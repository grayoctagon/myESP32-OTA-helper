#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <Update.h>
#include <SPIFFS.h>
#include "esp_ota_ops.h"

// =========================
// WLAN einstellen
// =========================
const char *WIFI_SSID = "DEIN_WLAN";
const char *WIFI_PASSWORD = "DEIN_PASSWORT";

// Optional: Wenn du start_polling ohne URL nutzt, trage hier deine Update URL ein.
// Ohne Schema wird automatisch http:// davor gesetzt.
const char *DEFAULT_UPDATE_URL = ""; // z.B. "example.com/firmware/esp32c3.bin"

const uint32_t SERIAL_BAUD = 115200;
const uint32_t HTTP_TIMEOUT_MS = 15000;
const size_t OTA_BUFFER_SIZE = 4096;

#if defined(__FILE_NAME__)
  #define OTA_SOURCE_FILE __FILE_NAME__
#else
  #define OTA_SOURCE_FILE __FILE__
#endif

// =========================
// Polling Status
// =========================
String otaPollUrl = DEFAULT_UPDATE_URL;
bool otaPollingEnabled = false;
bool otaPollDeleteFlash = false;
bool otaPollVerbose = true;
uint32_t otaPollIntervalSeconds = 0;
uint32_t otaLastPollMs = 0;

struct ParsedHttpUrl {
  String host;
  String path;
  uint16_t port = 80;
  bool ok = false;
};

// =========================
// Kleine Hilfsfunktionen
// =========================
const char *baseName(const char *path) {
  const char *name = path;
  for (const char *p = path; *p; ++p) {
    if (*p == '/' || *p == '\\') {
      name = p + 1;
    }
  }
  return name;
}

String urlSafeText(const String &input) {
  String out;
  out.reserve(input.length() + 8);

  for (size_t i = 0; i < input.length(); i++) {
    char c = input[i];
    bool safe = (c >= 'A' && c <= 'Z') ||
                (c >= 'a' && c <= 'z') ||
                (c >= '0' && c <= '9') ||
                c == '-' || c == '_' || c == '.' || c == '~';

    if (safe) {
      out += c;
    } else if (c == ' ' || c == ':' || c == '/' || c == '\\') {
      out += '-';
    } else {
      char buf[4];
      snprintf(buf, sizeof(buf), "%%%02X", (uint8_t)c);
      out += buf;
    }
  }

  while (out.indexOf("--") >= 0) {
    out.replace("--", "-");
  }
  return out;
}

String urlEncode(const String &input) {
  String out;
  out.reserve(input.length() + 8);

  for (size_t i = 0; i < input.length(); i++) {
    uint8_t c = (uint8_t)input[i];
    bool safe = (c >= 'A' && c <= 'Z') ||
                (c >= 'a' && c <= 'z') ||
                (c >= '0' && c <= '9') ||
                c == '-' || c == '_' || c == '.' || c == '~';

    if (safe) {
      out += (char)c;
    } else {
      char buf[4];
      snprintf(buf, sizeof(buf), "%%%02X", c);
      out += buf;
    }
  }
  return out;
}

String currentFirmwareVersion() {
  String date = __DATE__;   // z.B. "May 24 2026"
  String time = __TIME__;   // z.B. "14:37:12"
  String file = baseName(OTA_SOURCE_FILE);

  date.trim();
  while (date.indexOf("  ") >= 0) {
    date.replace("  ", " ");
  }
  date.replace(" ", "-");
  time.replace(":", "-");

  return urlSafeText(date + "_" + time + "_" + file);
}

String espMacHyphen() {
  String mac = WiFi.macAddress();
  mac.replace(":", "-");
  return mac;
}

String normalizeHttpUrl(String url) {
  url.trim();
  if (!url.startsWith("http://") && !url.startsWith("https://")) {
    url = "http://" + url;
  }
  return url;
}

