# CitySurfers LIVE @ Kiel by Pixcaelium

Live-Abfahrtszeiten der Kieler Verkehrsgesellschaft im Browser: Haltestellensuche
(ab 1 Buchstabe, mit Tippfehler-Toleranz), GPS/Adress-Standort, Fußweg-Routing
(OSRM, 6 km/h) und Favoriten für Stationen und Adressen, alles lokal im Browser.

## Version
**v1.0.4** – Zwei APKs im Release `android-latest`: `cslk.apk` (Release-Build)
und `cslkd.apk` (Debug-Build, eigene Paket-ID, parallel installierbar). Der
Wecker-Dialog zeigt im Browser (ohne native Brücke) einen Hinweis, dass
Vibration/Weckton im Hintergrund nicht funktionieren, mit relativem
Download-Link `cslk.apk` (funktioniert überall, wo APK und index.html im
selben Verzeichnis liegen). App neu: Menü „Web-Version übernehmen" lädt die
aktuelle index.html von einer URL und speichert sie direkt in der App.

**v1.0.3** – Native Android-Mini-App: `android/` enthält ein minimales
WebView-Projekt, das die index.html als Asset einbettet und über die
JS-Brücke `window.KvgNative` nativen Zugriff auf Vibration und
AlarmManager-Wecker bietet. Zeit-Wecker (X min vor Abfahrt) werden an die App
übergeben und klingeln auch bei ausgeschaltetem Display (Weckton-Channel +
Vibration); Ausschalten meldet die Seite der App. Im Browser läuft die Seite
unverändert weiter (Brücke optional). GitHub-Actions-Workflow
`.github/workflows/build-apk.yml` baut per Knopfdruck den APK und stellt ihn
als Release `android-latest` bereit (stabil herunterladbarer Link).

**v1.0.2** – Großumbau der Bedienung: Die "Aktuell"-Spalte im Fahrtverlauf
füllt sich bei Bussen sofort beim Öffnen (prognostizierte Ist-Zeiten aus der
Fahrt-Anfrage); die "Gep."-Soll-Zeiten liefert der globale ↻-Knopf des
Fahrtverlaufs, der Bus und alle Haltestellen auf einmal aktualisiert. Bei der
Bahn kommen Soll und Ist ohnehin global aus JourneyDetails. Zeilen-Lupen sind
entfernt. In Abfahrtslisten sind Liniennummer und Richtung jetzt Links, die
Stationsansicht und Fahrtverlauf gemeinsam öffnen. Alle drei Bereiche
(Haltestellen-Übersicht, Stationsansicht, Fahrtverlauf) sind unabhängig
auf-/zuklappbar mit Titelleiste; zugeklappte Bereiche sind eingefroren und
werden nicht aktualisiert. GPS-Radius: 75 m bei Bussen, 250 m bei Zügen und
SEV. Neu: Wecker je Haltestelle im Fahrtverlauf (🔔), Distanz (500 m–10 km)
ODER Minuten vor geplanter/aktueller Abfahrt (2–20 min), Auslösung per
Vibration und/oder Weckton; die Stationszeit wird alle (Auswahl)/2 Minuten
geprüft.

**v1.0.1** – GPS-Live im Fahrtverlauf: Erreicht das eigene GPS eine Station der
angezeigten Fahrt (75-m-Radius), wird dort laufend die aktuelle Uhrzeit als
Ist-Abfahrt eingetragen inklusive +/- Indikator; läuft weiter, bis die Station
verlassen wird (danach bleibt der letzte Stand eingefroren). Handy: Beim
ersten Antippen des Stations-Suchfelds bleibt die Bildschirm-Tastatur unten,
erst der zweite Tipp öffnet sie (auch beim Öffnen über eine Adresse).

**v1.0** – Von Timo vergebene Versionsbasis. Stationsansicht und Fahrtverlauf
im Detail-Panel sind jetzt umschaltbare Blöcke: immer genau einer aufgeklappt,
der zugeklappte bleibt eingefroren (keine Aktualisierung, spart Daten).
Kieler Busse: Zeiten aus der Fahrt-Anzeige sind PLAN-Zeiten und stehen in der
Spalte "Gep."; "Aktuell" füllt sich erst durch die Lupe (Ist-Zeit + farbiges
+Xm rot / 0m grün / −Xm blau), bei Nicht-Fund erscheint ein "?" zwischen den
Spalten.

