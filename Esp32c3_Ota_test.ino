#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <Update.h>
#include "esp_ota_ops.h"
#include "esp_partition.h"

// =========================
// WLAN einstellen
// =========================
const char *WIFI_SSID = "DEIN_WLAN";
const char *WIFI_PASSWORD = "DEIN_PASSWORT";

// Optional: Wenn du start_polling ohne URL nutzt, trage hier deine Update URL ein.
// Ohne Schema wird automatisch http:// verwendet.
const char *DEFAULT_UPDATE_URL = ""; // z.B. "example.com/firmware/esp32c3.bin"

const uint32_t SERIAL_BAUD = 115200;
const uint32_t HTTP_TIMEOUT_MS = 15000;
const size_t OTA_BUFFER_SIZE = 4096;

const size_t HOST_MAX = 96;
const size_t PATH_MAX_LEN = 512;
const size_t REQUEST_TARGET_MAX = 768;
const size_t FW_VERSION_MAX = 192;
const size_t SERIAL_LINE_MAX = 512;

#if defined(__FILE_NAME__)
  #define OTA_SOURCE_FILE __FILE_NAME__
#else
  #define OTA_SOURCE_FILE __FILE__
#endif

struct ParsedHttpUrl {
  char host[HOST_MAX];
  char path[PATH_MAX_LEN];
  uint16_t port;
};

// =========================
// Polling Status
// =========================
char otaPollUrl[REQUEST_TARGET_MAX] = "";
bool otaPollingEnabled = false;
bool otaPollDeleteFlash = false;
bool otaPollVerbose = true;
uint32_t otaPollIntervalSeconds = 0;
uint32_t otaLastPollMs = 0;

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

bool startsWithLiteral(const char *text, const char *prefix) {
  while (*prefix) {
    if (*text++ != *prefix++) return false;
  }
  return true;
}

char *trimInPlace(char *s) {
  while (*s == ' ' || *s == '\t') s++;
  char *end = s + strlen(s);
  while (end > s && (end[-1] == ' ' || end[-1] == '\t' || end[-1] == '\r' || end[-1] == '\n')) {
    *--end = '\0';
  }
  return s;
}

bool equalsIgnoreCase(const char *a, const char *b) {
  while (*a && *b) {
    char ca = *a++;
    char cb = *b++;
    if (ca >= 'A' && ca <= 'Z') ca += 32;
    if (cb >= 'A' && cb <= 'Z') cb += 32;
    if (ca != cb) return false;
  }
  return *a == '\0' && *b == '\0';
}

bool tokenEqualsIgnoreCase(const char *token, size_t tokenLen, const char *expected) {
  for (size_t i = 0; i < tokenLen; i++) {
    if (expected[i] == '\0') return false;
    char ca = token[i];
    char cb = expected[i];
    if (ca >= 'A' && ca <= 'Z') ca += 32;
    if (cb >= 'A' && cb <= 'Z') cb += 32;
    if (ca != cb) return false;
  }
  return expected[tokenLen] == '\0';
}

void copySafe(char *dst, size_t dstLen, const char *src) {
  if (!dst || dstLen == 0) return;
  if (!src) src = "";
  strncpy(dst, src, dstLen - 1);
  dst[dstLen - 1] = '\0';
}

void makeUrlSafeDash(const char *in, char *out, size_t outLen) {
  if (!out || outLen == 0) return;
  size_t pos = 0;
  bool lastDash = false;

  for (size_t i = 0; in && in[i] && pos + 1 < outLen; i++) {
    char c = in[i];
    bool safe = (c >= 'A' && c <= 'Z') ||
                (c >= 'a' && c <= 'z') ||
                (c >= '0' && c <= '9') ||
                c == '_' || c == '.' || c == '~';

    if (safe) {
      out[pos++] = c;
      lastDash = false;
    } else if (c == '-' || c == ' ' || c == ':' || c == '/' || c == '\\') {
      if (!lastDash && pos > 0) {
        out[pos++] = '-';
        lastDash = true;
      }
    } else {
      if (!lastDash && pos > 0) {
        out[pos++] = '-';
        lastDash = true;
      }
    }
  }

  if (pos > 0 && out[pos - 1] == '-') pos--;
  out[pos] = '\0';
}

void getCurrentFirmwareVersion(char *out, size_t outLen) {
  char raw[FW_VERSION_MAX];
  snprintf(raw, sizeof(raw), "%s_%s_%s", __DATE__, __TIME__, baseName(OTA_SOURCE_FILE));
  makeUrlSafeDash(raw, out, outLen);
}