String addUpdateQueryParams(String url) {
  int hash = url.indexOf('#');
  if (hash >= 0) {
    url = url.substring(0, hash);
  }

  String sep;
  if (url.indexOf('?') < 0) {
    sep = "?";
  } else if (url.endsWith("?") || url.endsWith("&")) {
    sep = "";
  } else {
    sep = "&";
  }

  url += sep;
  url += "macadress="; // Schreibweise absichtlich wie angefordert
  url += urlEncode(espMacHyphen());
  url += "&currentFirmware=";
  url += urlEncode(currentFirmwareVersion());
  return url;
}

ParsedHttpUrl parseHttpUrl(String url, String &error) {
  ParsedHttpUrl parsed;
  url = normalizeHttpUrl(url);

  if (url.startsWith("https://")) {
    error = "HTTPS ist in diesem Minimal-Build deaktiviert. Bitte http:// verwenden.";
    return parsed;
  }

  if (!url.startsWith("http://")) {
    error = "URL muss mit http:// beginnen oder ohne Schema angegeben werden.";
    return parsed;
  }

  String rest = url.substring(7);
  int slash = rest.indexOf('/');
  String authority;

  if (slash >= 0) {
    authority = rest.substring(0, slash);
    parsed.path = rest.substring(slash);
  } else {
    authority = rest;
    parsed.path = "/";
  }

  int at = authority.lastIndexOf('@');
  if (at >= 0) {
    authority = authority.substring(at + 1);
  }

  if (authority.length() == 0) {
    error = "Host fehlt in der URL.";
    return parsed;
  }

  if (authority[0] == '[') {
    int endBracket = authority.indexOf(']');
    if (endBracket < 0) {
      error = "Ungueltige IPv6 Host Schreibweise.";
      return parsed;
    }
    parsed.host = authority.substring(1, endBracket);
    if (authority.length() > (size_t)(endBracket + 1) && authority[endBracket + 1] == ':') {
      parsed.port = (uint16_t)authority.substring(endBracket + 2).toInt();
    }
  } else {
    int colon = authority.lastIndexOf(':');
    if (colon >= 0) {
      parsed.host = authority.substring(0, colon);
      parsed.port = (uint16_t)authority.substring(colon + 1).toInt();
    } else {
      parsed.host = authority;
      parsed.port = 80;
    }
  }

  parsed.host.trim();
  if (parsed.host.length() == 0) {
    error = "Host fehlt in der URL.";
    return parsed;
  }
  if (parsed.port == 0) {
    error = "Port ist ungueltig.";
    return parsed;
  }
  if (parsed.path.length() == 0) {
    parsed.path = "/";
  }

  parsed.ok = true;
  return parsed;
}

String readHttpLine(WiFiClient &client, uint32_t timeoutMs, bool &timedOut) {
  String line;
  uint32_t start = millis();
  timedOut = false;

  while (millis() - start < timeoutMs) {
    while (client.available()) {
      char c = (char)client.read();
      if (c == '\r') {
        continue;
      }
      if (c == '\n') {
        return line;
      }
      line += c;
      if (line.length() > 1024) {
        return line;
      }
    }
    if (!client.connected()) {
      return line;
    }
    delay(1);
  }

  timedOut = true;
  return line;
}

bool isOctetStream(String contentType) {
  contentType.toLowerCase();
  int semicolon = contentType.indexOf(';');
  if (semicolon >= 0) {
    contentType = contentType.substring(0, semicolon);
  }
  contentType.trim();
  return contentType == "application/octet-stream";
}

void printVersion() {
  Serial.println();
  Serial.println("ESP32-C3 OTA Sketch");
  Serial.print("Firmware: ");
  Serial.println(currentFirmwareVersion());
  Serial.print("Build file: ");
  Serial.println(baseName(OTA_SOURCE_FILE));
  Serial.print("MAC: ");
  Serial.println(espMacHyphen());
  Serial.print("Free sketch space: ");
  Serial.print(ESP.getFreeSketchSpace() / 1024);
  Serial.println(" KB");

  const esp_partition_t *next = esp_ota_get_next_update_partition(nullptr);
  if (next) {
    Serial.print("Next OTA partition: ");
    Serial.print(next->label);
    Serial.print(", size: ");
    Serial.print(next->size / 1024);
    Serial.println(" KB");
  }
  Serial.println();
}

