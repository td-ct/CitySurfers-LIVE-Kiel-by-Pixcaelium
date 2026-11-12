# CitySurfers LIVE @ Kiel by Pixcaelium

Live-Abfahrtszeiten der Kieler Verkehrsgesellschaft im Browser: Haltestellensuche
(ab 1 Buchstabe, mit Tippfehler-Toleranz), GPS/Adress-Standort, Fußweg-Routing
(OSRM, 6 km/h) und Favoriten für Stationen und Adressen, alles lokal im Browser.

## Version
**v0.1.1** – erste Version, ergänzt um Credits an alle Datenprovider und
API-Endpunkt-Entwickler (v0.1 – erste veröffentlichbare Version).

## Nutzung
`index.html` herunterladen oder via GitHub Pages hosten (HTTPS erforderlich,
damit GPS und die Live-Dienste im Browser funktionieren).

## Datenquellen & Credits
- Abfahrten: inoffizielle JSON-Schnittstelle der Kieler Verkehrsgesellschaft
  (INIT InternetService), keine Garantie
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
