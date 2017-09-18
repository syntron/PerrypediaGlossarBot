Einführung

PerrypediaGlossarBot ist das PHP Skript, welches hinter dem Bot 'SyntronsBot' in
der Perrypedia steht. Es verarbeitet die chronologischen Glossar-Einträge und
erstellt daraus alphabetische Listen. Im Gegensatz zu der alten Version (~2007
bis ~2010) werden die Glossar-Daten in den Heft- und Zyklenzusammenfassungen
nicht berücksichtigt. Dort müssen unter Umständen entsprechende Änderungen
nachgeholt werden.

Wie funktioniert es?
- Seiteninhalt aus der Perrypedia laden
- Glossar-Einträge von den chronologischen Listen einlesen
- Glossar-Einträge sortieren und bearbeiten (Namen) - komplex; siehe Code
- Alphabetische Seiten erstellen
- (optional) Unterschiede alt <=> neu in Datei speichern (Überprüfung)
- Neue Seiten hochladen

- PerrypediaGlossarBot:run() verwendet Exceptions; abgefangen werden diese in
  der Funktion

Wo finde ich den Code?
- Der PHP-Code für den Bot ist auf github zu finden
  https://github.com/...

Wie funktioniert es?
- Ausgabe von ~/PerrypediaGlossarBot.php -h
================================================================================
PerrypediaGlossarBot - Alphabetischen Glossar aktualisieren.

Usage:
  ./PerrypediaGlossarBot.php [options]
  ./PerrypediaGlossarBot.php [options] <command> [options]

Options:
  -d, --debug          Detailierte Ausgaben
  -l FILE, --log=FILE  Logdatei
  -h, --help           show this help message and exit
  -v, --version        show the program version and exit

Commands:
  all      Alle Schritte nacheinander ausführen [0-4]
  prepare  Verzeichnis erstellen und alte Dateien löschen (alias: 0)
  fetch    Glossar-Seiten von der Perrypedia laden (alias: 1)
  create   Alphabetisch sortierte Glossar-Seiten erstellen (alias: 2)
  diff     Unterschiede zu den bestehenden Seiten aufzeigen (alias: 3)
  submit   Neue Glossar-Seiten hochladen (alias: 4)
================================================================================

Anforderungen:
- PHP Kommandozeile (getestet unter opensuse 42.3)
- php7
  - php7-curl
  - php7-json
  - php7-pear (optional; files included as local versions; see below)

- PEAR
  - PEAR:Log
  - PEAR:System
  - PEAR:Getopt (required by PEAR:System)
  - PEAR:Console_CommandLine

Author:
Matthias Pfafferodt (syntron [at] web.de)

Lizenz:
GPL Version 3.0 or later (http://www.gnu.de/documents/gpl-3.0.de.html)

Änderungslog:
2017/09/17 - 0.2.0 - erster Durchlauf auf der Perrypedia