void printEta(uint32_t seconds) {
  uint32_t h = seconds / 3600;
  uint32_t m = (seconds % 3600) / 60;
  uint32_t s = seconds % 60;
  if (h > 0) {
    Serial.printf("%luh %lum %lus", (unsigned long)h, (unsigned long)m, (unsigned long)s);
  } else if (m > 0) {
    Serial.printf("%lum %lus", (unsigned long)m, (unsigned long)s);
  } else {
    Serial.printf("%lus", (unsigned long)s);
  }
}

void otaFail(WiFiClient *client, const String &msg, bool verbose) {
  if (verbose) {
    Serial.print("OTA Fehler: ");
    Serial.println(msg);
  }
  Update.abort();
  if (client) {
    client->stop();
  }
}

// =========================
// HTTP-only OTA Kernfunktion
// =========================
bool loadUpdate(String url = DEFAULT_UPDATE_URL, bool deleteFlash = false, bool verbose = true) {
  url = normalizeHttpUrl(url);

  if (url == "http://" || url.length() < 10) {
    if (verbose) {
      Serial.println("OTA Fehler: Keine Update URL angegeben.");
    }
    return false;
  }

  if (WiFi.status() != WL_CONNECTED) {
    if (verbose) {
      Serial.println("OTA Fehler: WLAN ist nicht verbunden.");
    }
    return false;
  }

  String fullUrl = addUpdateQueryParams(url);
  String parseError;
  ParsedHttpUrl parsed = parseHttpUrl(fullUrl, parseError);
  if (!parsed.ok) {
    if (verbose) {
      Serial.print("OTA Fehler: ");
      Serial.println(parseError);
    }
    return false;
  }

  WiFiClient client;
  client.setTimeout(HTTP_TIMEOUT_MS);

  if (verbose) {
    Serial.print("OTA URL: ");
    Serial.println(fullUrl);
    Serial.print("Verbinde mit ");
    Serial.print(parsed.host);
    Serial.print(":");
    Serial.println(parsed.port);
  }

  if (!client.connect(parsed.host.c_str(), parsed.port)) {
    otaFail(&client, "TCP Verbindung fehlgeschlagen.", verbose);
    return false;
  }

  client.print(String("GET ") + parsed.path + " HTTP/1.1\r\n");
  client.print(String("Host: ") + parsed.host + "\r\n");
  client.print("User-Agent: ESP32C3-OTA/1.0\r\n");
  client.print("Accept: application/octet-stream\r\n");
  client.print("Cache-Control: no-cache\r\n");
  client.print("Connection: close\r\n");
  client.print("\r\n");

  bool timedOut = false;
  String statusLine = readHttpLine(client, HTTP_TIMEOUT_MS, timedOut);
  if (timedOut || statusLine.length() == 0) {
    otaFail(&client, "Keine HTTP Statuszeile erhalten.", verbose);
    return false;
  }

  int firstSpace = statusLine.indexOf(' ');
  int secondSpace = statusLine.indexOf(' ', firstSpace + 1);
  int httpCode = 0;
  if (firstSpace >= 0) {
    String codeText = secondSpace > firstSpace ? statusLine.substring(firstSpace + 1, secondSpace) : statusLine.substring(firstSpace + 1);
    httpCode = codeText.toInt();
  }

  if (httpCode != 200) {
    otaFail(&client, "HTTP Status ist nicht 200. Statuszeile: " + statusLine, verbose);
    return false;
  }

  String contentType;
  int contentLength = -1;

  while (true) {
    String line = readHttpLine(client, HTTP_TIMEOUT_MS, timedOut);
    if (timedOut) {
      otaFail(&client, "Timeout beim Lesen der HTTP Header.", verbose);
      return false;
    }

    if (line.length() == 0) {
      break;
    }

    int colon = line.indexOf(':');
    if (colon <= 0) {
      continue;
    }

    String name = line.substring(0, colon);
    String value = line.substring(colon + 1);
    name.trim();
    value.trim();
    name.toLowerCase();

    if (name == "content-type") {
      contentType = value;
    } else if (name == "content-length") {
      contentLength = value.toInt();
    }
  }

  if (!isOctetStream(contentType)) {
    otaFail(&client, "Content-Type ist nicht application/octet-stream, sondern '" + contentType + "'", verbose);
    return false;
  }

  if (contentLength <= 0) {
    otaFail(&client, "Content-Length fehlt oder ist ungueltig.", verbose);
    return false;
  }

  const esp_partition_t *next = esp_ota_get_next_update_partition(nullptr);
  size_t nextPartitionSize = next ? next->size : 0;
  size_t freeSketchSpace = ESP.getFreeSketchSpace();
  size_t maxAllowed = nextPartitionSize > 0 ? nextPartitionSize : freeSketchSpace;

  if (maxAllowed > 0 && (size_t)contentLength > maxAllowed) {
    String msg = "Firmware ist zu gross: ";
    msg += String(contentLength / 1024);
    msg += " KB, verfuegbarer OTA App Speicher: ";
    msg += String(maxAllowed / 1024);
    msg += " KB";
    otaFail(&client, msg, verbose);
    return false;
  }

  if (verbose) {
    Serial.print("HTTP Status: ");
    Serial.println(httpCode);
    Serial.print("Content-Type: ");
    Serial.println(contentType);
    Serial.print("Firmware Groesse: ");
    Serial.print(contentLength / 1024);
    Serial.println(" KB");
    if (next) {
      Serial.print("Zielpartition: ");
      Serial.print(next->label);
      Serial.print(" (");
      Serial.print(next->size / 1024);
      Serial.println(" KB)");
    }
  }

  if (!Update.begin((size_t)contentLength, U_FLASH)) {
    otaFail(&client, "Update.begin fehlgeschlagen: " + String(Update.errorString()), verbose);
    return false;
  }

  uint8_t buffer[OTA_BUFFER_SIZE];
  size_t written = 0;
  size_t lastWritten = 0;
  uint32_t startMs = millis();
  uint32_t lastReportMs = startMs;
  uint32_t lastDataMs = startMs;

  if (verbose) {
    Serial.println("Download startet...");
  }

  while (written < (size_t)contentLength) {
    int available = client.available();

    if (available <= 0) {
      if (!client.connected()) {
        otaFail(&client, "Verbindung wurde vorzeitig geschlossen.", verbose);
        return false;
      }
      if (millis() - lastDataMs > HTTP_TIMEOUT_MS) {
        otaFail(&client, "Timeout beim Firmware Download.", verbose);
        return false;
      }
      delay(1);
      continue;
    }

    size_t remaining = (size_t)contentLength - written;
    size_t toRead = available;
    if (toRead > OTA_BUFFER_SIZE) {
      toRead = OTA_BUFFER_SIZE;
    }
    if (toRead > remaining) {
      toRead = remaining;
    }

    int readBytes = client.readBytes(buffer, toRead);
    if (readBytes <= 0) {
      otaFail(&client, "Socket readBytes lieferte 0 Bytes.", verbose);
      return false;
    }

    lastDataMs = millis();

    size_t updateWritten = Update.write(buffer, (size_t)readBytes);
    if (updateWritten != (size_t)readBytes) {
      otaFail(&client, "Update.write fehlgeschlagen: " + String(Update.errorString()), verbose);
      return false;
    }

    written += updateWritten;

    uint32_t now = millis();
    if (verbose && (now - lastReportMs >= 1000 || written == (size_t)contentLength)) {
      float elapsedSec = (now - startMs) / 1000.0f;
      float intervalSec = (now - lastReportMs) / 1000.0f;
      if (elapsedSec <= 0.0f) elapsedSec = 0.001f;
      if (intervalSec <= 0.0f) intervalSec = 0.001f;

      float avgKBs = (written / 1024.0f) / elapsedSec;
      float nowKBs = ((written - lastWritten) / 1024.0f) / intervalSec;
      uint32_t etaSec = 0;
      if (avgKBs > 0.01f) {
        etaSec = (uint32_t)(((contentLength - written) / 1024.0f) / avgKBs);
      }

      float percent = (100.0f * written) / contentLength;
      Serial.printf("OTA %.1f%%, %lu/%lu KB, %.1f KB/s aktuell, %.1f KB/s Schnitt, ETA ",
                    percent,
                    (unsigned long)(written / 1024),
                    (unsigned long)(contentLength / 1024),
                    nowKBs,
                    avgKBs);
      printEta(etaSec);
      Serial.println();

      lastReportMs = now;
      lastWritten = written;
    }

    delay(1);
  }

  if (written != (size_t)contentLength) {
    otaFail(&client, "Download unvollstaendig: " + String(written) + " von " + String(contentLength) + " Bytes.", verbose);
    return false;
  }

  if (!Update.end(true)) {
    otaFail(&client, "Update.end fehlgeschlagen: " + String(Update.errorString()), verbose);
    return false;
  }

  if (!Update.isFinished()) {
    otaFail(&client, "Update ist nicht vollstaendig.", verbose);
    return false;
  }

  client.stop();

  if (deleteFlash) {
    if (verbose) {
      Serial.println("Formatiere SPIFFS...");
    }
    if (SPIFFS.begin(true)) {
      bool ok = SPIFFS.format();
      SPIFFS.end();
      if (verbose) {
        Serial.println(ok ? "SPIFFS wurde geloescht." : "SPIFFS format fehlgeschlagen.");
      }
    } else if (verbose) {
      Serial.println("SPIFFS.begin fehlgeschlagen, SPIFFS wurde nicht geloescht.");
    }
  }

  if (verbose) {
    Serial.println("OTA erfolgreich. Neustart...");
    Serial.flush();
  }

  delay(500);
  ESP.restart();
  return true;
}

