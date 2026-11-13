# CitySurfers LIVE @ Kiel by Pixcaelium

Live-Abfahrtszeiten der Kieler Verkehrsgesellschaft im Browser: Haltestellensuche
(ab 1 Buchstabe, mit Tippfehler-Toleranz), GPS/Adress-Standort, Fußweg-Routing
(OSRM, 6 km/h) und Favoriten für Stationen und Adressen, alles lokal im Browser.

## Version
**v1.0.16** – Standort- und Favoriten-Handhabung überarbeitet: Der GPS-an/aus-Knopf (◉ mit Genauigkeitsbalken) sitzt jetzt als feste Ecke in der rechten oberen Bildschirmecke; die Aktivitäts-Indikatoren und Wecker-/Statistik-Panels rücken darunter. Rechts neben der Haltestellensuche gibt es den Knopf „GPS➜“: Bei laufendem GPS mit guter Ortung (± 100 m oder besser) wird die Position sofort (ohne Dialog) für die Sortierung der Haltestellenliste übernommen und die Liste öffnet sich mit Fokus auf dem ersten Eintrag. Bei schlechter Ortung erscheint ein Dialog mit GPS-an/aus-Knopf, Genauigkeitsanzeige und -balken sowie Abbrechen/Übernehmen; er schließt automatisch, sobald die Ortung ± 20 m erreicht. GPS-Ortung wird für die Sortierung der Listen höchstens alle 30 s neu angewendet (Polling-Entlastung). Auf Mobiltelefonen öffnet sich die Bildschirm-Tastatur bei beiden Suchfeldern erst, wenn der Fokus wirklich auf der Texteingabe liegt; programmatisch geöffnete Listen setzen den Fokus auf den ersten Eintrag (Pfeiltasten/Enter steuern die Liste direkt). Adressfavoriten haben jetzt Alias-Namen: In der Favoritenliste bearbeitet eine Maske Alias und Adresse zusammen; die (ggf. neu aufgelöste) Adresse wird beim Übernehmen zugleich als neuer Standort eingeloggt. Die Adresssuche durchsucht Favoriten nach Alias und Adresse mit und zeigt Treffer oben über einer Trennlinie vor den allgemeinen Orten; ein Favoriten-Treffer aktiviert die zugehörige gespeicherte Adresse. Der Favoriten-Knopf für Haltestellen sitzt jetzt genauso oben im Header neben dem der Adressen; beide Panels verdrängen sich gegenseitig. App-Update empfohlen (versionCode 16).

**v1.0.15** – Geofence-Wecker: Distanz-Wecker klingeln in der App jetzt auch bei ausgeschaltetem Display bzw. im Hintergrund. Die Seite bleibt das Konzept: Sie konfiguriert die Zäune (jeweils Radius = Wecker-Distanz um die Wecker-Haltestelle, mit Klang/Vibration/Lautstärke aus dem Wecker-Dialog), die App registriert sie über die Android-Geofencing-API beim System. Beim Eintritt in den Radius startet dieselbe native Läut-Kette wie beim Zeit-Wecker (3 s Ton/Vibration, 5 s Pause, Wiederholung bis zum ⏰-Antippen, plus Benachrichtigung); jeder Zaun feuert nur einmal. Beim Zurückkehren in die App übernimmt die Seite ausgelöste Geofence-Wecker (⏰-Symbol, Wecker gilt als ausgelöst). Neue Berechtigung ACCESS_BACKGROUND_LOCATION wird nach der Standortfreigabe separat abgefragt („Bei allen Zeiten“); ohne sie klingeln Geofence-Wecker nur bei offener App. Im Browser bleibt alles wirkungslos, die Seite läuft unverändert weiter. Benötigt App-Update.

**v1.0.14** – GPS-Signal im Fahrtverlauf verfeinert: Tritt das GPS in den Stationsradius (75 m Bus, 250 m Zug), blinkt die Ist-Zeit unter „Aktuell“; durchgestrichen und ausgegraut wird die Zeile erst, wenn der Radius wieder verlassen wird. Zusätzlich wird beim Eintritt in den Radius geprüft, ob das Verkehrsmittel dort überhaupt sein kann: Liegt die Systemzeit außerhalb des erweiterten Fensters (± 2 Minuten) um die Abfahrtszeit der Fahrt an der Station (Ist-Zeit falls schon abgefragt, sonst Soll), wird der Fortschritt nicht gesetzt; stattdessen blinkt der kleine runde GPS-Knopf oben in der Statusleiste rot. Ein solches Ereignis löst genau einmal eine Echtzeitabfrage der Linie aus (sofort und noch mal 2 Minuten später); danach wird der alte Zustand des GPS-Knopfes wiederhergestellt, sofern er nicht zwischenzeitlich neu gesetzt wurde.

