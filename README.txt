Einführung

Wie funktioniert es?
- Seiteninhalt aus der Perrypedia laden
- Glossar-Einträge von den chronologischen Listen einlesen
- Glossar-Einträge sortieren und bearbeiten (Namen) - komplex; siehe Code
- Alphabetische Seiten erstellen
- (optional) Unterschiede alt <=> neu in Datei speichern (Überprüfung)
- Neue Seiten hochladen

- PerrypediaGlossarBot:run() verwendet Exceptions; abgefangen nur in dieser
  Funktion

Anforderungen:
- php7
  - php7-curl
  - php7-json
  - php7-pear (optional; files included as local versions; see below)

- PEAR
  - PEAR:Log
  - PEAR:System
  - PEAR:Getopt
  - PEAR:Console_CommandLine