// =========================
// Polling API
// =========================
void start_polling(uint32_t seconds, String url = DEFAULT_UPDATE_URL, bool deleteFlash = false, bool verbose = true) {
  if (seconds == 0) {
    otaPollingEnabled = false;
    if (verbose) {
      Serial.println("Polling deaktiviert, weil Intervall 0 ist.");
    }
    return;
  }

  otaPollUrl = normalizeHttpUrl(url);
  otaPollIntervalSeconds = seconds;
  otaPollDeleteFlash = deleteFlash;
  otaPollVerbose = verbose;
  otaLastPollMs = millis() - (seconds * 1000UL); // erster Check sofort
  otaPollingEnabled = true;

  if (verbose) {
    Serial.print("Polling aktiviert: alle ");
    Serial.print(seconds);
    Serial.print(" Sekunden, URL: ");
    Serial.println(otaPollUrl);
  }
}

void stop_polling(bool verbose = true) {
  otaPollingEnabled = false;
  if (verbose) {
    Serial.println("Polling deaktiviert.");
  }
}

void handleOtaPolling() {
  if (!otaPollingEnabled || otaPollIntervalSeconds == 0) {
    return;
  }

  uint32_t now = millis();
  uint32_t intervalMs = otaPollIntervalSeconds * 1000UL;
  if ((uint32_t)(now - otaLastPollMs) >= intervalMs) {
    otaLastPollMs = now;
    loadUpdate(otaPollUrl, otaPollDeleteFlash, otaPollVerbose);
  }
}