**v1.0.13** – Standort-Handling überarbeitet: Der GPS-an/aus-Knopf (◉ mit Genauigkeitsbalken) sitzt jetzt in der Statusleiste oben ganz rechts. Am Adressfeld gibt es stattdessen „⤓ GPS“ (aktuelle Position übernehmen; bei sehr schlechter Ortung erscheint ein Dialog mit Genauigkeit in Metern, einstellbarem Schwellenwert für automatische Übernahme, OK und Abbrechen) sowie „➜“ zum Einloggen einer eingegebenen Adresse. Das Adressfeld akzeptiert jetzt zusätzlich flexible Koordinaten (Dezimal- und Grad/Min/Sek-Formate, auch mit N/S/E/W), Maidenhead-/QTH-Locator (JO49sk), Plus Codes (auch kurz, mit Kiel-Bezug), geo:-URIs sowie Karten-Links von Google Maps, Apple Karten, Bing, OpenStreetMap, HERE und Yandex – alles lokal erkannt, ohne Abfrage. Die App ist als Senden-Ziel („Teilen mit“) registriert: Geteilte Adressen, Koordinaten, Locator oder Karten-Links landen im Adressfeld, werden geprüft und bei Gültigkeit direkt als Standort übernommen (erfordert App-Update). Beim Adress-Login wird laufender Haltestellen-Suchtext gelöscht und die Liste zeigt die zehn nächsten Stationen nach Abstand; die Stationliste rendert nur die ersten 10 Treffer und lädt weitere 10er-Schritten beim Scrollen nach. Im Fahrtverlauf wird kein OSRM-Routing mehr abgefragt: Fußweg-Schätzungen dort laufen über die Luftlinie (× 1,3), das spart Daten.

**v1.0.12** – Wecker-Benachrichtigungen in Android: Für jeden gestellten Wecker liegt eine Nachricht in der Benachrichtigungsleiste (eigener Kanal „Gestellte Wecker“, still). Sie zeigt Station, Bedingung, Läut-Zeit und Countdown, aktualisiert sich laufend und verschwindet, sobald der Wecker gelöscht oder auslöst bzw. wenn die App beendet wird. Antippen öffnet die App direkt im Reiter des Weckers.

**v1.0.11** – Reiter-System und Wecker-Übersicht: Unterhalb der Suchfelder lassen sich mehrere Haltestellen (mit Abfahrtsübersicht und Fahrtverlauf) als horizontale Reiter offen halten; Reiter sind verschiebbar (Drag) und per ✕ schließbar. Hat ein Reiter einen Wecker, warnt das Schließen und löscht den Wecker mit. Neu: Wecker-Symbol ⏰ oben neben den Aktivitäts-Indikatoren listet alle gestellten Wecker mit Bedingung, Läut-Zeit und Countdown; ein Klick springt in den Reiter. Haltestellenfavoriten gibt es jetzt als Liste (⭐-Knopf über der Haltestellensuche) wie bei den Adressen; der „Merken“-Knopf ist entfallen. Der Zustands-Export sichert alle offenen Reiter.

**v1.0.10** – Zustands-Export/-Import umfasst jetzt auch die aktuell geöffnete Ansicht: Die Kette aus gewählter Haltestelle und ggf. ausgewählter Bus-/Zuglinie inkl. geöffnetem Fahrtverlauf wird im Zustands-Code mitgesichert und beim Import (z. B. in die Android-App) automatisch wiederhergestellt.

**v1.0.9** – Durchgehendes Läuten: Der Wecker klingelt/vibriert 3 s, pausiert 5 s und wiederholt sich, bis er am ⏰-Symbol gelöscht wird. Gestellte Wecker zeigen ⏰ statt 🔔; Antippen öffnet den Dialog mit „🗑 Wecker löschen“ (beim Läuten beendet der Griff zum ⏰ den Wecker sofort). Der native AlarmReceiver läutet im selben Rhythmus (bei ausgeschaltetem Display), die Seite übernimmt laufende Läut-Ketten via ringingAlarms()-Brücke.

**v1.0.8** – Wecker-Verbesserungen: Im Wecker-Dialog sind jetzt Ton (Wecker/Klingelton/Nachricht) und Lautstärke einstellbar, plus Test-Knopf; die Einstellungen werden gesichert (auch im Zustand-Export). Vibration geht in der App jetzt zusätzlich über die native Brücke (WebView-navigator.vibrate ist unzuverlässig), der AlarmReceiver spielt Klang und Vibration gemäß den Einstellungen (stiller Notification-Channel, Ton über MediaPlayer mit gewählter Lautstärke). Der Schriftgrößen-Regler zoomt die Kopfzeile gegen, sodass der Slider beim Ziehen nicht mehr zuckt.

**v1.0.7** – App-Fix: In der Gradle-Konfiguration fehlte das Kotlin-Plugin,
wurde dadurch gar kein Code kompiliert und die APK enthielt eine leere
classes.dex – die App stürzte sofort nach dem Start ab. Neu:
`org.jetbrains.kotlin.android` (Root + App-Modul), JVM-Target 17,
versionCode 7. Bitte alte App deinstallieren und neu installieren (CI
signiert mit frischem Debug-Key).
**v1.0.6** – App-Standard-URLs auf das Hosting umgestellt: Seite als

https://pixcaelium.timo-damm.de/apps/citysurfers/cslk.html, APK daneben als
cslk.apk (der relative Download-Link im Wecker-Dialog greift dadurch
automatisch). "Web-Version übernehmen" schlägt jetzt die eigene Server-URL
vor; APK-Links in der App öffnen den System-Download.

**v1.0.5** – Zustandsübertragung: Die gewählte Startadresse wird jetzt
gesichert (localStorage) und beim Start wiederhergestellt. Neuer Knopf
"⇅ Zustand" exportiert Favoriten (Haltestellen + Adressen), Startadresse,
letzten Standort und Einstellungen als kompakten Code bzw. spielt einen Code
wieder ein. Damit lässt sich der komplette Nutzerzustand von einer
Browser-Sitzung (z. B. vom eigenen Server) in die Android-App übertragen –
dort per App-Menü "Zustand importieren" (Code einfügen, gilt für den
WebView-Speicher der App).

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
