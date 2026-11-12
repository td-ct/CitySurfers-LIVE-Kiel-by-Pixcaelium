# CitySurfers LIVE @ Kiel by Pixcaelium

Live-Abfahrtszeiten der Kieler Verkehrsgesellschaft im Browser: Haltestellensuche
(ab 1 Buchstabe, mit Tippfehler-Toleranz), GPS/Adress-Standort, Fußweg-Routing
(OSRM, 6 km/h) und Favoriten für Stationen und Adressen, alles lokal im Browser.

## Version
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