// =========================
// Serial Helper
// =========================
String serialLine;

String argAt(const String &line, int index) {
  int current = 0;
  int start = -1;

  for (int i = 0; i <= (int)line.length(); i++) {
    bool isSep = i == (int)line.length() || line[i] == ' ' || line[i] == '\t';
    if (!isSep && start < 0) {
      start = i;
    }
    if (isSep && start >= 0) {
      if (current == index) {
        return line.substring(start, i);
      }
      current++;
      start = -1;
    }
  }
  return "";
}

bool argBool(const String &value, bool fallback) {
  if (value.length() == 0) return fallback;
  String v = value;
  v.toLowerCase();
  if (v == "1" || v == "true" || v == "yes" || v == "ja" || v == "on") return true;
  if (v == "0" || v == "false" || v == "no" || v == "nein" || v == "off") return false;
  return fallback;
}

void printSerialHelp() {
  Serial.println("Befehle:");
  Serial.println("  version");
  Serial.println("  update <url> [deleteFlash 0|1] [verbose 0|1]");
  Serial.println("  loadUpdate <url> [deleteFlash 0|1] [verbose 0|1]");
  Serial.println("  start_polling <sekunden> [url] [deleteFlash 0|1] [verbose 0|1]");
  Serial.println("  stop_polling");
  Serial.println("  help");
  Serial.println();
}