**v0.2.1** – Bugfix Zug-Zeiten: die relative Zeit der DB-Züge wurde in Minuten
statt Sekunden weitergerechnet, dadurch waren angezeigte Abfahrtszeiten
fast identisch; jetzt korrekt. Bahnhof Kiel Suchsdorf ergänzt. Farbcodierung
im Fahrtverlauf: 0m grün, −Xm blau, +Xm rot (Badge bestätigt erfolgreiche
Einzelabfrage einer Haltestelle). SEV-Liniennamen bereinigt ("SEVde:NA" → "SEV").

**v0.2.0** – Fahrtverlauf im DB-Navigator-Stil: vertikale Route mit
Haltepunkten, Spalten "Gep." (Soll) und "Aktuell" (Ist) je Haltestelle,
Verspätungs-Badge (+Xm/−Xm/pünktlich), aktuelle Position hervorgehoben,
befahrene Haltestellen ausgegraut. Züge zeigen Soll/Ist komplett aus einer
Anfrage (HAFAS JourneyDetails), Endstationen mit Ankunftszeit statt Lücke.
Kieler Busse: kompletter Lauf inkl. bereits befahrener Haltestellen aus einer
Anfrage (tripPassages mit old); Soll/Ist weiterer Haltestellen nur auf
Anforderung per Lupe (eine Abfrage je Station, bewusst nicht automatisch).
Abfahrtstafeln aktualisieren sich jetzt spätestens alle 45 s automatisch.

**v0.1.9** – Doppelte Zug-Einträge entfernt: dieselbe Fahrt kam teils als zwei
Produkte mit verschiedenen IDs (z.B. "RB73" und SEV-Ersatz "de:NAHSH") mit
identischer Zeit und Ziel ins Board; jetzt wird nach Ziel+Sollzeit dedupliziert
und der konkrete Liniennamen bevorzugt. Abfragefenster der Bahnhöfe vergrößert
(100 statt 40 Abfahrten), damit auch weiter entfernte Bahnhöfe wie Kiel Hbf
vorausschauende Züge zeigen. Nachtmittags-Zeiten mit Tagesoffset korrekt geparst.

**v0.1.8** – Züge: Doppelte Zug-Einträge entfernt (SEV-Fahrten durchlaufen eine
Haltestelle teils mehrfach mit derselben Fahrt-ID), Fahrtverlauf zeigt jetzt den
zur angeklickten Abfahrtszeit passierenden Durchgang (Zuordnung über Fahrt-ID
plus Soll-Abfahrtszeit), Datenpseudonym-Zeile ("de:NAHSH") als "RE" gelistet.

**v0.1.7** – DB-Haltestellen und Live-Auskünfte (NAH.SH/HAFAS), API-Indikatoren
(DB rot, KVG blau, Nominatim/OSM grün, OSRM orange) mit Leucht-Effekt je
Abfrage und Statistik-Panel (Anfragen und Datenvolumen je Anbieter) per Klick.

**v0.1.2** – Fahrtverlauf pro Fahrt über die Lupe (🔍) mit Verspätungen pro
Station (Live-Auskunft), GPS-Fortschrittsanzeige im Fahrtverlauf (75-m-Regel,
erledigte Stationen werden ausgegraut), Abfahrten älter als 2 Minuten werden
entfernt, Liste sortiert sich bei jedem Standortwechsel neu.

## Nutzung
`index.html` herunterladen oder via GitHub Pages hosten (HTTPS erforderlich,
damit GPS und die Live-Dienste im Browser funktionieren).

## Datenquellen & Credits
- Abfahrten und Fahrtverläufe: inoffizielle JSON-Schnittstelle der Kieler
  Verkehrsgesellschaft (INIT InternetService), keine Garantie
- Fußwege: OSRM (OpenStreetMap-Wegenetz, 6 km/h) via routing.openstreetmap.de
- Adressen: Nominatim (Fallback: Photon/komoot)
- Geodaten: © OpenStreetMap-Mitwirkende, ODbL 1.0
- Offizielle Alternative: https://www.nah.sh

Ein ausführlicher Dank an alle Anbieter steht im Fußbereich der App unter
„Credits & Dank".

## Rechtliches
Impressum und Datenschutzerklärung sind in der App (Fußbereich) enthalten.
Alle Favoriten und Einstellungen werden ausschließlich lokal im Browser
(localStorage) gespeichert.
