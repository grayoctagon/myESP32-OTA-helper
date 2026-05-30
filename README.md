# ESP32-C3 Minimal HTTP OTA Updater

Ein Helper Code für einen schnellstart in OTA über HTTP für ESP32 Arduino Projekte, der Firmware direkt in die freie OTA-App-Partition lädt. Zusätzlich bietet das Projekt serielle Update-Kommandos, optionales Polling und eine reduzierte Codebasis für kleine Flash-Partitionen.


Dieses Projekt wurde teilweise mit Unterstützung von ChatGPT entwickelt. Teile des Codes, der Dokumentation und/oder strukturelle Vorschläge wurden mithilfe von KI generiert und anschließend von mir geprüft, angepasst und integriert. Trotz sorgfältiger Prüfung kann der Code Fehler enthalten. Die Nutzung erfolgt auf eigene Gefahr.


Lizenziert unter CC-BY-SA 4.0: Sie dürfen die Inhalte unter Angabe der Quelle verwenden, teilen und bearbeiten. Jegliche Änderungen müssen unter derselben Lizenz veröffentlicht werden.

---

This project was partially developed with the support of ChatGPT. Parts of the code, documentation, and/or structural suggestions were generated using AI and subsequently reviewed, adapted, and integrated by me. Despite careful review, the code may contain errors. Use at your own risk.


Licensed under CC-BY-SA-4.0: you can use, share, and adapt them with proper attribution, and any modifications must be shared under the same license.

## Features

* HTTP OTA Update ohne `HTTPClient` und ohne HTTPS/TLS Overhead
* Raw HTTP Parser über `WiFiClient`
* Versionsstring aus Build-Datum, Build-Zeit und Dateiname
* Übergibt MAC-Adresse und aktuelle Firmware-Version als GET-Parameter
* Prüft HTTP Status `200`
* Prüft `Content-Type: application/octet-stream`
* Prüft `Content-Length` gegen die verfügbare OTA App Partition
* Fortschrittsanzeige mit Prozent, KB/s und ETA
* Serielle Steuerung für manuelle Updates
* Optionales Polling nach neuer Firmware
* Optionales Löschen der SPIFFS Partition über `esp_partition_erase_range()`

## Serielle Befehle

```text
version
loadUpdate <url> [deleteFlash 0|1] [verbose 0|1]
start_polling <sekunden> [url] [deleteFlash 0|1] [verbose 0|1]
stop_polling
help
```

Beispiel:

```text
loadUpdate example.com/firmware/esp32c3.bin
start_polling 300 example.com/firmware/esp32c3.bin
```

URLs ohne Schema werden automatisch als HTTP URL behandelt.

## Server Anforderungen

Der Firmware Server muss direkt eine `.bin` Datei ausliefern und folgende Header setzen:

```text
HTTP/1.1 200 OK
Content-Type: application/octet-stream
Content-Length: <firmware-size>
```

Beim Aufruf ergänzt der ESP32-C3 automatisch:

```text
?macadress=<MAC>&currentFirmware=<VERSION>
```

Der Gedanke hierbei ist Aufwärtskompatibilität, damit der Server später erkennen kann welcher ESP32 nach Software fragt und diese spezifisch zurück gibt. Damit es z.B. einen ESP gibt der immer die bewaesserung.ino.bin Software bekommt und ein anderer die Lampe.ino.bin

## Sicherheitshinweis

Diese Version verwendet bewusst nur HTTP, um den Sketch klein zu halten. Für produktive Anwendungen sollte die Firmware zusätzlich signiert oder anderweitig gegen Manipulation abgesichert werden.

## Partitionierung

Für OTA werden zwei App Partitionen benötigt. Empfohlen ist eine Partitionierung mit ausreichend großen OTA Slots, zum Beispiel zwei App Partitionen mit jeweils ca. 1.4 MB und einer kleineren SPIFFS Partition.

Benutzerdefinierte Partitionierungen kann man hier gut erstellen: [https://esp32.jgarrettcorbin.com/](https://esp32.jgarrettcorbin.com/)


## License: 
Attribution-ShareAlike 4.0 International CC-BY-SA 

(details see LICENSE.txt file)

[![CC-BY-SA](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](#license)