void handleSerialCommand(String line) {
  line.trim();
  if (line.length() == 0) return;

  String cmd = argAt(line, 0);
  cmd.trim();
  cmd.toLowerCase();

  if (cmd == "help" || cmd == "?") {
    printSerialHelp();
    return;
  }

  if (cmd == "version") {
    printVersion();
    return;
  }

  if (cmd == "update" || cmd == "loadupdate") {
    String url = argAt(line, 1);
    bool deleteFlash = argBool(argAt(line, 2), false);
    bool verbose = argBool(argAt(line, 3), true);
    loadUpdate(url, deleteFlash, verbose);
    return;
  }

  if (cmd == "start_polling") {
    uint32_t seconds = (uint32_t)argAt(line, 1).toInt();
    String url = argAt(line, 2);
    if (url.length() == 0) {
      url = DEFAULT_UPDATE_URL;
    }
    bool deleteFlash = argBool(argAt(line, 3), false);
    bool verbose = argBool(argAt(line, 4), true);
    start_polling(seconds, url, deleteFlash, verbose);
    return;
  }

  if (cmd == "stop_polling") {
    stop_polling(true);
    return;
  }

  Serial.print("Unbekannter Befehl: ");
  Serial.println(cmd);
  printSerialHelp();
}

// Nicht blockierend, einfach in loop() aufrufen.
void otaSerialHelper() {
  while (Serial.available()) {
    char c = (char)Serial.read();
    if (c == '\r') continue;

    if (c == '\n') {
      handleSerialCommand(serialLine);
      serialLine = "";
    } else {
      serialLine += c;
      if (serialLine.length() > 512) {
        serialLine = "";
        Serial.println("Serial Eingabe zu lang, verworfen.");
      }
    }
  }
}

// Optional blockierend, wenn du an einer Stelle explizit auf Eingabe warten willst.
void waitForSerialOtaCommand() {
  while (true) {
    otaSerialHelper();
    handleOtaPolling();
    delay(10);
  }
}

// =========================
// Arduino Setup und Loop
// =========================
void setup() {
  Serial.begin(SERIAL_BAUD);
  delay(600);

  Serial.println();
  Serial.println("Boot...");

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  Serial.print("Verbinde WLAN");
  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 20000) {
    Serial.print(".");
    delay(500);
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("WLAN verbunden, IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("WLAN nicht verbunden. OTA wird erst funktionieren, wenn WLAN verbunden ist.");
  }

  printVersion();
  printSerialHelp();

  // Beispiel: automatisches Polling beim Boot aktivieren
  // start_polling(300, DEFAULT_UPDATE_URL, false, true);
}

void loop() {
  otaSerialHelper();
  handleOtaPolling();

  // Hier kommt dein normaler Programmcode hin.
  delay(10);
}