void getMacHyphen(char *out, size_t outLen) {
  uint8_t mac[6];
  WiFi.macAddress(mac);
  snprintf(out, outLen, "%02X-%02X-%02X-%02X-%02X-%02X",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
}

bool parseHttpUrl(const char *url, ParsedHttpUrl &parsed, char *error, size_t errorLen) {
  memset(&parsed, 0, sizeof(parsed));
  parsed.port = 80;

  if (!url || !*url) {
    copySafe(error, errorLen, "Keine URL angegeben.");
    return false;
  }

  while (*url == ' ' || *url == '\t') url++;

  if (startsWithLiteral(url, "https://")) {
    copySafe(error, errorLen, "HTTPS ist in diesem Minimal-Build deaktiviert. Bitte http:// verwenden.");
    return false;
  }

  if (startsWithLiteral(url, "http://")) {
    url += 7;
  }

  if (!*url) {
    copySafe(error, errorLen, "Host fehlt in der URL.");
    return false;
  }

  const char *hostStart = url;
  const char *p = hostStart;
  while (*p && *p != '/' && *p != '?' && *p != '#') p++;

  const char *hostEnd = p;
  const char *colon = nullptr;
  for (const char *h = hostStart; h < hostEnd; h++) {
    if (*h == ':') colon = h;
  }

  size_t hostLen = colon ? (size_t)(colon - hostStart) : (size_t)(hostEnd - hostStart);
  if (hostLen == 0 || hostLen >= HOST_MAX) {
    copySafe(error, errorLen, "Host fehlt oder ist zu lang.");
    return false;
  }

  memcpy(parsed.host, hostStart, hostLen);
  parsed.host[hostLen] = '\0';

  if (colon) {
    uint32_t port = 0;
    for (const char *d = colon + 1; d < hostEnd; d++) {
      if (*d < '0' || *d > '9') {
        copySafe(error, errorLen, "Port ist ungueltig.");
        return false;
      }
      port = port * 10 + (uint32_t)(*d - '0');
      if (port > 65535) {
        copySafe(error, errorLen, "Port ist zu gross.");
        return false;
      }
    }
    if (port == 0) {
      copySafe(error, errorLen, "Port ist ungueltig.");
      return false;
    }
    parsed.port = (uint16_t)port;
  }

  if (*p == '/') {
    const char *pathStart = p;
    while (*p && *p != '#') p++;
    size_t pathLen = (size_t)(p - pathStart);
    if (pathLen == 0 || pathLen >= PATH_MAX_LEN) {
      copySafe(error, errorLen, "Pfad ist zu lang.");
      return false;
    }
    memcpy(parsed.path, pathStart, pathLen);
    parsed.path[pathLen] = '\0';
  } else if (*p == '?') {
    if (strlen(p) + 2 >= PATH_MAX_LEN) {
      copySafe(error, errorLen, "Query ist zu lang.");
      return false;
    }
    parsed.path[0] = '/';
    size_t i = 1;
    while (*p && *p != '#' && i + 1 < PATH_MAX_LEN) {
      parsed.path[i++] = *p++;
    }
    parsed.path[i] = '\0';
  } else {
    copySafe(parsed.path, sizeof(parsed.path), "/");
  }

  return true;
}

bool buildRequestTarget(const ParsedHttpUrl &parsed, char *target, size_t targetLen) {
  char mac[24];
  char fw[FW_VERSION_MAX];
  getMacHyphen(mac, sizeof(mac));
  getCurrentFirmwareVersion(fw, sizeof(fw));

  char sep = '?';
  size_t pathLen = strlen(parsed.path);
  if (strchr(parsed.path, '?')) {
    sep = (pathLen > 0 && (parsed.path[pathLen - 1] == '?' || parsed.path[pathLen - 1] == '&')) ? '\0' : '&';
  }

  int n;
  if (sep == '\0') {
    n = snprintf(target, targetLen, "%smacadress=%s&currentFirmware=%s", parsed.path, mac, fw);
  } else {
    n = snprintf(target, targetLen, "%s%cmacadress=%s&currentFirmware=%s", parsed.path, sep, mac, fw);
  }

  return n > 0 && (size_t)n < targetLen;
}

bool readHttpLine(WiFiClient &client, char *line, size_t lineLen, uint32_t timeoutMs) {
  if (!line || lineLen == 0) return false;
  size_t pos = 0;
  uint32_t start = millis();

  while (millis() - start < timeoutMs) {
    while (client.available()) {
      char c = (char)client.read();
      if (c == '\r') continue;
      if (c == '\n') {
        line[pos] = '\0';
        return true;
      }
      if (pos + 1 < lineLen) {
        line[pos++] = c;
      }
    }

    if (!client.connected()) {
      line[pos] = '\0';
      return pos > 0;
    }
    delay(1);
  }

  line[pos] = '\0';
  return false;
}

bool isOctetStream(const char *contentType) {
  if (!contentType) return false;
  while (*contentType == ' ' || *contentType == '\t') contentType++;

  size_t len = 0;
  while (contentType[len] && contentType[len] != ';') len++;
  while (len > 0 && (contentType[len - 1] == ' ' || contentType[len - 1] == '\t')) len--;

  return tokenEqualsIgnoreCase(contentType, len, "application/octet-stream");
}

void printVersion() {
  char fw[FW_VERSION_MAX];
  char mac[24];
  getCurrentFirmwareVersion(fw, sizeof(fw));
  getMacHyphen(mac, sizeof(mac));

  Serial.println();
  Serial.println("ESP32-C3 OTA Sketch");
  Serial.print("Firmware: ");
  Serial.println(fw);
  Serial.print("Build file: ");
  Serial.println(baseName(OTA_SOURCE_FILE));
  Serial.print("MAC: ");
  Serial.println(mac);
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

void otaFail(WiFiClient *client, const char *msg, bool verbose) {
  if (verbose) {
    Serial.print("OTA Fehler: ");
    Serial.println(msg ? msg : "Unbekannter Fehler.");
  }
  Update.abort();
  if (client) client->stop();
}

void otaFailNum(WiFiClient *client, const char *msg, int value, bool verbose) {
  if (verbose) {
    Serial.print("OTA Fehler: ");
    Serial.print(msg);
    Serial.println(value);
  }
  Update.abort();
  if (client) client->stop();
}

bool eraseSpiffsPartition(bool verbose) {
  const esp_partition_t *p = esp_partition_find_first(
    ESP_PARTITION_TYPE_DATA,
    ESP_PARTITION_SUBTYPE_DATA_SPIFFS,
    nullptr
  );

  if (!p) {
    if (verbose) Serial.println("SPIFFS Partition nicht gefunden.");
    return false;
  }

  if (verbose) {
    Serial.print("Loesche SPIFFS Partition: ");
    Serial.print(p->label);
    Serial.print(", Groesse: ");
    Serial.print(p->size / 1024);
    Serial.println(" KB");
  }

  esp_err_t err = esp_partition_erase_range(p, 0, p->size);
  if (verbose) {
    Serial.println(err == ESP_OK ? "SPIFFS Partition wurde geloescht." : "SPIFFS Partition konnte nicht geloescht werden.");
  }
  return err == ESP_OK;
}

// =========================
// HTTP-only OTA Kernfunktion
// =========================
bool loadUpdate(const char *url = DEFAULT_UPDATE_URL, bool deleteFlash = false, bool verbose = true) {
  if (!url || !*url) url = DEFAULT_UPDATE_URL;

  if (!url || !*url) {
    if (verbose) Serial.println("OTA Fehler: Keine Update URL angegeben.");
    return false;
  }

  if (WiFi.status() != WL_CONNECTED) {
    if (verbose) Serial.println("OTA Fehler: WLAN ist nicht verbunden.");
    return false;
  }

  ParsedHttpUrl parsed;
  char error[120];
  if (!parseHttpUrl(url, parsed, error, sizeof(error))) {
    if (verbose) {
      Serial.print("OTA Fehler: ");
      Serial.println(error);
    }
    return false;
  }

  char requestTarget[REQUEST_TARGET_MAX];
  if (!buildRequestTarget(parsed, requestTarget, sizeof(requestTarget))) {
    if (verbose) Serial.println("OTA Fehler: Request URL ist zu lang.");
    return false;
  }

  WiFiClient client;
  client.setTimeout(HTTP_TIMEOUT_MS);

  if (verbose) {
    Serial.print("OTA Host: ");
    Serial.print(parsed.host);
    Serial.print(":");
    Serial.println(parsed.port);
    Serial.print("OTA Pfad: ");
    Serial.println(requestTarget);
  }

  if (!client.connect(parsed.host, parsed.port)) {
    otaFail(&client, "TCP Verbindung fehlgeschlagen.", verbose);
    return false;
  }

  client.print("GET ");
  client.print(requestTarget);
  client.print(" HTTP/1.1\r\nHost: ");
  client.print(parsed.host);
  client.print("\r\nUser-Agent: ESP32C3-OTA/1.0\r\nAccept: application/octet-stream\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n");

  char line[384];
  if (!readHttpLine(client, line, sizeof(line), HTTP_TIMEOUT_MS)) {
    otaFail(&client, "Keine HTTP Statuszeile erhalten.", verbose);
    return false;
  }

  int httpCode = 0;
  char *firstSpace = strchr(line, ' ');
  if (firstSpace) httpCode = atoi(firstSpace + 1);

  if (httpCode != 200) {
    if (verbose) {
      Serial.print("HTTP Statuszeile: ");
      Serial.println(line);
    }
    otaFailNum(&client, "HTTP Status ist nicht 200, sondern ", httpCode, verbose);
    return false;
  }

  char contentType[96] = "";
  int contentLength = -1;

  while (true) {
    if (!readHttpLine(client, line, sizeof(line), HTTP_TIMEOUT_MS)) {
      otaFail(&client, "Timeout beim Lesen der HTTP Header.", verbose);
      return false;
    }

    if (line[0] == '\0') break;

    char *colon = strchr(line, ':');
    if (!colon) continue;
    *colon = '\0';
    char *name = trimInPlace(line);
    char *value = trimInPlace(colon + 1);

    if (equalsIgnoreCase(name, "Content-Type")) {
      copySafe(contentType, sizeof(contentType), value);
    } else if (equalsIgnoreCase(name, "Content-Length")) {
      contentLength = atoi(value);
    }
  }

  if (!isOctetStream(contentType)) {
    if (verbose) {
      Serial.print("Content-Type erhalten: '");
      Serial.print(contentType);
      Serial.println("'");
    }
    otaFail(&client, "Content-Type ist nicht application/octet-stream.", verbose);
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
    if (verbose) {
      Serial.print("OTA Fehler: Firmware ist zu gross: ");
      Serial.print((unsigned long)(contentLength / 1024));
      Serial.print(" KB, verfuegbarer OTA App Speicher: ");
      Serial.print((unsigned long)(maxAllowed / 1024));
      Serial.println(" KB");
    }
    client.stop();
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
    if (verbose) {
      Serial.print("OTA Fehler: Update.begin fehlgeschlagen: ");
      Serial.println(Update.errorString());
    }
    client.stop();
    return false;
  }

  uint8_t buffer[OTA_BUFFER_SIZE];
  size_t written = 0;
  size_t lastWritten = 0;
  uint32_t startMs = millis();
  uint32_t lastReportMs = startMs;
  uint32_t lastDataMs = startMs;

  if (verbose) Serial.println("Download startet...");

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
    size_t toRead = (size_t)available;
    if (toRead > OTA_BUFFER_SIZE) toRead = OTA_BUFFER_SIZE;
    if (toRead > remaining) toRead = remaining;

    int readBytes = client.readBytes(buffer, toRead);
    if (readBytes <= 0) {
      otaFail(&client, "Socket readBytes lieferte 0 Bytes.", verbose);
      return false;
    }

    lastDataMs = millis();

    size_t updateWritten = Update.write(buffer, (size_t)readBytes);
    if (updateWritten != (size_t)readBytes) {
      if (verbose) {
        Serial.print("OTA Fehler: Update.write fehlgeschlagen: ");
        Serial.println(Update.errorString());
      }
      Update.abort();
      client.stop();
      return false;
    }

    written += updateWritten;

    uint32_t now = millis();
    if (verbose && (now - lastReportMs >= 1000 || written == (size_t)contentLength)) {
      uint32_t elapsedMs = now - startMs;
      uint32_t intervalMs = now - lastReportMs;
      if (elapsedMs == 0) elapsedMs = 1;
      if (intervalMs == 0) intervalMs = 1;

      uint32_t percent = (uint32_t)(((uint64_t)written * 100ULL) / (uint64_t)contentLength);
      uint32_t nowKBs = (uint32_t)((((uint64_t)(written - lastWritten)) * 1000ULL) / intervalMs / 1024ULL);
      uint32_t avgKBs = (uint32_t)((((uint64_t)written) * 1000ULL) / elapsedMs / 1024ULL);
      uint32_t avgBps = (uint32_t)((((uint64_t)written) * 1000ULL) / elapsedMs);
      uint32_t etaSec = 0;
      if (avgBps > 0) {
        etaSec = (uint32_t)((((uint64_t)contentLength - written) + avgBps - 1) / avgBps);
      }

      Serial.print("OTA ");
      Serial.print(percent);
      Serial.print("%, ");
      Serial.print((unsigned long)(written / 1024));
      Serial.print("/");
      Serial.print((unsigned long)(contentLength / 1024));
      Serial.print(" KB, ");
      Serial.print(nowKBs);
      Serial.print(" KB/s aktuell, ");
      Serial.print(avgKBs);
      Serial.print(" KB/s Schnitt, ETA ");
      printEta(etaSec);
      Serial.println();

      lastReportMs = now;
      lastWritten = written;
    }

    delay(1);
  }

  if (written != (size_t)contentLength) {
    otaFail(&client, "Download unvollstaendig.", verbose);
    return false;
  }

  if (!Update.end(true)) {
    if (verbose) {
      Serial.print("OTA Fehler: Update.end fehlgeschlagen: ");
      Serial.println(Update.errorString());
    }
    Update.abort();
    client.stop();
    return false;
  }

  if (!Update.isFinished()) {
    otaFail(&client, "Update ist nicht vollstaendig.", verbose);
    return false;
  }

  client.stop();

  if (deleteFlash) {
    eraseSpiffsPartition(verbose);
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
void start_polling(uint32_t seconds, const char *url = DEFAULT_UPDATE_URL, bool deleteFlash = false, bool verbose = true) {
  if (seconds == 0) {
    otaPollingEnabled = false;
    if (verbose) Serial.println("Polling deaktiviert, weil Intervall 0 ist.");
    return;
  }

  if (!url || !*url) url = DEFAULT_UPDATE_URL;
  copySafe(otaPollUrl, sizeof(otaPollUrl), url);
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
  if (verbose) Serial.println("Polling deaktiviert.");
}

void handleOtaPolling() {
  if (!otaPollingEnabled || otaPollIntervalSeconds == 0) return;

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
char serialLine[SERIAL_LINE_MAX];
size_t serialLineLen = 0;

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

bool argBool(const char *value, bool fallback) {
  if (!value || !*value) return fallback;
  if (equalsIgnoreCase(value, "1") || equalsIgnoreCase(value, "true") || equalsIgnoreCase(value, "yes") || equalsIgnoreCase(value, "ja") || equalsIgnoreCase(value, "on")) return true;
  if (equalsIgnoreCase(value, "0") || equalsIgnoreCase(value, "false") || equalsIgnoreCase(value, "no") || equalsIgnoreCase(value, "nein") || equalsIgnoreCase(value, "off")) return false;
  return fallback;
}

int splitArgs(char *line, char **argv, int maxArgs) {
  int argc = 0;
  char *p = line;

  while (*p && argc < maxArgs) {
    while (*p == ' ' || *p == '\t') p++;
    if (!*p) break;
    argv[argc++] = p;
    while (*p && *p != ' ' && *p != '\t') p++;
    if (*p) {
      *p = '\0';
      p++;
    }
  }
  return argc;
}

void handleSerialCommand(char *line) {
  char *argv[6];
  int argc = splitArgs(line, argv, 6);
  if (argc <= 0) return;

  const char *cmd = argv[0];

  if (equalsIgnoreCase(cmd, "help") || equalsIgnoreCase(cmd, "?")) {
    printSerialHelp();
    return;
  }

  if (equalsIgnoreCase(cmd, "version")) {
    printVersion();
    return;
  }

  if (equalsIgnoreCase(cmd, "update") || equalsIgnoreCase(cmd, "loadUpdate")) {
    const char *url = argc > 1 ? argv[1] : DEFAULT_UPDATE_URL;
    bool deleteFlash = argc > 2 ? argBool(argv[2], false) : false;
    bool verbose = argc > 3 ? argBool(argv[3], true) : true;
    loadUpdate(url, deleteFlash, verbose);
    return;
  }

  if (equalsIgnoreCase(cmd, "start_polling")) {
    uint32_t seconds = argc > 1 ? (uint32_t)strtoul(argv[1], nullptr, 10) : 0;
    const char *url = argc > 2 ? argv[2] : DEFAULT_UPDATE_URL;
    bool deleteFlash = argc > 3 ? argBool(argv[3], false) : false;
    bool verbose = argc > 4 ? argBool(argv[4], true) : true;
    start_polling(seconds, url, deleteFlash, verbose);
    return;
  }

  if (equalsIgnoreCase(cmd, "stop_polling")) {
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
      serialLine[serialLineLen] = '\0';
      handleSerialCommand(serialLine);
      serialLineLen = 0;
    } else if (serialLineLen + 1 < sizeof(serialLine)) {
      serialLine[serialLineLen++] = c;
    } else {
      serialLineLen = 0;
      Serial.println("Serial Eingabe zu lang, verworfen.");
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
