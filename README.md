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


## ToDos
- Tippfehler "macadress" korrigieren
- eine bessere Authentifikation des ESP32, statt nur die MAC adresse. Etwa Authorization: Bearer <device-token> -> im Flash des esp speichern, ggf automatisch rotieren?

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

Prompt für Serverseite: [backendPrompt.txt](./backendPrompt.txt)

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

---

## Sicherheitshinweis

Diese Version verwendet bewusst nur HTTP, um den Sketch klein zu halten. Für produktive Anwendungen sollte die Firmware zusätzlich signiert oder anderweitig gegen Manipulation abgesichert werden.

## Partitionierung

Für OTA werden zwei App Partitionen benötigt. Empfohlen ist eine Partitionierung mit ausreichend großen OTA Slots, zum Beispiel zwei App Partitionen mit jeweils ca. 1.4 MB und einer kleineren SPIFFS Partition.

Benutzerdefinierte Partitionierungen kann man hier gut erstellen: [https://esp32.jgarrettcorbin.com/](https://esp32.jgarrettcorbin.com/)

## init Prompt 

ich habe einen ESP32C3 super mini, ich würde gerne einen arduino sketch schreiben, der sich selbst ota updaten kann, dazu soll er einerseits im setup immer die eigene version ausgeben, er soll auch eine funktion loadUpdate haben, die als parameter eine https oder http url annimmt (wenn nicht angegeben, soll http verwendet werden) und einen parameter "deleteFlash" (also dass der lokale SPIFFS flash gelöscht werden soll nachdem das update geladen wurde), und einen parameter "verbose"(also ob via serial informationen ausgegeben werden sollen, oder nicht). Beim Aufruf der url sollen die get-parameter ?macadress=<espMacAddress,e.g.12-34-56-78-90-12>&currentFirmware=<currentfirmware> angegeben werden.
Beim Download soll im header geprüft werden ob es einen code 200 gibt und der content-type "application/octet-stream" ist, wenn nicht soll ein Fehler ausgegeben werden. Es soll auch anhand des headers prüfen ob genug platz im App-speicher im flash ist (ggf einen Fehler ausgeben).
Die Funktion soll von der angegebenen url die neue firmware als bin-file direkt un den unbenutzten app-speicher laden, und den download Vortschritt ca einmal die sekunde ausgeben, inklusibe wieviele KB/s und wie viel zeit es noch dauert. Nachdem die Firmware heruntergeladen wurde soll diese aktiviert/geflasht werden und der esp soll damit neustarten. Meine aktuelle flash Partitionierung in der arduino ide ist: (zwei mal) 1.2MB APP + 1.5MB SPIFFS 
verwende um die aktuelle vdersion zu benennen __DATE__  __TIME__ und __FILE_NAME__ (falls es das nicht gibt nimm __FILE__ um den teil nach dem letzten / bzw \ zu verwenden) mache es url save, damit es ca so aussieht: "May-24-2026_14-37-12_ESP32C3_OTA.ino" .

Es soll außerdem eine helper funktion geben die serial eingaben abwartet und mit der ich via Serail ein update laden kann
als auch eine "start_polling" funktion die als parameter die sekunden übernimmt in denen gepollt werden soll, z.B. "start_polling 300" = alle 5 minuten



## License: 
Attribution-ShareAlike 4.0 International CC-BY-SA 

(details see LICENSE.txt file)

[![CC-BY-SA](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](#license)
