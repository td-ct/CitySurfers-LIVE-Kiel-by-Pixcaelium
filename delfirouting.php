<?php
declare(strict_types=1);
/**
 * delfirouting.php - v0.2.16
 * Eigenes deutschlandweites OEPNV-Routing (Tuer zu Tuer) aus DELFI-Fahrplandaten.
 * Primaerquelle ist das DODP-Gesamtdeutschland-Zip (opendata-oepnv.de,
 * Soll-Daten bis ca. 31.3.2027, einmaliger Vollbau mit 128-Tage-Bitmasken je
 * Fahrt, danach KEIN taeglicher Bau-Cron mehr); die gtfs.de-free-Feeds
 * fv/rv/nv (CC-BY, Datenbasis DELFI) bleiben als Failsafe drin, falls kein
 * frisches DODP-Zip vorliegt. Echtzeit gtfs.de-realtime-free im free-Modus,
 * Zubringer-/Ziellaeufe OSRM-geroutet (FOSSGIS, Fallback Luftlinie 1,3x mit
 * 5 km/h), Modi &vm=walk|bike|ebike|scoot (17/22/18 km/h, +2 min Handhabung).
 *
 * v0.2.14 Tages-Anfragen im Budget (Timo 25.11.2026: Alex->Potsdam ging
 *   ueber VBB, delfirouting aber NICHT; auch tagsueber generell 25-s-Timings
 *   bzw. 502). Diagnose per &dbg=1: Nachts konvergiert die Welle frueh
 *   (Kiel 23:00: 5,4 s, Wellen 4-7 fast leer), tagsueber bei 3,2 Mio
 *   aktiven Fahrten laufen dagegen ALLE 9 Wellen gegen die Mark-Cap, jeder
 *   Stop scannt Abfahrten im DFR_SCAN_MIN=240-min-Fenster und laedt je
 *   Unique-Trip ein Platten-tprofil - Summe > 25 s, im Berlin-Dichtenetz
 *   additionally Speicher-Druck bis zum FPM-Absturz (502). Vier Hebel,
 *   Ergebnis-Qualitaet bewusst unangetastet:
 *   (a) ZIEL-PRUNING: Stops, deren fruehester tau bereits >= beste
 *       Zielankunft + DFR_KEEP_SLACK (60 min) liegt, koennen keine
 *       Verbindung im Arsenal mehr beitragen und werden nicht mehr
 *       gescannt (erst ab Welle 1, wenn es eine Zielankunft gibt; die
 *       v0.2.13-Ferne-Frontier bleibt intakt: Durchfahrts-Ketten, die
 *       spaeter am Ziel ankommen als bestFin+60, sind ohnehin tot).
 *   (b) STAGNATIONS-ABBRUCH: 2 Wellen ohne Verbesserung der besten
 *       Zielankunft (ab Welle 4) beenden die Suche - die Spaetwellen sind
 *       genau die teuersten.
 *   (c) DFR_SCAN_MIN 240 -> 120 Minuten: Zwei Stunden Abfahrtsfenster
 *       je Stop reichen fuer frueheste-Ankunft plus Alternativen locker.
 *   (d) SPEICHER-NOTKAPPE: tCache-Flash ab 400.000 Eintraegen (502-).
 * v0.2.16 Abend-noconns + Early-Exit (26.11.2026, Routing-Test 10 Metropolen
 *   intern / 10 Nachbar- / 10 Langstrecken: 5 von 10 Langstrecken plus
 *   Nuernberg->Bamberg liefen abends mit noconns ins Leere, obwohl der
 *   Bahnhof-Fallback der App 6-9 Zug-Verbindungen fand; tagsueber liefen
 *   dieselben Paare). Zwei Hebel:
 *   (a) EARLY-EXIT: Sobald die Welle eine Zielankunft hat und mehr als
 *       DFR_EARLY_S Sekunden verstrichen sind, endet die Suche - die
 *       teuersten Spaetwellen ohne echte Verbesserung entfallen
 *       (Hamburg->Kiel 27,5 s -> ~13 s), Ergebnisqualitaet unangetastet,
 *       solange die Welle frueher konvergiert.
 *   (b) ZIEL-HOP-WIEDERHOLUNG: Lief die normale Welle komplett ohne
 *       Zielankunft (abends duenner Takt: Anschluss-Wartezeit am Hub
 *       oft > 120-min-Scan-Fenster, DFR_DEP_CAP=15 schneidet die seltene
 *       spaete Abfahrt an kleinen Stops ab), startet EINE Wiederholung
 *       mit weitem Fenster DFR_SCAN_WIDE=360, Deckel DFR_DEP_WIDE=45
 *       und DFR_ROUNDS_WIDE Wellen - nur im verbleibenden Zeitbudget,
 *       nur wenn vorher noconns geliefert worden waere, und ab der
 *       ERSTEN Zielankunft sofort fertig. dbg liefert jetzt auch bei
 *       noconns das Wellenprofil (retry-Welle markiert).
 * v0.2.15 Abfahrts-Deckel je Stop (25.11.2026, wenige Stunden nach dem
 *   v0.2.14-Deploy: Kiel- und Berlin-Binnenrouten liefen jetzt im Budget,
 *   Alex->Potsdam aber weiter gegen die 25 s; die teuren Wellen laufen VOR
 *   der ersten Zielankunft, das v0.2.14-Ziel-Pruning greift erst danach).
 *   RAPTOR-Naeherung: Je markiertem Stop genuegt je Welle die ERSTEN
 *   DFR_DEP_CAP=15 Abfahrten ab tau (frueheste Ankunft braucht nie die
 *   spaeteren einer Runde - verbesserter tau markiert den Stop ohnehin
 *   erneut); dichte Innenstadt-Hubs mit 30-100 Abfahrten im 120-min-Fenster
 *   verlieren damit ihren Dominanzanteil. Zusaetzlich liefert der Timeout-
 *   Fehler jetzt den dbg-Block mit (Wellenprofil auch fuer scheiternde
 *   Anfragen, Diagnose ohne Rebuild).
 * v0.2.13 Echtzeit-Katalog fuer den DODP-Index (Timo 24.11.2026 abends: "mit
 *   Delfirouting eine gute Planung und trotzdem VBB/Echtzeit einblenden; IDs
 *   matchen? Katalog bauen!" - Der App-Output traegt dD je Fahrt schon seit
 *   v0.2.11, rt war aber IMMER 0, weil der bisherige Join nur den gtfs.de-free-
 *   Strom kannte, dessen anonymisierte Kurz-IDs NUR zum free-Index passen).
 *   Neu ist eine zweistufige Zuordnung gegen den echten DELFI-Pool ( dieselbe
 *   Mobilithek-Subscription wie delfiproxy.php, mit deren mTLS-Zertifikaten aus
 *   ../delficerts, gemeinsame Strom-Datei ../pool.pb mit flock-Verriegelung,
 *   so dass beide Proxies sich abwechseln, ohne sich zu stoeszen):
 *   Stufe 1 EXAKT: Pool-Trip-ID == DODP-trips.tsv-Trip-ID (beide offizielle
 *   DELFI-Kenner; Treffer tendiert gegen 100 % und geht VOR der Welle in die
 *   Fahrzeiten), Stufe 2 HEURISTIK (der "Katalog"): je Fahrteintrag im Pool-
 *   Strom wird stop_id (de:-Kenner) + geplante Abfahrt (departure-ts minus
 *   delay) indiziert; fuer Fahrt-Legs OHNE exakten Treffer wird am Einstiegs-
 *   Halt die haltestellen-und-minutengenaue Passage gegen den Index geprueft
 *   (+/- 4 min Fenster, naechster gewinnt) und die Verspaetung nachgereicht
 *   (nachtraeglich, Anzeige-Ebene). Antwort sagt rt (Anzahl), rt_src
 *   (pool|pool+heur|free) und Pool-Trip-ID-Proben in probe=1 (rtcat: raw Trips,
 *   exakte Joins, Katalog-Stopzahl) - so sieht man nach dem Deploy sofort,
 *   ob Stufe 1 greift.
 *   ZWEITE Heilung (Timo: "S-Bahn mit weit entfernten Zielen und die Ringbahn
 *   anpassen, durchgehende Verbindung nach Potsdam"): Der Wellen-Markierer
 *   kappt bei Ueberlauf die markierten Stops auf die DFR_MARK_CAP fruehesten
 *   (tau-sortiert) - fernen Durchfahrts-Halten (spaete tau, z. B. Potsdam Hbf
 *   nach durchgehender S7) wird damit der Anschluss-Weg abgeschnitten und die
 *   Rekonstruktion verknotet sich durch Bus/Ring-Kaskaden. Neu: das Kappt-
 *   Stueck haelt ZUSAETZLICH (a) die DFR_MARK_TAIL spaetesten Stops als
 *   Ferne-Frontier ( Durchfahrt-Ketten laufen weiter), (b) ALLE Ziel-
 *   Zubringerstops (walkTo) unkappbar und (c) optional &dbg=1 in der Antwort
 *   ein dbg-Block (je Welle: rein/gekappt/head+tail+acc, bestFinish-Minuten),
 *   damit Potsdam-artige Faelle nach Deploy im Blick bleiben.
 *   Zusaetzlich DFR_ROUNDS 8 -> 9.
 * v0.2.12 Keine Hop-Doppelung im Endlauf + OSRM-echte Zubringerdauern +
 *   Modi bike/ebike/scoot (Timo-Report 24.11.2026): (1) Der Ziellauf einer
 *   Verbindung lief als HOP-KETTE von Haltestellen (Beispiel Bremerskamp ->
 *   Botanischer Garten -> Ziel, 8 + 2 min, statt EINEM ~10-min-Lauf), weil
 *   die Welle das Ziel nur ueber Zubringerstops (accTo) erreicht und die
 *   Kette dorthin ueber Geo-Umstiege laeuft. Abhilfe ohne Aenderung der
 *   Indexformate: Rekonstruktion sammelt die Kette erst mit STOP-NUMMERN
 *   ein, dann werden Fuss-/Modus-Ketten an BEIDEN Enden zu EINEM Lauf
 *   zusammengelegt (Einstieg: Start -> Zustiegshaltestelle; Ausstieg:
 *   Ausstiegshaltestelle -> Ziel; ohne OEPNV: Start -> Ziel; echte
 *   Zwischen-Umstiege zwischen zwei Fahrten bleiben eigene Legs) und die
 *   Signatur dedupliziert nur noch Transit-Legs (Hop-Zwillinge entfallen).
 * (2) Diese zusammengelegten Laeufe werden serverseitig ueber FOSSGIS-OSRM
 *   geroutet (routing.openstreetmap.de/routed-foot bzw. -bike, je Modus;
 *   Datei-Cache 30 Tage unter dfrouting/osrm/, 1-m-Koordinatenraster,
 *   Groessenlimit 4000 Dateien mit aelteste-Trimmen, Breaker: 3 Fehler
 *   oder 6 s kumuliert je Anfrage; Fallback Luftlinie 1,3x, walk 5 km/h):
 *   dist/mins in der Leg sind echt, arr/dur werden aus echtem Ausstieg +
 *   echtem Ziellauf NEU gerechnet, die Verbindungen sortieren nach echter
 *   Ankunft. Fahrrad/E-Bike/Roller: OSRM-bike-DISTANZ zur eigenen
 *   Geschwindigkeit (17/22/18 km/h) plus 2 min Handhabung.
 * (3) NEU &vm=walk|bike|ebike|scoot (Default walk, alles andere -> walk):
 *   Zubringer-/Ziellauf UND Geo-Umstiege laufen im Modus-Tempo (lediglich
 *   die Parent-Paare aus transfers.txt haben keine Meter und werden aus
 *   der Gehzeit skaliert: ceil(walkMin*5/kmh)+2); Wellenseeds,
 *   Umstiegs-Dauern und Parent-'w'-Werte tragen Modus-Minuten. Antwort
 *   traegt vm, jede Bewegungsleg ein vm-Feld. Kein Format-/Rebuild-
 *   Aufwand, Deploy reicht.
 * v0.2.11 Verlauf fuer die App + Parent-Parse-Fix (Timo-Report 23.11.2026
 *   abends: delfirouting liefert Ergebnisse, aber JEDE Fahrt zeigte die
 *   Linie von Trip 0 (zufaellig "61"), Richtung immer leer ("Richtung ?"
 *   in der App) und der Fahrtverlauf der Linien war nicht aufklappbar):
 *   (1) URSAECHLICHER PARSE-BUG seit v0.1.0: Der Parent-Zeiger der Welle
 *   ist 'j' . trip . '|' . seq . '|' . stop . '|' . dep . '|' . arr
 *   bzw. 'w' . stop . '|' . walkmin - die Leg-Zusammenlegung exploade('|')
 *   aber OHNE das 'j'/'w'-Praefix abzutrennen, und (int)'j4157842' ist in
 *   PHP 0. Detail: $pp[2]/[3]/[4] (Stop/dep/arr) trafen zufaellig die
 *   richtigen Felder, nur $pp[0] war 'j<trip>' -> Linie/Verzoegerung/Fuss-
 *   weg-Start UND die Ruckwaerts-Kette nach Umstiegs-Fusswegen liefen auf
 *   Trip 0 bzw. Stop 0 (jene Conns fielen dann ersatzlos weg). Fix:
 *   explode('|', substr($pd, 1)) - Indizes bleiben unveraendert.
 *   (2) Legs jny tragen jetzt 'tid' (TripNr) und 'stops' (Soll-Verlauf des
 *   eigenen Abschnitts aus dem tprof: seq/board..exit, Name, dep/arr-Times,
 *   lat/lon) - die App kann den Abschnitts-Verlauf damit direkt aufklappen.
 *   (3) NEU ?trip=<TripNr>: ganzer Lauf einer Fahrt (profil-sortiert, mit
 *   seq/dep/arr/name/lat/lon/active) fuer die App-Erweiterung "ganzer
 *   Lauf" und fuer die spaeter-Halt-Weitestfahren-Pruefung. Kein Index-/
 *   Format-Aufwand, Deploy reicht.
 * v0.2.10 fgets-Schutz (Timo-Report 23.11.2026 nachmittag: manuelle
 *   delfirouting-Wahl in der App warf fatal "TypeError: fgets(): Argument
 *   #1 ($stream) must be of type resource, false given"): (1) Im Query-Pfad
 *   waren die Grid-Zellen-Leser (dfr_near, dfr_cell_band) nur per is_file
 *   vor fopen gesichert; laeuft der Deskriptor-Vorrat des Prozesses
 *   mitten in der Suche leer (fd-Limit des Webspace), liefert fopen false
 *   und fgets(false) wirft genau diesen TypeError als fatal-JSON. Beide
 *   Leser ueberspringen jetzt nicht mehr lesbare Zellen (dfr_log, kein
 *   Fatal). (2) Mitursache angegriffen: dfr_fopen legte Handles nur an
 *   und schloss nie (bis zu 6 Dateifamilien x 256 Buckets + Rest = ~1540
 *   offene Handles je Request moeglich); der Handle-Cache schliesst ab
 *   400 Eintraegen ALLE gecachten Handles und baut sich neu auf - sicher,
 *   weil jeder Leser vor jedem fread explizit fseek setzt (kein
 *   Lesezeiger-Zustand noetig). (3) dfr_maps_reload (Build-Resume) hat
 *   ebenso einen fopen-Guard bekommen. Kein Format-, kein Rebuild-Aufwand,
 *   Deploy reicht.
 * v0.2.9 App-Anbindung (CitySurfers v1.6.0): delfirouting wird als waehlbares
 *   und als dritte Stufe des Auto-Routers eingebunden. Damit die App weiss, ob
 *   der Server wirklich nutzbaere Profile hat: (1) probe=1 liefert jetzt
 *   idx.dprof_mb/idx.tprof_mb (Bucket 000); die App schaltet das Backend nur
 *   bei tprof_mb >= 1, done und gueltigem valid_until frei - nach den
 *   Format-Zerstoerern blieben je Buckets unter 0,3 MB uebrig, gesunde
 *   Buckets haben einige MB. (2) Jny-Abschnitte in conns tragen jetzt 'dir'
 *   (Kopfziel der Fahrt aus dem tprof-Profil, dfr_trip_headsign), damit die
 *   App "Richtung ..." anzeigen kann; kein Format-, kein Rebuild-Aufwand,
 *   deploy reicht. Keine Index-/Eventformat-Aenderung.
 * v0.2.8 ZWEIFTE Wurzelursache des leeren Index (diag v0.2.7: dprof/tprof
 *   gefuellt, Querys trotzdem walk-only/noconns in unter 200 ms; tprof
 *   cnt:1, dprof ueberall seq:0, tidx_000 447656 B trotz nur 3,2 Mio Trips):
 *   Die Event-SCHREIBER in stimes1 (free UND DODP) packten
 *   pack('VvvvV', stop, dep, arr, trip, seq) - fuenf Formatcodes V,v,v,v,V
 *   ordnen sich ARGUMENTWEISE zu, nicht nach Namen: trip fiel auf v
 *   (2 Byte, mod 65536!) und seq auf V (4 Byte). Der Lexer las mit
 *   erwartetem V,v,v,V,v: 'trip' war trip-Low16 inkl. der zwei ersten
 *   Seq-Bytes als Muell (Werte bis > 14 Mio, daher tidx bis Slot 55956),
 *   'seq' waren die High-16-Bits der echten Seq (fast immer 0). Je Stop
 *   einer Fahrt variierte der Muell-Trip, also kollabierte JEDE Fahrt im
 *   tprof auf einen Eintrag (cnt:1) und die Engine konnte keine Fahrt
 *   je weiterverfolgen. Gleiche PHP-Bugklasse wie v0.2.6 (Formatcodes
 *   iterieren unabhaengig von Argument-/Feldnamen), diesmal beim packen:
 *   Schreiber jetzt pack('VvvVv', stop, dep, arr, trip, seq) = 14 B in der
 *   dokumentierten Reihenfolge; der tp-Bucket-Schreiber (V,v,v,v,V mit
 *   Argumenten trip,seq,dep,arr,stop) war dagegen korrekt und bleibt.
 *   Alle uebrigen pack/unpack-Paare (nidx, sll, dprof-, tprof-, trans-,
 *   trx-Bloecke, Slots, tp-Files, x-Files) verifiziert und konsistent.
 *   repair erweitert: raeumt jetzt auch tmp/dodp_<day0> komplett weg
 *   (Stunden-mix alter/neuer Eventformate in Resume-Anhaengen waere fatal).
 * v0.2.7 Repair-Crash-Fix (erster echter repair-Lauf 23.11.): TypeError
 *   dfr_day_diff(): Argument #1 ($from) must be of type string, int given.
 *   URSACHE: dfr_dodp_dirs() liefert day0 als Array-SCHLUESSEL, und PHP
 *   castet rein numerische String-Schluessel ('20261123') automatisch zu
 *   int; unter strict_types=1 lehnt dfr_day_diff(string $from, ...) den
 *   int dann ab. Der Fehler traf repair (dfr_repair-Loop) UND lauerte
 *   genauso in der Frueh-Fertig-Rueckkehr von dfr_build_dodp (kam beim
 *   Erstbau nie zum Zug, weil dfr_dodp_dirs nur DONE-Verzeichnisse
 *   liefert und vor dem ersten DONE kein Eintrag existierte). Fix an der
 *   Wurzel: dfr_day_diff akzeptiert jetzt int|string und normalisiert auf
 *   string; dfr_repair schreibt day0 als String in den State.
 * v0.2.6 Ursaechlicher Fix des leeren Index (diag v0.2.5 brachte es ans
 *   Licht: tripday 867.589 aktive Fahrten OK, aber dprof/tprof NUR je
 *   12 Byte, didx/tidx je 8 Byte, trans-Paare null): DREI Fixes.
 *   (1) stimes2 las die 14-Byte-Events mit unpack('Vstop/dep/varr/...').
 *       In PHP ist 'd' der DOUBLE-Code (8 Byte): das Format parst
 *       V+d+v+V+v = 20 Byte statt 14, unpack liefert false und $rows
 *       kollabierte je Bucket auf EINE Null-Zeile (alle Schluessel
 *       identisch). Korrekt: 'Vstop/vdep/varr/Vtrip/vseq'.
 *   (2) dfr_trans_block las 5-Byte-Records (alter 5-B-Entwurf 'Vv' in
 *       der Doku), der Bau schreibt aber pack('Vv') = 6 Byte pro Paar:
 *       Stride 5 -> 6, sonst Lesefehler und null-Paare.
 *   (3) dfr_build_dodp behandelte jede dodp_*-Verzeichnis existenz als
 *       "fertig"; fehlt DONE (Abbruch oder repair), kam der falsche
 *       Frueh-Rueckruf "Index bereits fertig". Jetzt greift das nur
 *       noch mit vorhandener DONE-Datei.
 *   NEU repair-Bootstrap: "import=1 repair=1" (CLI) entfernt DONE/
 *   stimes_done und die kollabierten dprof/didx/tprof/tidx/trans/trx,
 *   setzt den State auf Phase stimes zurueck und laesst den Bau dann
 *   NUR die Phasen ab stop_times neu laufen (svc/tabs mit svcday.tsv,
 *   stops/trips.tsv, tripday.bin, gridb, nidx/nms/sll, trline bleiben
 *   erhalten und werden geskippt).
 * v0.2.5 Diagnose-Modus ?diag=1: Nach dem v0.2.4-Fix liefen die Web-Modi
 *   fehlerfrei, aber Querys fanden NUR reine Fussweg-Verbindungen bzw.
 *   noconns, alle in unter 100 ms (zu schnell fuer echte Wellen-Suche).
 *   Ohne Shell-Zugriff braucht es Beweise aus dem Index: diag zaehlt die
 *   heute aktiven Fahrten im tripday-Bitmap, sampelt die ersten nicht-leeren
 *   dprof- (Abfahrten), tprof- (Fahrtprofil) und trans-Bloecke (Umstiege)
 *   mit Labels und Aktiv-Bits und nennt die Dateigroessen der Kern-Indizes.
 * v0.2.4 Query-Fehlerfix: dfr_route() bekam als zehnten Parameter das
 *   Zeitlimit als FLOAT (microtime(true) + DFR_TIMEOUT_S), der Signatur-
 *   Parameter ist aber int und mit strict_types=1 warf PHP einen TypeError
 *   Fatal, der als leerer HTTP 500 erschien. Erst das v0.2.3-try/catch machte
 *   ihn sichtbar: Der TypeError war die Ursache der Query-500er vom
 *   23.11.2026 (nicht der Speicher). Fix: int-Cast an der Aufrufstelle.
 * v0.2.3 Robustheits-Patch nach dem ersten DODP-Vollbau-Lauf (alle Web-Modi
 * sind speicher- und fehlersicher geworden):
 *   - probe zaehlt stops/trips als STROM (fgets) statt count(file()), das
 *     lud die kompletten TSVs (1,3 Mio bzw. 1,9 Mio Zeilen) als Array in den
 *     RAM und starb am Web-SAPI-Limit mit HTTP 500 ohne Body; probe nennt
 *     jetzt zusaetzlich Dateigroessen, DONE-Stand, PHP-SAPI, memory_limit und
 *     ob gerade ein Bau-Prozess laeuft (build.lock).
 *   - dfr_trip_active liest das Aktiv-Bit per fseek+EIN Byte aus tripday.bin
 *     statt die komplette Datei (rund 30 MB) als String zu laden.
 *   - dfr_build haelt ein LOCK_EX|LOCK_NB auf build.lock: GENU EIN Bau-Prozess;
 *     warm=1 antwortet waehrend eines laufenden Imports mit code:busy, statt
 *     sich mit den Resume-Zaichern desselben Baus zu verheddern. Das Lock
 *     endet mit dem Prozess (auch bei kill), es bleibt kein Rest zurueck.
 *   - Alle Web-Modi melden Fatale Fehler als JSON {ok:false,code:fatal,...}
 *     mit Klasse, Meldung und Zeile statt als leerer HTTP 500.
 *
 * Projekt CitySurfers LIVE @ Kiel by Pixcaelium (td-ct) - Timo Damm.
 * Web: pixcaelium.timo-damm.de/apps/citysurfers/ (liegt parallel zu
 *      delfiproxy.php im CitySurfers-Verzeichnis)
 *
 * Schnittstellen:
 *   HTTP: ?from=lat,lon&to=lat,lon&dep=HH:MM[&walk=200..5000][&limit=1..8]
 *         [&vm=walk|bike|ebike|scoot]  Zubringer-/Ziellauf im Modus-Tempo
 *         Antwort JSON, Fehler als {"ok":false,...} mit HTTP 200.
 *         ?probe=1   Status/Index-Info
 *         ?diag=1    Tiefen-Diagnose des Index (Bitmap/dprof/tprof/trans)
 *         ?warm=1    eine Bau-Runde (18 s Budget, Cron-tauglich, resume-faehig)
 *   CLI : php delfirouting.php import=1            (Cron-Slot, 900 s Budget)
 *         php delfirouting.php import=1 budget=0   (Langlaeufer ohne Limit,
 *                                                   nohup, DODP-Vollbau)
 *         php delfirouting.php probe=1
 *
 * Datensaetze:
 *   - Soll (primaer): DODP-Zip, gesucht in delfidata/ und delfidata/dfrouting/
 *     (neben bzw. unterhalb dieses Skripts), ../citysurfers/delfidata/ und
 *     /var/www/pixcaelium/apps/citysurfers/delfidata/
 *     (Dateiname mit gesamt/deutschland/fahrplan/dodp); 21 Tage nach mtime
 *     frisch. Ergebnis: delfidata/dfrouting/idx/dodp_<day0>/ mit tripday.bin
 *     (16-B-Bitmaske je Trip, Tage day0+0..127) und den Profilen;
 *     Neubau bei neuem Zip.
 *   - Soll (Failsafe): download.gtfs.de free-Feeds (fv/rv/nv, taeglich, CC-BY),
 *     Tagesindex delfidata/dfrouting/idx/<yyyymmdd>/ mit nur heute aktiven
 *     Services.
 *   - Echtzeit: realtime.gtfs.de/realtime-free.pb (TripUpdates; nur free-Modus,
 *     da die RT-IDs zu den free-Feeds passen)
 *
 * Verzeichnis: <skript>/delfidata/dfrouting/   (eigener Bereich im delfidata-
 *   Ordner von delfiproxy.php; getrennt von dessen state.json/overlay.json.gz/
 *   pool.pb/bk/-Cache; die .htaccess-Sperre von delfidata deckt den Unterordner
 *   mit ab)
 *   zips/            heruntergeladene latest.zip je Feed (Cache 20 h, free-Modus)
 *   rt_sim.pb        Echtzeit-Cache (60 s)
 *   state.json       Build-Status (atomar), Phasen: tabs/stimes1/stimes2/
 *                    stimes3/trans/fin
 *   idx/<yyyymmdd>/  Tagesindex:
 *     stops.tsv   nr \t stop_id \t name \t lat \t lon \t parent_station
 *     trips.tsv   nr \t trip_id \t route_id
 *     routes.tsv  route_id \t line \t type
 *     nms.bin/nidx.bin   Stopnamen (u32 off, u16 len, 2 pad = 8 B je Stop)
 *     sll.bin            Stop-Koordinaten (2x float32 LE = 8 B je Stop)
 *     trline.bin         Linienlabel je Trip (32 B, NUL-gepaddet)
 *     gridb_<la>_<lo>.tsv Zubringer-Grid 0,1 Grad (la/lo = floor(lat*10))
 *     dprof_<n>.bin/didx_<n>.bin  Abfahrtsprofile je Stop
 *         Block: [count u32][count x (dep u16, tripNr u32, seq u16) = 8 B]
 *         Slot didx (stopNr>>8): Offset u64
 *     tprof_<n>.bin/tidx_<n>.bin  Fahrtprofile je Trip
 *         Block: [stops u16][stops x (seq u16, dep u16, arr u16, stopNr u32) = 10 B]
 *         Slot tidx (tripNr>>8): Offset u64
 *     trans_<n>.bin/trx_<n>.bin   Parent-Transfers
 *         Block: [count u16][count x (stopNr u32, mins u8) = 5 B]
 *         Slot trx (stopNr>>8): Offset u64
 *
 * Datenformate sind identisch zum Python-Referenzsimulator (dfrsim.py),
 * PHP pack:route N/V/n/v/P/g == Python struct '>I'/'<I'/'<H'/'<Q'/'<f'.
 */

const DFR_V          = 'delfirouting 0.2.16';
const DFR_TT_URL     = '';            /* DODP opendata-oepnv.de-Link (spaeter) */
const DFR_RT_URL     = 'https://realtime.gtfs.de/realtime-free.pb';
const DFR_DODP_FRESH = 1814400;       /* DODP-Zip zaehlt 21 Tage als frisch */
const DFR_SVC_DAYS   = 128;           /* Mehr-Tages-Bitmaske: 16 Bytes je Trip */
const DFR_FEEDS      = [
    'fv' => 'https://download.gtfs.de/germanfv_free/latest.zip',
    'rv' => 'https://download.gtfs.de/germanrv_free/latest.zip',
    'nv' => 'https://download.gtfs.de/germannv_free/latest.zip',
];
const DFR_STAT_TTL  = 71400;   /* Sekunden: Index gilt ~19,8 h */
const DFR_BUDGET_S  = 18;      /* warm: Budget einer Bau-Runde */
const DFR_IMPORT_S  = 900;     /* import: Budget eines Cron-Slots */
const DFR_TIMEOUT_S = 25;      /* harte Obergrenze je Routing-Anfrage */
const DFR_TRANS_M   = 600;     /* Geo-Transferradius Fussweg (m) */
const DFR_TRANS_MAX = 14;      /* max. naechste Transferpartner je Stop */
const DFR_ROUNDS    = 9;       /* Wellen der Routing-Engine (v0.2.13: +1 f. Ferne-Frontier) */
const DFR_SCAN_MIN  = 120;     /* Abfahrtsfenster je Stop (Minuten; v0.2.14: war 240, Uhrzeit des Zeitbudgets) */
const DFR_RESULTS   = 6;       /* max. Verbindungen */
const DFR_MARK_CAP  = 1500;    /* max. markierte Stops je Welle (frueheste tau) */
const DFR_MARK_TAIL = 600;     /* v0.2.13: Ferne-Frontier: zusaetzlich die SPAETESTEN tau */
const DFR_KEEP_SLACK = 60;    /* v0.2.14: Ziel-Pruning: tau >= bestFin+X wird nicht mehr gescannt */
const DFR_STAG_ROUNDS = 2;   /* v0.2.14: Abbruch nach X Wellen ohne bestFinish-Verbesserung (ab Welle 4) */
const DFR_DEP_CAP    = 15;   /* v0.2.15: je Stop je Welle max. so viele erste Abfahrten scannen (RAPTOR-Naeherung) */
const DFR_EARLY_S    = 12;   /* v0.2.16: nach erster Zielankunft Suche spaetestens nach so vielen Sekunden abbrechen */
const DFR_SCAN_WIDE  = 360;  /* v0.2.16: Ziel-Hop-Wiederholung: weites Abfahrtsfenster (Minuten) */
const DFR_DEP_WIDE   = 45;   /* v0.2.16: Ziel-Hop-Wiederholung: Abfahrts-Deckel je Stop je Welle */
const DFR_ROUNDS_WIDE= 12;   /* v0.2.16: Ziel-Hop-Wiederholung: Wellenzahl der Wiederholung */
const DFR_POOL_TTL  = 90;       /* v0.2.13: DELFI-Pool-Strom-Flussalter (s) */
const DFR_POOL_MIN  = 400000;      /* v0.2.13: Plausibilitaet Pool-Strom (~4..40 MB) */
const DFR_POOL_MAX  = 40000000;
const DFR_HEUR_WIN  = 240;         /* v0.2.13: Heuristik-Fenster geplante Passage (s) */
const DFR_GRID_FH   = 512;     /* max. offene Grid-Zellen-Dateien (ulimit-Schutz) */
const DFR_MT_TTL    = 60;      /* Echtzeit-Cache (Sekunden) */
const DFR_ACC_MAX   = 32;      /* Zubringerstops je Endpunkt */
const DFR_OSRM_TTL  = 2592000;      /* v0.2.12: OSRM-Dauer-Cache in s (30 Tage) */
const DFR_OSRM_MS   = 6000.0;       /* v0.2.12: OSRM-Gesamtbudget je Anfrage (ms) */
const DFR_OSRM_MAXM = 12000;        /* v0.2.12: Luftlinien-Grenze fuer OSRM-Routen (m) */
const DFR_OSRM_MAXF = 4000;         /* v0.2.12: OSRM-Cache-Dateien, danach Trimmen */
const DFR_OSRM_TRIM = 1200;         /* v0.2.12: Zahl aeltester Dateien beim Trimmen */
const DFR_VM_KMH    = ['bike' => 17.0, 'ebike' => 22.0, 'scoot' => 18.0];
const DFR_MEM       = '512M';

/** Basisverzeichnis: eigener Bereich INNERHALB des delfidata-Ordners von
 *  delfiproxy.php (keine Kollision mit dessen state.json/pool.pb/bk/...);
 *  das DODP-Zip selbst bleibt direkt in ../delfidata/ liegen. */
function dfr_base(): string { return __DIR__ . '/delfidata/dfrouting'; }
function dfr_idxdir(string $day): string { return dfr_base() . '/idx/' . $day; }
function dfr_tmpdir(string $day): string { return dfr_base() . '/tmp/' . $day; }
function dfr_zipdir(): string { return dfr_base() . '/zips'; }

/* ---------------------------------------------------------------- Zeit */

function dfr_day_zo(): string {
    $d = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    return $d->format('Ymd');
}
function dfr_hm2min(string $s): ?int {
    if ($s === '' || strlen($s) < 4) return null;
    $p = explode(':', $s);
    if (count($p) < 2) return null;
    $h = (int)$p[0]; $m = (int)$p[1];
    if ($h < 0 || $h > 47 || $m < 0 || $m > 59) return null;
    $t = $h * 60 + $m;
    if ($t > 65535) return null;
    return $t;
}
function dfr_min2hm(int $m): string {
    $m &= 0xFFFF;
    return sprintf('%02d:%02d', intdiv($m, 60) % 48, $m % 60);
}
function dfr_dist_m(float $la1, float $lo1, float $la2, float $lo2): float {
    $rla = deg2rad($la2 - $la1); $rlo = deg2rad($lo2 - $lo1);
    $a = sin($rla / 2) ** 2 +
        cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($rlo / 2) ** 2;
    return 12742000.0 * asin(min(1.0, sqrt($a)));
}
function dfr_walkmin(float $m): int {
    return max(1, (int)ceil($m * 1.3 / 5000.0 * 60.0));
}

/** v0.2.12: Wellen-Dauer eines Zubringer-/Umstiegslaufs im Modus-Tempo.
 *  walk: Gehzeit unveraendert; bike/ebike/scoot: aus Metern (Geo-Umstieg:
 *  Luftlinie x 1,3) auf kmh plus 2 min Handhabung; Parent-Paare aus
 *  transfers.txt kommen OHNE Meter und werden aus der Gehzeit skaliert. */
function dfr_vm_wave(string $vm, int $walkMin, ?float $meters): int {
    if ($vm === 'walk') return $walkMin;
    $kmh = DFR_VM_KMH[$vm] ?? 17.0;
    if ($meters !== null && $meters > 0.0)
        $m = (int)ceil($meters * 1.3 / ($kmh * 1000.0) * 60.0) + 2;
    else
        $m = (int)ceil($walkMin * 5.0 / $kmh) + 2;
    return max(1, $m);
}

/** v0.2.12: OSRM-Routing eines Laufs (FOSSGIS-Server, Profil foot/bike).
 *  Rueckgabe [dist_m, dur_min] oder null bei Fehler/Distanz. Datei-Cache
 *  30 Tage (1-m-Raster); statischer Breaker: 3 Fehlschlaege oder 6 s
 *  kumuliert -> fuer den Rest DER ANFRAGE Luftlinien-Schaetzung. */
function dfr_osrm(string $prof, float $la1, float $lo1,
    float $la2, float $lo2): ?array {
    static $fails = 0; static $spend = 0.0;
    if ($fails >= 3 || $spend >= DFR_OSRM_MS) return null;
    $k = md5(sprintf('%s|%.5f|%.5f|%.5f|%.5f', $prof, $la1, $lo1, $la2, $lo2));
    $cf = dfr_base() . '/osrm/' . $k . '.json';
    if (is_file($cf) && time() - (int)filemtime($cf) < DFR_OSRM_TTL) {
        $j = json_decode((string)@file_get_contents($cf), true);
        if (is_array($j) && ($j[0] ?? 0) > 0) return $j;   /* [dist_m, dur_min] */
    }
    if (dfr_dist_m($la1, $lo1, $la2, $lo2) > DFR_OSRM_MAXM) return null;
    $t0 = microtime(true);
    $url = 'https://routing.openstreetmap.de/routed-' . $prof .
        '/route/v1/' . $prof . '/' .
        sprintf('%.5f,%.5f;%.5f,%.5f', $lo1, $la1, $lo2, $la2) .
        '?overview=false&steps=false&alternatives=false';
    $ctx = stream_context_create(['http' => ['timeout' => 5,
        'user_agent' => 'pixcaelium-delfirouting/0.2']]);
    $raw = @file_get_contents($url, false, $ctx);
    $spend += (microtime(true) - $t0) * 1000.0;
    if (!is_string($raw) || strlen($raw) < 30) { $fails++; return null; }
    $j = json_decode($raw, true);
    if (!is_array($j) || ($j['code'] ?? '') !== 'Ok' ||
        !isset($j['routes'][0]['distance'], $j['routes'][0]['duration'])) {
        $fails++; return null;
    }
    $res = [(int)round((float)$j['routes'][0]['distance']),
        max(1, (int)ceil((float)$j['routes'][0]['duration'] / 60.0))];
    if (!is_dir(dirname($cf))) @mkdir(dirname($cf), 0755, true);
    @file_put_contents($cf, json_encode($res));
    if (random_int(0, 99) === 0) {                /* Cache-Groesse begrenzen */
        $fs = glob(dirname($cf) . '/*.json') ?: [];
        if (count($fs) > DFR_OSRM_MAXF) {
            usort($fs, fn($x, $y) => (int)@filemtime($x) <=> (int)@filemtime($y));
            for ($i = 0; $i < DFR_OSRM_TRIM && $i < count($fs); $i++)
                @unlink($fs[$i]);
        }
    }
    return $res;
}

/** v0.2.12: Dauer/Distanz eines zusammengelegten Zubringer-/Ziellaufs je
 *  Modus: walk = OSRM-Fuss-Dauer; bike/ebike/scoot = OSRM-bike-Distanz zur
 *  eigenen Geschwindigkeit plus 2 min Handhabung; Fallback Luftlinie x 1,3.
 *  Rueckgabe [dist_m, mins]. */
function dfr_leg_calc(string $vm, float $la1, float $lo1,
    float $la2, float $lo2): array {
    $os = dfr_osrm($vm === 'walk' ? 'foot' : 'bike', $la1, $lo1, $la2, $lo2);
    if ($os !== null) {
        if ($vm === 'walk') return $os;
        $kmh = DFR_VM_KMH[$vm] ?? 17.0;
        return [$os[0], max(1, (int)ceil($os[0] / ($kmh * 1000.0) * 60.0)) + 2];
    }
    $hv = dfr_dist_m($la1, $lo1, $la2, $lo2);
    if ($vm === 'walk')
        return [(int)round($hv), max(1, (int)ceil($hv * 1.3 / 5000.0 * 60.0))];
    $kmh = DFR_VM_KMH[$vm] ?? 17.0;
    return [(int)round($hv),
        max(1, (int)ceil($hv * 1.3 / ($kmh * 1000.0) * 60.0)) + 2];
}

/* --------------------------------------------------- State (atomar) */

function dfr_state_get(): array {
    $p = dfr_base() . '/state.json';
    if (!is_file($p)) return [];
    $j = json_decode((string)file_get_contents($p), true);
    return is_array($j) ? $j : [];
}
function dfr_state_put(array $st): void {
    if (!is_dir(dfr_base())) mkdir(dfr_base(), 0755, true);
    $p = dfr_base() . '/state.json';
    $q = $p . '.tmp';
    file_put_contents($q, json_encode($st), LOCK_EX);
    rename($q, $p);
}

/* ------------------------------------------------------------ CSV-Zeile */

function dfr_csv(string $ln): array {
    if (strpos($ln, '"') === false) return explode(',', $ln);
    $out = []; $cur = ''; $q = false; $n = strlen($ln);
    for ($i = 0; $i < $n; $i++) {
        $c = $ln[$i];
        if ($q) {
            if ($c === '"') $q = false; else $cur .= $c;
        } elseif ($c === '"') { $q = true;
        } elseif ($c === ',') { $out[] = $cur; $cur = '';
        } else { $cur .= $c; }
    }
    $out[] = $cur;
    return $out;
}
function dfr_col(array $f, ?int $i): string {
    if ($i === null || $i < 0 || $i >= count($f)) return '';
    return trim($f[$i]);
}
/** Header-Aliase: free-Feeds benennen teilweise ohne route_-Praefix. */
function dfr_hmap(string $ln, array $want): array {
    $cols = dfr_csv(trim($ln));
    $mp = [];
    foreach ($cols as $i => $c) $mp[trim($c)] = $i;
    $out = [];
    foreach ($want as $w) $out[$w] = $mp[$w] ?? null;
    return $out;
}

/* -------------------------------------------------------------- Logs */

function dfr_log(string $s): void {
    file_put_contents(dfr_base() . '/build.log', date('c') . ' ' . $s . "\n", FILE_APPEND);
}

/* ============================================================ IMPORT */

/** Zip als Zeilen-Generator; ZipArchive ist auf Netcup vorhanden.
 *  Member wird auch in Unterordnern gefunden (DODP-Zips koennen einen
 *  Praefix-Pfad haben, z. B. "fahrplan/stops.txt"). */
function dfr_zip_lines($zipPath, string $member): ?array {
    static $zCache = [];
    static $mCache = [];
    if (!class_exists('ZipArchive')) return null;
    if (!array_key_exists($zipPath, $zCache)) {
        $z = new ZipArchive();
        if ($z->open($zipPath) !== true) { $zCache[$zipPath] = false; return null; }
        $zCache[$zipPath] = $z;
    }
    $z = $zCache[$zipPath];
    if (!is_object($z)) return null;
    $mk = $zipPath . '#' . $member;
    if (!array_key_exists($mk, $mCache)) {
        $full = null;
        if ($z->locateName($member) !== false) {
            $full = $member;
        } else {
            $suf = '/' . $member;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $nm = $z->getNameIndex($i);
                if (is_string($nm) &&
                    (substr($nm, -strlen($suf)) === $suf || $nm === $member)) {
                    $full = $nm; break;
                }
            }
        }
        $mCache[$mk] = $full;
    }
    $full = $mCache[$mk];
    if ($full === null) return null;
    $strm = $z->getStream($full);
    if ($strm === false) return null;
    return [$z, $strm];
}

/** DODP-Gesamtdeutschland-Zip suchen (neuestes gewinnt).
 *  Rueckgabe: [Pfad, mtime] oder null. */
function dfr_dodp_find(): ?array {
    $cands = [];
    $paths = [
        dfr_base(),
        __DIR__ . '/delfidata',
        __DIR__ . '/../citysurfers/delfidata',
        '/var/www/pixcaelium/apps/citysurfers/delfidata',
    ];
    foreach ($paths as $p) {
        if (!is_dir($p)) continue;
        $fs = @scandir($p);
        if ($fs === false) continue;
        foreach ($fs as $f) {
            if (strlen($f) < 8 || substr($f, -4) !== '.zip') continue;
            $fp = $p . '/' . $f;
            if (!is_file($fp)) continue;
            if (!preg_match('/dodp|gesamt|deutschland|fahrplan/i', $f)) continue;
            if (!preg_match('/gesamt|deutschland/i', $f)) continue; /* Schutz vor Streifern */
            $cands[$fp] = (int)filemtime($fp);
        }
    }
    if (!$cands) return null;
    arsort($cands);
    $fp = array_key_first($cands);
    return [$fp, $cands[$fp]];
}

/** Feed-Zip je.feed laden (Cache 20 h, atomic rename). */
function dfr_feed_zip(string $feed, string $url, int $budgetEnd): ?string {
    $dir = dfr_zipdir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $p = $dir . '/' . $feed . '_latest.zip';
    if (is_file($p) && time() - (int)filemtime($p) < 72000) return $p;
    $q = $p . '.part';
    $ok = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($q, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => max(30, $budgetEnd - time()),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'pixcaelium-delfirouting/0.1',
        ]);
        $ok = curl_exec($ch) === true;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if ($ok && $code !== 200) $ok = false;
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => max(30, $budgetEnd - time()),
            'user_agent' => 'pixcaelium-delfirouting/0.1',
        ]]);
        $ok = (bool)@file_put_contents($q, fopen($url, 'rb', false, $ctx));
    }
    if (!$ok || !is_file($q) || filesize($q) < 500) {
        @unlink($q);
        return is_file($p) ? $p : null;   /* Alter Stand bleibt nutzbar */
    }
    rename($q, $p);
    return $p;
}

/** Aktive Services des Tages (calendar + calendar_dates). */
function dfr_svc_active($zipPath, string $day): ?array {
    $got = dfr_zip_lines($zipPath, 'calendar.txt');
    $act = [];
    if ($got !== null) {
        [, $fh] = $got;
        $wd = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'][
            (int)(new DateTimeImmutable($day))->format('N') - 1];
        $hd = null; $first = true;
        while (($ln = fgets($fh, 65536)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['service_id','monday','tuesday','wednesday',
                    'thursday','friday','saturday','sunday','start_date','end_date']);
                continue; }
            $f = dfr_csv($ln);
            $si = dfr_col($f, $hd['service_id']);
            if ($si === '') continue;
            $st = dfr_col($f, $hd['start_date']); $en = dfr_col($f, $hd['end_date']);
            if ($st !== '' && $day < $st) continue;
            if ($en !== '' && $day > $en) continue;
            if (dfr_col($f, $hd[$wd]) === '1') $act[$si] = 1;
        }
        fclose($fh);
    }
    $got = dfr_zip_lines($zipPath, 'calendar_dates.txt');
    if ($got !== null) {
        [, $fh] = $got;
        $hd = null; $first = true;
        while (($ln = fgets($fh, 65536)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['service_id','date','exception_type']);
                continue; }
            $f = dfr_csv($ln);
            $si = dfr_col($f, $hd['service_id']); $dt = dfr_col($f, $hd['date']);
            if ($si === '' || $dt !== $day) continue;
            if (dfr_col($f, $hd['exception_type']) === '1') $act[$si] = 1;
            elseif (dfr_col($f, $hd['exception_type']) === '2') unset($act[$si]);
        }
        fclose($fh);
    }
    return $act;
}

/** stop-/trip-ID-Maps aus bereits geschriebenen TSVs laden (Resume + Dedupe). */
function dfr_maps_reload(string $idir, array &$stopmap, array &$tripmap): void {
    $stopmap = []; $tripmap = [];
    $p = $idir . '/stops.tsv';
    if (is_file($p)) {
        $fh = fopen($p, 'rb');
        $i = 0;
        if ($fh !== false) {   /* v0.2.10: fopen-Schutz */
            while (($ln = fgets($fh, 4096)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"));
                if (count($t) >= 2) $stopmap[$t[1]] = $i;
                $i++;
            }
            fclose($fh);
        }
    }
    $p = $idir . '/trips.tsv';
    if (is_file($p)) {
        $fh = fopen($p, 'rb');
        $i = 0;
        if ($fh !== false) {   /* v0.2.10: fopen-Schutz */
            while (($ln = fgets($fh, 4096)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"));
                if (count($t) >= 2) $tripmap[$t[1]] = $i;
                $i++;
            }
            fclose($fh);
        }
    }
}

/** Grid-Zellen-Schreiber: haelt max. DFR_GRID_FH Handles offen, sonst
 *  schliesst er alle und oeffnet neu (GTFS-Stops sind regional gebuundelt,
 *  deshalb wenige, lokale Batches). 0.2.2-Fix gegen 'Too many open files'. */
function dfr_grid_put(array &$fhC, string $idir, string $k, string $line): void {
    if (!isset($fhC[$k])) {
        if (count($fhC) >= DFR_GRID_FH) {
            foreach ($fhC as $h) { if (is_resource($h)) fclose($h); }
            $fhC = [];
        }
        $fhC[$k] = @fopen($idir . '/gridb_' . $k . '.tsv', 'ab');
    }
    if (is_resource($fhC[$k])) fwrite($fhC[$k], $line);
}

/** Die Phasen tabs/stimes1/stimes2/stimes3/trans/fin des Tagesindex-Baus. */
function dfr_build(int $budgetS): array {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'code' => 'nozip',
            'msg' => 'PHP-ZipArchive fehlt; Extension zip wird benoetigt.'];
    }
    /* v0.2.3: Bau-Lock: GENU EIN bauender Prozess (Import-Langlaeufer ODER
     * warm-Runde). Ohne Lock schrieb ein warm=1 waehrend des laufenden Imports
     * mit denselben Resume-Zaehnern in diesselben Dateien. Das Lock gilt fuer
     * die Lebensdauer des Prozesses (auch bei kill sofort frei, kein Rest). */
    if (!is_dir(dfr_base())) @mkdir(dfr_base(), 0755, true);
    $lf = @fopen(dfr_base() . '/build.lock', 'c');
    if ($lf === false || !flock($lf, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'code' => 'busy',
            'msg' => 'Ein Bau-Prozess (Import/warm) ist bereits aktiv; ohne Aenderungen beendet.'];
    }
    register_shutdown_function(function () use ($lf) { @flock($lf, LOCK_UN); });
    if ($budgetS > 0) @set_time_limit($budgetS + 30); else @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    $tEnd = $budgetS > 0 ? time() + $budgetS : PHP_INT_MAX;
    $day = dfr_day_zo();
    $st = dfr_state_get();

    /* DODP-Vollindex hat Vorrang; die free-Feeds sind der Failsafe */
    $dodp = dfr_dodp_find();
    if ($dodp !== null && time() - $dodp[1] <= DFR_DODP_FRESH) {
        return dfr_build_dodp($dodp[0], $dodp[1], $tEnd);
    }

    if (($st['phase'] ?? '') === 'done' && ($st['src'] ?? 'free') === 'free'
        && ($st['day'] ?? '') === $day) {
        return ['ok' => true, 'msg' => 'Index ' . $day . ' bereits fertig.'];
    }
    if (($st['src'] ?? 'free') !== 'free' || ($st['day'] ?? '') !== $day) {
        $st = ['phase' => 'build', 'src' => 'free', 'day' => $day, 'tabs' => [],
               'stl' => [], 'st2next' => 0, 'st3next' => 0, 'transnext' => 0];
    }
    $idir = dfr_idxdir($day); $tdir = dfr_tmpdir($day);
    if (!is_dir($idir)) mkdir($idir, 0755, true);
    if (!is_dir($tdir)) mkdir($tdir, 0755, true);

    $stopmap = []; $tripmap = [];
    dfr_maps_reload($idir, $stopmap, $tripmap);
    $rlines = [];
    if (is_file($idir . '/routes.tsv')) {
        foreach (file($idir . '/routes.tsv', FILE_IGNORE_NEW_LINES) as $ln) {
            $t = explode("\t", $ln);
            if (count($t) >= 2) $rlines[$t[0]] = true;
        }
    }

    /* ---- Phase tabs: stops, routes, trips je Feed anhaengen ---- */
    foreach (DFR_FEEDS as $feed => $url) {
        if (in_array($feed, $st['tabs'], true)) continue;
        $t0 = time();
        $zip = dfr_feed_zip($feed, $url, $tEnd);
        if ($zip === null) { dfr_log("tabs $feed: Zip-Fehler"); continue; }
        $svc = dfr_svc_active($zip, $day);
        if ($svc === null) { dfr_log("tabs $feed: calendar lesbar? nein"); continue; }
        $fStops = fopen($idir . '/stops.tsv', 'ab');
        $fNms   = fopen($idir . '/nms.bin', 'ab');
        $fNidx  = fopen($idir . '/nidx.bin', 'ab');
        $fLL    = fopen($idir . '/sll.bin', 'ab');
        $gridFh = []; $nNew = 0;
        $got = dfr_zip_lines($zip, 'stops.txt');
        if ($got !== null) {
            [, $fh] = $got; $hd = null; $first = true;
            while (($ln = fgets($fh, 65536)) !== false) {
                $ln = rtrim($ln, "\r\n");
                if ($ln === '') continue;
                if ($first) { $first = false;
                    $hd = dfr_hmap($ln, ['stop_id','stop_name','stop_lat','stop_lon',
                        'parent_station']);
                    continue; }
                $f = dfr_csv($ln);
                $sid = dfr_col($f, $hd['stop_id']);
                if ($sid === '' || isset($stopmap[$sid])) continue;
                $la = dfr_col($f, $hd['stop_lat']); $lo = dfr_col($f, $hd['stop_lon']);
                if ($la === '' || $lo === '') continue;
                $laF = (float)$la; $loF = (float)$lo;
                if ($laF < 47 || $laF > 55.6 || $loF < 5.8 || $loF > 15.2) continue;
                $pa = dfr_col($f, $hd['parent_station']);
                $nm = trim(dfr_col($f, $hd['stop_name']));
                if ($nm === '') $nm = $sid;
                $nm = str_replace("\t", ' ', mb_substr($nm, 0, 180));
                $nr = count($stopmap);
                fwrite($fStops, $nr . "\t" . $sid . "\t" . $nm . "\t" .
                    $laF . "\t" . $loF . "\t" . $pa . "\n");
                $off = ftell($fNms);
                $nb = mb_substr($nm, 0, 180);
                fwrite($fNms, $nb);
                $lbnb = strlen($nb);
                fwrite($fNidx, pack('Vvxx', $off, $lbnb <= 65535 ? $lbnb : 65535));
                fwrite($fLL, pack('gg', $laF, $loF));
                $k = ((int)floor($laF * 10)) . '_' . ((int)floor($loF * 10));
                dfr_grid_put($gridFh, $idir, $k,
                    $nr . "\t" . $nm . "\t" . $laF . "\t" . $loF . "\n");
                $stopmap[$sid] = $nr;
                $nNew++;
            }
            fclose($fh);
        }
        foreach ($gridFh as $h) { if (is_resource($h)) fclose($h); }
        fclose($fStops); fclose($fNms); fclose($fNidx); fclose($fLL);
        /* routes */
        $fRoutes = fopen($idir . '/routes.tsv', 'ab');
        $rlines2 = $rlines;
        $got = dfr_zip_lines($zip, 'routes.txt');
        if ($got !== null) {
            [, $fh] = $got; $hd = null; $first = true;
            while (($ln = fgets($fh, 65536)) !== false) {
                $ln = rtrim($ln, "\r\n");
                if ($ln === '') continue;
                if ($first) { $first = false;
                    $hd = dfr_hmap($ln, ['route_id','long_name','short_name','route_type',
                        'route_long_name','route_short_name']);
                    continue; }
                $f = dfr_csv($ln);
                $ri = dfr_col($f, $hd['route_id']);
                if ($ri === '' || isset($rlines2[$ri])) continue;
                $sn = dfr_col($f, $hd['short_name']);
                if ($sn === '') $sn = dfr_col($f, $hd['route_short_name']);
                $l2n = dfr_col($f, $hd['long_name']);
                if ($l2n === '') $l2n = dfr_col($f, $hd['route_long_name']);
                $ty = dfr_col($f, $hd['route_type']); if ($ty === '') $ty = '3';
                $line = $sn; if ($line === '') $line = $l2n;
                $line = str_replace("\t", ' ', mb_substr($line, 0, 60));
                fwrite($fRoutes, $ri . "\t" . $line . "\t" . $ty . "\n");
                $rlines2[$ri] = true;
            }
            fclose($fh);
        }
        fclose($fRoutes);
        /* trips (nur aktive Services) */
        $fTrips = fopen($idir . '/trips.tsv', 'ab');
        $fTrline = fopen($idir . '/trline.bin', 'ab');
        $nT = 0;
        $rlmap = [];
        foreach (file($idir . '/routes.tsv', FILE_IGNORE_NEW_LINES) as $ln) {
            $t = explode("\t", $ln);
            if (count($t) >= 2) $rlmap[$t[0]] = $t[1];
        }
        $trlineCache = [];
        $got = dfr_zip_lines($zip, 'trips.txt');
        if ($got !== null) {
            [, $fh] = $got; $hd = null; $first = true;
            while (($ln = fgets($fh, 65536)) !== false) {
                $ln = rtrim($ln, "\r\n");
                if ($ln === '') continue;
                if ($first) { $first = false;
                    $hd = dfr_hmap($ln, ['trip_id','service_id','route_id']);
                    continue; }
                $f = dfr_csv($ln);
                $ti = dfr_col($f, $hd['trip_id']);
                if ($ti === '' || isset($tripmap[$ti])) continue;
                $si = dfr_col($f, $hd['service_id']);
                if ($si === '' || !isset($svc[$si])) continue;
                $ri = dfr_col($f, $hd['route_id']);
                $nr = count($tripmap);
                fwrite($fTrips, $nr . "\t" . $ti . "\t" . $ri . "\n");
                if (!isset($trlineCache[$ri])) {
                    $lb = isset($rlmap[$ri]) ? $rlmap[$ri] : '';
                    $b = substr($lb, 0, 31);
                    $trlineCache[$ri] = $b . str_repeat("\0", 32 - strlen($b));
                }
                fwrite($fTrline, $trlineCache[$ri]);
                $tripmap[$ti] = $nr;
                $nT++;
            }
            fclose($fh);
        }
        fclose($fTrips); fclose($fTrline);
        $st['tabs'][] = $feed;
        dfr_state_put($st);
        dfr_log(sprintf('tabs %s: +%d stops +%d trips (%.0fs)', $feed, $nNew, $nT, time() - $t0));
        if (time() > $tEnd) return ['ok' => true, 'phase' => 'tabs', 'budget' => true,
            'msg' => 'Budget nach tabs/' . $feed . ', Resume noetig.'];
    }

    /* Map-Reload nach tabs (auch bei Resume der spaeteren Phasen) */
    dfr_maps_reload($idir, $stopmap, $tripmap);

    /* ---- Phase stimes1: stop_times je Feed in 256 TMP-Buckets ---- */
    $buckets = null;
    foreach (DFR_FEEDS as $feed => $url) {
        if (!isset($st['stl'][$feed])) $st['stl'][$feed] = 0;
    }
    foreach (DFR_FEEDS as $feed => $url) {
        if (($st['stl'][$feed] ?? -2) === -2) continue;
        $t0 = time();
        $zip = dfr_zip_zippath($feed);
        $zip = $zip ?? dfr_feed_zip($feed, $url, $tEnd);
        if ($zip === null) { dfr_log("stimes1 $feed: Zip-Fehler"); continue; }
        $done = (int)$st['stl'][$feed];
        $nr = 0; $miss = 0;
        $got = dfr_zip_lines($zip, 'stop_times.txt');
        if ($got === null) { dfr_log("stimes1 $feed: keine stop_times"); continue; }
        [, $fh] = $got;
        $hd = null; $first = true;
        while (($ln = fgets($fh, 1024)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($nr < $done) { $nr++; continue; }        /* Zeilen-Skip-Resume */
            if ($ln === '') { $nr++; continue; }
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['trip_id','arrival_time','arrival',
                    'departure_time','departure','stop_id','stop_sequence']);
                $nr++; continue; }
            $f = dfr_csv($ln);
            $ti = dfr_col($f, $hd['trip_id']);
            $sid = dfr_col($f, $hd['stop_id']);
            if ($ti === '' || $sid === '' ||
                !isset($tripmap[$ti]) || !isset($stopmap[$sid])) { $miss++; }
            else {
                $iDp = $hd['departure_time']; if ($iDp === null) $iDp = $hd['departure'];
                $iAr = $hd['arrival_time'];   if ($iAr === null) $iAr = $hd['arrival'];
                $dp = dfr_col($f, $iDp);
                $ar = dfr_col($f, $iAr); if ($ar === '') $ar = $dp;
                $dep = dfr_hm2min($dp); $arr = dfr_hm2min($ar);
                $sq = (int)dfr_col($f, $hd['stop_sequence']);
                if ($dep === null || $arr === null || $sq < 0 || $sq > 65535) $miss++;
                else {
                    $sp = $stopmap[$sid];
                    $rec = pack('VvvVv', $sp, $dep, $arr, $tripmap[$ti], $sq);
                    $b = $sp & 255;
                    if ($buckets === null) $buckets = [];
                    if (!isset($buckets[$b]))
                        $buckets[$b] = fopen($tdir . sprintf('/b%03d.bin', $b), 'ab');
                    fwrite($buckets[$b], $rec);
                }
            }
            $nr++;
            if (($nr & 0xFFFFF) === 0) {
                $st['stl'][$feed] = $nr;
                dfr_state_put($st);
                if (time() > $tEnd) {
                    foreach ($buckets ?? [] as $h) fclose($h);
                    dfr_log("stimes1 $feed: Budget bei Zeile $nr");
                    return ['ok' => true, 'phase' => 'stimes1', 'budget' => true,
                        'msg' => "Budget in stimes1/$feed bei Zeile $nr."];
                }
            }
        }
        foreach ($buckets ?? [] as $h) fclose($h);
        $buckets = null;
        $st['stl'][$feed] = -2;
        dfr_state_put($st);
        dfr_log(sprintf('stimes1 %s: fertig (%d Zeilen, %d verworfen, %.0fs)',
            $feed, $nr, $miss, time() - $t0));
        if (time() > $tEnd)
            return ['ok' => true, 'phase' => 'stimes1', 'budget' => true,
                'msg' => 'Budget nach stimes1/' . $feed . '.'];
    }

    /* ---- Bucket-Phasen (gemeinsam fuer free und DODP) ---- */
    $bres = dfr_phase_buckets($idir, $tdir, $st, $tEnd);
    if ($bres !== null) return $bres;
    $ntrips = (int)($st['b_trips'] ?? 0);
    $npairs = (int)($st['b_pairs'] ?? 0);

    /* ---- fin: TMP aufraeumen, State done ---- */
    if (is_dir($tdir)) {
        foreach (scandir($tdir) as $f2) if ($f2 !== '.' && $f2 !== '..')
            @unlink($tdir . '/' . $f2);
        @rmdir($tdir);
    }
    dfr_state_put(['src' => 'free', 'phase' => 'done', 'day' => $day, 'ts' => time(),
        'trips' => $ntrips, 'pairs' => $npairs]);
    dfr_log("fin: Index $day fertig ($ntrips Profile, $npairs Transferpaare)");
    return ['ok' => true, 'phase' => 'fin', 'day' => $day,
        'trips' => $ntrips, 'pairs' => $npairs];
}

/** Gemeinsame Bucket-Phasen stimes2/stimes3/trans (free UND DODP).
 *  Rueckgabe null = alle Phasen fertig (Zaehler in $st['b_trips']/['b_pairs']),
 *  sonst Budget-Info-Array. */
function dfr_phase_buckets(string $idir, string $tdir, array &$st, int $tEnd): ?array {
    if (!isset($st['b_trips'])) { $st['b_trips'] = 0; $st['b_pairs'] = 0; }
    /* ---- Phase stimes2: je Bucket sortieren -> dprof/didx + tp-Buckets ---- */
    $t0 = time();
    while ($st['st2next'] < 256) {
        if (time() > $tEnd) { dfr_state_put($st);
            return ['ok' => true, 'phase' => 'stimes2', 'budget' => true,
                'msg' => 'Budget in stimes2 bei ' . $st['st2next'] . '.']; }
        $n = (int)$st['st2next'];
        $bf = $tdir . sprintf('/b%03d.bin', $n);
        if (!is_file($bf) && is_file($idir . sprintf('/dprof_%03d.bin', $n))) {
            $st['st2next'] = $n + 1;                     /* Resume: schon fertig */
            continue;
        }
        $rows = [];
        if (is_file($bf)) {
            $fh = fopen($bf, 'rb');
            $left = (int)floor(filesize($bf) / 14);
            while ($left-- > 0) {
                $raw = fread($fh, 14);
                /* v0.2.6: 'vdep' statt '/dep'! In PHP ist 'd' der
                 * DOUBLE-Code (8 Byte), das alte Format parste V+d+v+V+v
                 * = 20 Byte statt 14: unpack false, alle Schluessel null,
                 * je Bucket blieb EINE Zeile uebrig (dprof nur 12 Byte). */
                $u = unpack('Vstop/vdep/varr/Vtrip/vseq', $raw);
                $key = pack('NnnNV', $u['stop'], $u['dep'], $u['seq'], $u['trip'], $u['arr']);
                $rows[$key] = $raw;
            }
            fclose($fh);
            unlink($bf);
        }
        $fpD = fopen($idir . sprintf('/dprof_%03d.bin', $n), 'wb');
        $fpI = fopen($idir . sprintf('/didx_%03d.bin', $n), 'wb');
        $tp = []; $curStop = -1; $curRek = ''; $curOff = 0; $curCount = 0;
        $keys = array_keys($rows);
        usort($keys, 'strcmp');
        foreach ($keys as $key) {
            $raw = $rows[$key];
            /* v0.2.6: siehe oben, unpack-Format korrigiert */
            $u = unpack('Vstop/vdep/varr/Vtrip/vseq', $raw);
            if ($u['stop'] !== $curStop) {
                if ($curCount > 0) {
                    fseek($fpI, ($curStop >> 8) * 8, SEEK_SET);
                    fwrite($fpI, pack('P', $curOff));
                    fwrite($fpD, pack('V', $curCount) . $curRek);
                }
                $curStop = $u['stop']; $curRek = ''; $curCount = 0;
                $curOff = ftell($fpD);
            }
            $curRek .= pack('vVv', $u['dep'], $u['trip'], $u['seq']);
            $curCount++;
            $tb = $u['trip'] & 255;
            if (!isset($tp[$tb]))
                $tp[$tb] = fopen($tdir . sprintf('/tp%03d.bin', $tb), 'ab');
            fwrite($tp[$tb], pack('VvvvV', $u['trip'], $u['seq'], $u['dep'], $u['arr'], $u['stop']));
        }
        if ($curCount > 0) {
            fseek($fpI, ($curStop >> 8) * 8, SEEK_SET);
            fwrite($fpI, pack('P', $curOff));
            fwrite($fpD, pack('V', $curCount) . $curRek);
        }
        fclose($fpD); fclose($fpI);
        foreach ($tp as $h) fclose($h);
        $st['st2next'] = $n + 1;
        if (time() - $t0 > 5) { dfr_state_put($st); $t0 = time(); }
    }

    /* ---- Phase stimes3: tp-Buckets -> tprof/tidx ---- */
    $t0 = time();
    while ($st['st3next'] < 256) {
        if (time() > $tEnd) { dfr_state_put($st);
            return ['ok' => true, 'phase' => 'stimes3', 'budget' => true,
                'msg' => 'Budget in stimes3 bei ' . $st['st3next'] . '.']; }
        $n = (int)$st['st3next'];
        $bf = $tdir . sprintf('/tp%03d.bin', $n);
        if (!is_file($bf) && is_file($idir . sprintf('/tprof_%03d.bin', $n))) {
            $st['st3next'] = $n + 1;                     /* Resume: schon fertig */
            continue;
        }
        $rows = [];
        if (is_file($bf)) {
            $fh = fopen($bf, 'rb');
            $left = (int)floor(filesize($bf) / 14);
            while ($left-- > 0) {
                $raw = fread($fh, 14);
                $u = unpack('Vtrip/vseq/vdep/varr/Vstop', $raw);
                $key = pack('Nn', $u['trip'], $u['seq']);
                $rows[$key] = [$u, $raw];
            }
            fclose($fh);
            unlink($bf);
        }
        $fpT = fopen($idir . sprintf('/tprof_%03d.bin', $n), 'wb');
        $fpX = fopen($idir . sprintf('/tidx_%03d.bin', $n), 'wb');
        $curTrip = -1; $curRek = ''; $curOff = 0; $curCount = 0;
        $keys = array_keys($rows);
        usort($keys, 'strcmp');
        foreach ($keys as $key) {
            [$u] = $rows[$key];
            if ($u['trip'] !== $curTrip) {
                if ($curCount > 0) {
                    fseek($fpX, ($curTrip >> 8) * 8, SEEK_SET);
                    fwrite($fpX, pack('P', $curOff));
                    fwrite($fpT, pack('v', $curCount) . $curRek);
                }
                $curTrip = $u['trip']; $curRek = ''; $curCount = 0;
                $curOff = ftell($fpT);
            }
            $curRek .= pack('vvvV', $u['seq'], $u['dep'], $u['arr'], $u['stop']);
            $curCount++;
        }
        if ($curCount > 0) {
            fseek($fpX, ($curTrip >> 8) * 8, SEEK_SET);
            fwrite($fpX, pack('P', $curOff));
            fwrite($fpT, pack('v', $curCount) . $curRek);
        }
        fclose($fpT); fclose($fpX);
        $st['b_trips'] += count(array_unique(array_map('intval',
            array_map(fn($k) => unpack('Nt', substr($k, 0, 4))['t'], $keys))));
        $st['st3next'] = $n + 1;
    }

    /* ---- Phase trans: Parent-Station-Paare (symmetrisch) ---- */
    $t0 = time();
    while ($st['transnext'] < 256) {
        if (time() > $tEnd) { dfr_state_put($st);
            return ['ok' => true, 'phase' => 'trans', 'budget' => true,
                'msg' => 'Budget in trans bei ' . $st['transnext'] . '.']; }
        $n = (int)$st['transnext'];
        if (!is_file($tdir . sprintf('/x%03d.bin', $n)) &&
            is_file($idir . sprintf('/trans_%03d.bin', $n))) {
            $st['transnext'] = $n + 1;                   /* Resume: schon fertig */
            continue;
        }
        /* Paare einmalig aus stops.tsv (Parent-Gruppen) */
        if (!is_file($tdir . '/tpairs_ready')) {
            $groups = [];
            $fh = fopen($idir . '/stops.tsv', 'rb');
            while (($ln = fgets($fh, 4096)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"));
                if (count($t) >= 6 && $t[5] !== '') {
                    $groups[$t[5]][] = [(int)$t[0], (float)$t[3], (float)$t[4]];
                }
            }
            fclose($fh);
            $fh2 = [];
            foreach ($groups as $mem) {
                $c = count($mem);
                if ($c < 2 || $c > 64) continue;
                for ($i = 0; $i < $c; $i++) {
                    for ($j = 0; $j < $c; $j++) {
                        if ($i === $j) continue;
                        [$a, $la1, $lo1] = $mem[$i];
                        [$b2, $la2, $lo2] = $mem[$j];
                        $d = dfr_dist_m($la1, $lo1, $la2, $lo2);
                        if ($d > 1200) continue;
                        $mins = dfr_walkmin($d);
                        $bkt = $a & 255;
                        if (!isset($fh2[$bkt]))
                            $fh2[$bkt] = fopen($tdir . sprintf('/x%03d.bin', $bkt), 'wb');
                        fwrite($fh2[$bkt], pack('VVC', $a, $b2,
                            min(255, $mins)));
                    }
                }
            }
            foreach ($fh2 as $h) fclose($h);
            file_put_contents($tdir . '/tpairs_ready', '1');
        }
        /* Bucket sortieren -> trans/trx (Block nach Stop A) */
        $bf = $tdir . sprintf('/x%03d.bin', $n);
        $rows = [];
        if (is_file($bf)) {
            $fh = fopen($bf, 'rb');
            $left = (int)floor(filesize($bf) / 9);
            while ($left-- > 0) {
                $u = unpack('Va/Vb/Cm', fread($fh, 9));
                if ($u['b'] !== $u['a'])
                    $rows[$u['a']][$u['b']] = $u['m'];
            }
            fclose($fh);
            unlink($bf);
        }
        $fpT = fopen($idir . sprintf('/trans_%03d.bin', $n), 'wb');
        $fpX = fopen($idir . sprintf('/trx_%03d.bin', $n), 'wb');
        ksort($rows);
        foreach ($rows as $a => $inner) {
            $off = ftell($fpT);
            fseek($fpX, ((int)$a >> 8) * 8, SEEK_SET);
            fwrite($fpX, pack('P', $off));
            ksort($inner);
            fwrite($fpT, pack('v', count($inner)));
            foreach ($inner as $b2 => $m) fwrite($fpT, pack('Vv', $b2, $m));
            $st['b_pairs'] += count($inner);
        }
        fclose($fpT); fclose($fpX);
        $st['transnext'] = $n + 1;
        if (time() - $t0 > 3) { dfr_state_put($st); $t0 = time(); }
    }
    return null;
}

/* ============================================================ DODP-VOLLBAU */

/** Tagesdifferenz zwischen zwei Ymd-Daten (to minus from).
 *  v0.2.7: Parameter int ODER string, weil numerische Array-Schluessel
 *  (day0 aus dfr_dodp_dirs, z. B. '20261123') in PHP automatisch int
 *  werden und strict_types=1 den expliziten string-Typ dann abweist. */
function dfr_day_diff(int|string $from, int|string $to): int {
    $from = (string)$from; $to = (string)$to;
    if (strlen($from) !== 8 || strlen($to) !== 8) return PHP_INT_MIN;
    $d = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));
    return (int)$d->format('%r%a');
}

/** Alle fertigen DODP-Index-Verzeichnisse (day0 => Pfad, absteigend). */
function dfr_dodp_dirs(): array {
    $root = dfr_base() . '/idx';
    $out = [];
    if (!is_dir($root)) return $out;
    $fs = @scandir($root);
    if ($fs === false) return $out;
    foreach ($fs as $d) {
        if (!str_starts_with($d, 'dodp_')) continue;
        $d0 = substr($d, 5);
        if (!ctype_digit($d0)) continue;
        $p = $root . '/' . $d;
        if (is_file($p . '/tprof_255.bin') && is_file($p . '/DONE'))
            $out[$d0] = $p;
    }
    krsort($out);
    return $out;
}

/** Phase svc: 128-Tage-Bitmasken aller Services aus calendar+calendar_dates.
 *  Rueckgabe null bei Erfolg, sonst Budget-Info-Array. */
function dfr_dodp_svcmap(string $zipPath, string $day0, string $idir, int $tEnd): ?array {
    $zero = str_repeat("\0", 16);
    $svc = [];
    $wd = [];
    for ($i = 0; $i < DFR_SVC_DAYS; $i++) {
        $wd[$i] = (int)(new DateTimeImmutable($day0))
            ->add(new DateInterval('P' . $i . 'D'))->format('N');
    }
    $wdN = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
        5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
    $got = dfr_zip_lines($zipPath, 'calendar.txt');
    if ($got !== null) {
        [, $fh] = $got;
        $hd = null; $first = true;
        while (($ln = fgets($fh, 65536)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['service_id','monday','tuesday','wednesday',
                    'thursday','friday','saturday','sunday','start_date','end_date']);
                continue; }
            $f = dfr_csv($ln);
            $sid = dfr_col($f, $hd['service_id']);
            if ($sid === '' || isset($svc[$sid])) continue;
            $bm = $zero;
            $stD = dfr_col($f, $hd['start_date']);
            $enD = dfr_col($f, $hd['end_date']);
            $i0 = 0; $i1 = DFR_SVC_DAYS - 1;
            if ($stD !== '') {
                $u = dfr_day_diff($day0, $stD);
                if ($u > $i1) { $svc[$sid] = $bm; continue; }
                $i0 = max(0, $u);
            }
            if ($enD !== '') {
                $u = dfr_day_diff($day0, $enD);
                $i1 = min($i1, $u);
            }
            for ($i = $i0; $i <= $i1; $i++) {
                if (dfr_col($f, $hd[$wdN[$wd[$i]]]) === '1') {
                    $bm[$i >> 3] = chr(ord($bm[$i >> 3]) | (1 << ($i & 7)));
                }
            }
            $svc[$sid] = $bm;
        }
        fclose($fh);
        if ($tEnd !== PHP_INT_MAX && time() > $tEnd)
            return ['ok' => true, 'phase' => 'svc', 'budget' => true,
                'msg' => 'Budget in DODP-svc (calendar).'];
    }
    $got = dfr_zip_lines($zipPath, 'calendar_dates.txt');
    if ($got !== null) {
        [, $fh] = $got;
        $hd = null; $first = true;
        while (($ln = fgets($fh, 65536)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['service_id','date','exception_type']);
                continue; }
            $f = dfr_csv($ln);
            $si = dfr_col($f, $hd['service_id']);
            $dt = dfr_col($f, $hd['date']);
            $ex = dfr_col($f, $hd['exception_type']);
            if ($si === '' || $dt === '') continue;
            $di = dfr_day_diff($day0, $dt);
            if ($di < 0 || $di >= DFR_SVC_DAYS) continue;
            if (!isset($svc[$si])) $svc[$si] = $zero;
            $byte = $di >> 3; $mask = 1 << ($di & 7);
            $bm = $svc[$si];
            if ($ex === '1') {
                $bm[$byte] = chr(ord($bm[$byte]) | $mask);
            } elseif ($ex === '2') {
                $bm[$byte] = chr(ord($bm[$byte]) & (255 - $mask));
            }
            $svc[$si] = $bm;
            if ($tEnd !== PHP_INT_MAX && time() > $tEnd)
                return ['ok' => true, 'phase' => 'svc', 'budget' => true,
                    'msg' => 'Budget in DODP-svc (calendar_dates).'];
        }
        fclose($fh);
    }
    $fhO = fopen($idir . '/svcday.tsv.part', 'wb');
    if ($fhO === false) return ['ok' => false, 'code' => 'readonly',
        'msg' => 'svcday.tsv nicht schreibbar.'];
    foreach ($svc as $sid => $bm) fwrite($fhO, $sid . "\t" . bin2hex($bm) . "\n");
    fclose($fhO);
    rename($idir . '/svcday.tsv.part', $idir . '/svcday.tsv');
    dfr_log(sprintf('dodp svc: %d Services gemappt', count($svc)));
    return null;
}

/** v0.2.6: Reparatur-Bootstrap nach dem unpack-Bug (DIAG v0.2.5: dprof/tprof
 *  je nur 12 Byte, weil alle Events je Bucket auf EINE Zeile kollabierten).
 *  Aufruf NUR CLI: "php delfirouting.php import=1 repair=1 budget=0".
 *  Entfernt DONE/stimes_done und die kollabierten Bucket-Dateien, setzt den
 *  State auf Phase stimes zurueck; der folgende Bau ueberspringt svc (svcday.tsv
 *  da) und tabs (tabs_done + stops/trips.tsv, tripday.bin, gridb, nidx/nms/sll,
 *  trline bleiben) und laeuft NUR stop_times -> Buckets -> trans -> fin neu. */
function dfr_repair(): void {
    $today = dfr_day_zo();
    $dir = null; $day0 = '';
    foreach (dfr_dodp_dirs() as $d0 => $p) {
        $u = dfr_day_diff($d0, $today);
        if ($u >= 0 && $u < DFR_SVC_DAYS) { $day0 = (string)$d0; $dir = $p; break; }
    }
    if ($dir === null) {
        echo "repair: kein gueltiges DODP-Index-Verzeichnis gefunden.\n";
        exit(1);
    }
    foreach (['DONE', 'stimes_done'] as $f) @unlink($dir . '/' . $f);
    foreach (['dprof_*.bin', 'didx_*.bin', 'tprof_*.bin', 'tidx_*.bin',
              'trans_*.bin', 'trx_*.bin'] as $g)
        foreach (glob($dir . '/' . $g) ?: [] as $p2) @unlink($p2);
    /* v0.2.8: tmp-Buckets des Tages komplett weg - die b-Files (Events),
     * tp-Files und x-Files stammen evtl. noch aus einem Abbruch mit dem
     * ALTEN Eventformat; stimes1 haengt im 'ab'-Modus an und vermischte
     * Zeilen liessen sich per Dedupe nicht mehr trennen. Der Bau mkdir-t
     * das tmp-Verzeichnis selbst neu. */
    $t2 = dfr_base() . '/tmp/dodp_' . $day0;
    if (is_dir($t2)) {
        foreach (scandir($t2) ?: [] as $f2)
            if ($f2 !== '.' && $f2 !== '..') @unlink($t2 . '/' . $f2);
        @rmdir($t2);
    }
    dfr_state_put(['src' => 'dodp', 'phase' => 'stimes', 'day0' => $day0,
        'doff' => 0, 'st2next' => 0, 'st3next' => 0, 'transnext' => 0,
        'b_trips' => 0, 'b_pairs' => 0, 'ts' => time()]);
    dfr_log("v0.2.6 repair: stimes-Neubau fuer dodp_$day0 (svc/tabs bleiben erhalten)");
    echo "repair ok: dodp_$day0 auf Phase stimes zurueckgesetzt.\n";
}

/** Der DODP-Vollbau: svc -> tabs -> stimes -> Buckets -> fin. */
function dfr_build_dodp(string $zipPath, int $zipMtime, int $tEnd): array {
    $today = dfr_day_zo();
    foreach (dfr_dodp_dirs() as $d0 => $p) {
        $u = dfr_day_diff($d0, $today);
        /* v0.2.6: ohne DONE-Datei ist kein Index "fertig"; fehlt sie
         * (Abbruch oder repair), faellt der Bau in die Resume-Logik. */
        if ($u >= 0 && $u < DFR_SVC_DAYS && is_file($p . '/DONE')) {
            $ts = (int)@filemtime($p . '/DONE');
            if ($zipMtime > $ts) {
                return ['ok' => true, 'phase' => 'done',
                    'msg' => 'Gueltiger DODP-Index ab ' . $d0 .
                        ' vorhanden, aber das Zip ist neuer. Fuer Neubau: delfidata/dfrouting/idx/dodp_' .
                        $d0 . ' loeschen und Import neu starten.'];
            }
            return ['ok' => true, 'phase' => 'done',
                'msg' => 'DODP-Index ab ' . $d0 . ' bereits fertig und gueltig.'];
        }
    }

    /* passenden abgebrochenen Bau fortsetzen (max. 30 Tage alt), sonst neu */
    $st = dfr_state_get();
    $st = is_array($st) ? $st : [];
    $day0 = $today; $resume = false;
    if (($st['src'] ?? '') === 'dodp' && ($st['phase'] ?? '') !== 'done') {
        $cand = (string)($st['day0'] ?? '');
        $u = $cand !== '' ? dfr_day_diff($cand, $today) : PHP_INT_MIN;
        if ($u >= 0 && $u <= 30 && is_dir(dfr_base() . '/idx/dodp_' . $cand)) {
            $day0 = $cand; $resume = true;
        }
    }
    if (!$resume) {
        $st = ['src' => 'dodp', 'phase' => 'svc', 'day0' => $day0, 'zip' => $zipPath,
               'doff' => 0, 'st2next' => 0, 'st3next' => 0, 'transnext' => 0,
               'b_trips' => 0, 'b_pairs' => 0, 'ts' => time()];
        dfr_state_put($st);
    }
    $idir = dfr_base() . '/idx/dodp_' . $day0;
    $tdir = dfr_base() . '/tmp/dodp_' . $day0;
    if (!is_dir($idir)) mkdir($idir, 0755, true);
    if (!is_dir($tdir)) mkdir($tdir, 0755, true);

    /* ---- Phase svc ---- */
    if (!is_file($idir . '/svcday.tsv')) {
        $res = dfr_dodp_svcmap($zipPath, $day0, $idir, $tEnd);
        if ($res !== null) { dfr_state_put($st); return $res; }
    }
    $st['phase'] = 'tabs';
    dfr_state_put($st);

    /* ---- Phase tabs: stops/routes/trips (alle) + tripday.bin ---- */
    if (!is_file($idir . '/tabs_done')) {
        if ($tEnd !== PHP_INT_MAX && time() > $tEnd)
            return ['ok' => true, 'phase' => 'tabs', 'budget' => true,
                'msg' => 'Budget vor DODP-tabs; naechster Slot.'];
        $svcmap = [];
        foreach (file($idir . '/svcday.tsv', FILE_IGNORE_NEW_LINES) as $ln) {
            $t = explode("\t", $ln);
            if (count($t) >= 2) $svcmap[$t[0]] = hex2bin($t[1]);
        }
        $fStops = fopen($idir . '/stops.tsv', 'ab');
        $fNms   = fopen($idir . '/nms.bin', 'ab');
        $fNidx  = fopen($idir . '/nidx.bin', 'ab');
        $fLL    = fopen($idir . '/sll.bin', 'ab');
        $gridFh = [];
        $stopmap = []; $tripmap = [];
        dfr_maps_reload($idir, $stopmap, $tripmap);
        $got = dfr_zip_lines($zipPath, 'stops.txt');
        if ($got === null) return ['ok' => false, 'code' => 'nozipmember',
            'msg' => 'stops.txt nicht im DODP-Zip gefunden.'];
        [, $fh] = $got; $hd = null; $first = true; $nStops = 0;
        while (($ln = fgets($fh, 65536)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['stop_id','stop_name','stop_lat','stop_lon',
                    'parent_station']);
                continue; }
            $f = dfr_csv($ln);
            $sid = dfr_col($f, $hd['stop_id']);
            if ($sid === '' || isset($stopmap[$sid])) continue;
            $la = dfr_col($f, $hd['stop_lat']); $lo = dfr_col($f, $hd['stop_lon']);
            if ($la === '' || $lo === '') continue;
            $laF = (float)$la; $loF = (float)$lo;
            if ($laF < 47 || $laF > 55.6 || $loF < 5.8 || $loF > 15.2) continue;
            $pa = dfr_col($f, $hd['parent_station']);
            $nm = trim(dfr_col($f, $hd['stop_name']));
            if ($nm === '') $nm = $sid;
            if (strpos($sid, "\t") !== false) continue;   /* TSV-Sicherheit */
            $nm = str_replace("\t", ' ', mb_substr($nm, 0, 180));
            $nr = count($stopmap);
            $stopmap[$sid] = $nr;
            fwrite($fStops, $nr . "\t" . $sid . "\t" . $nm . "\t" .
                $laF . "\t" . $loF . "\t" . $pa . "\n");
            $off = ftell($fNms);
            fwrite($fNms, $nm);
            $lbnb = strlen($nm);
            fwrite($fNidx, pack('Vvxx', $off, $lbnb <= 65535 ? $lbnb : 65535));
            fwrite($fLL, pack('gg', $laF, $loF));
            $k = ((int)floor($laF * 10)) . '_' . ((int)floor($loF * 10));
            dfr_grid_put($gridFh, $idir, $k,
                $nr . "\t" . $nm . "\t" . $laF . "\t" . $loF . "\n");
            $nStops++;
        }
        fclose($fh);
        foreach ($gridFh as $h) { if (is_resource($h)) fclose($h); }
        fclose($fStops); fclose($fNms); fclose($fNidx); fclose($fLL);
        /* routes */
        $fRoutes = fopen($idir . '/routes.tsv', 'ab');
        $got = dfr_zip_lines($zipPath, 'routes.txt');
        if ($got !== null) {
            [, $fh] = $got; $hd = null; $first = true;
            $have = [];
            foreach (file($idir . '/routes.tsv', FILE_IGNORE_NEW_LINES) as $ln)
                { $t2 = explode("\t", $ln); if (count($t2) >= 1) $have[$t2[0]] = 1; }
            while (($ln = fgets($fh, 65536)) !== false) {
                $ln = rtrim($ln, "\r\n");
                if ($ln === '') continue;
                if ($first) { $first = false;
                    $hd = dfr_hmap($ln, ['route_id','long_name','short_name','route_type',
                        'route_long_name','route_short_name']);
                    continue; }
                $f = dfr_csv($ln);
                $ri = dfr_col($f, $hd['route_id']);
                if ($ri === '' || isset($have[$ri])) continue;
                $sn = dfr_col($f, $hd['short_name']);
                if ($sn === '') $sn = dfr_col($f, $hd['route_short_name']);
                $l2n = dfr_col($f, $hd['long_name']);
                if ($l2n === '') $l2n = dfr_col($f, $hd['route_long_name']);
                $ty = dfr_col($f, $hd['route_type']); if ($ty === '') $ty = '3';
                $line = $sn; if ($line === '') $line = $l2n;
                $line = str_replace("\t", ' ', mb_substr($line, 0, 60));
                fwrite($fRoutes, $ri . "\t" . $line . "\t" . $ty . "\n");
                $have[$ri] = 1;
            }
            fclose($fh);
        }
        fclose($fRoutes);
        /* rlmap + trips (alle) + tripday.bin */
        $rlmap = [];
        foreach (file($idir . '/routes.tsv', FILE_IGNORE_NEW_LINES) as $ln) {
            $t = explode("\t", $ln);
            if (count($t) >= 2) $rlmap[$t[0]] = $t[1];
        }
        $fTrips = fopen($idir . '/trips.tsv', 'ab');
        $fTrline = fopen($idir . '/trline.bin', 'ab');
        $fTd = fopen($idir . '/tripday.bin', 'ab');
        $zero16 = str_repeat("\0", 16);
        $got = dfr_zip_lines($zipPath, 'trips.txt');
        if ($got === null) return ['ok' => false, 'code' => 'nozipmember',
            'msg' => 'trips.txt nicht im DODP-Zip gefunden.'];
        [, $fh] = $got; $hd = null; $first = true; $nTrips = 0;
        while (($ln = fgets($fh, 65536)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['trip_id','service_id','route_id']);
                continue; }
            $f = dfr_csv($ln);
            $ti = dfr_col($f, $hd['trip_id']);
            if ($ti === '' || strpos($ti, "\t") !== false || isset($tripmap[$ti])) continue;
            $si = dfr_col($f, $hd['service_id']);
            $ri = dfr_col($f, $hd['route_id']);
            $nr = count($tripmap);
            $tripmap[$ti] = $nr;
            fwrite($fTrips, $nr . "\t" . $ti . "\t" . $ri . "\n");
            $lb = isset($rlmap[$ri]) ? $rlmap[$ri] : '';
            $b = substr($lb, 0, 31);
            fwrite($fTrline, $b . str_repeat("\0", 32 - strlen($b)));
            fwrite($fTd, $svcmap[$si] ?? $zero16);
            $nTrips++;
        }
        fclose($fh); fclose($fTrips); fclose($fTrline); fclose($fTd);
        file_put_contents($idir . '/tabs_done', '1');
        dfr_log(sprintf('dodp tabs: %d stops, %d trips', $nStops, $nTrips));
    }
    $st['phase'] = 'stimes';
    dfr_state_put($st);

    /* ---- Phase stimes: stop_times mit Zeilen-Resume (doff) ---- */
    if (!is_file($idir . '/stimes_done')) {
        $stopmap = []; $tripmap = [];
        dfr_maps_reload($idir, $stopmap, $tripmap);
        $done = (int)($st['doff'] ?? 0);
        $got = dfr_zip_lines($zipPath, 'stop_times.txt');
        if ($got === null) return ['ok' => false, 'code' => 'nozipmember',
            'msg' => 'stop_times.txt nicht im DODP-Zip gefunden.'];
        [, $fh] = $got;
        $hd = null; $first = true; $nr = 0; $miss = 0;
        $buckets = [];
        while (($ln = fgets($fh, 1024)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($nr < $done) { $nr++; continue; }
            if ($ln === '') { $nr++; continue; }
            if ($first) { $first = false;
                $hd = dfr_hmap($ln, ['trip_id','arrival_time','arrival',
                    'departure_time','departure','stop_id','stop_sequence']);
                $nr++; continue; }
            $f = dfr_csv($ln);
            $ti = dfr_col($f, $hd['trip_id']);
            $sid = dfr_col($f, $hd['stop_id']);
            if ($ti === '' || $sid === '' ||
                !isset($tripmap[$ti]) || !isset($stopmap[$sid])) { $miss++; }
            else {
                $iDp = $hd['departure_time']; if ($iDp === null) $iDp = $hd['departure'];
                $iAr = $hd['arrival_time'];   if ($iAr === null) $iAr = $hd['arrival'];
                $dp = dfr_col($f, $iDp);
                $ar = dfr_col($f, $iAr); if ($ar === '') $ar = $dp;
                $dep = dfr_hm2min($dp); $arr = dfr_hm2min($ar);
                $sq = (int)dfr_col($f, $hd['stop_sequence']);
                if ($dep === null || $arr === null || $sq < 0 || $sq > 65535) $miss++;
                else {
                    $sp = $stopmap[$sid];
                    $rec = pack('VvvVv', $sp, $dep, $arr, $tripmap[$ti], $sq);
                    $b = $sp & 255;
                    if ($buckets === null) $buckets = [];
                    if (!isset($buckets[$b]))
                        $buckets[$b] = fopen($tdir . sprintf('/b%03d.bin', $b), 'ab');
                    fwrite($buckets[$b], $rec);
                }
            }
            $nr++;
            if (($nr & 0x3FFFF) === 0) {
                $st['doff'] = $nr;
                dfr_state_put($st);
                if (time() > $tEnd) {
                    foreach ($buckets ?? [] as $h) fclose($h);
                    dfr_log("dodp stimes: Budget bei Zeile $nr");
                    return ['ok' => true, 'phase' => 'stimes', 'budget' => true,
                        'msg' => "Budget in DODP-stimes bei Zeile $nr."];
                }
            }
        }
        foreach ($buckets ?? [] as $h) fclose($h);
        $st['doff'] = $nr;
        dfr_state_put($st);
        file_put_contents($idir . '/stimes_done', '1');
        dfr_log(sprintf('dodp stimes: fertig (%d Zeilen, %d verworfen)', $nr, $miss));
    }
    $st['phase'] = 'buckets';
    dfr_state_put($st);

    /* ---- Bucket-Phasen (identisch free) ---- */
    $bres = dfr_phase_buckets($idir, $tdir, $st, $tEnd);
    if ($bres !== null) { dfr_state_put($st); return $bres; }

    /* ---- fin ---- */
    if (is_dir($tdir)) {
        foreach (scandir($tdir) as $f2) if ($f2 !== '.' && $f2 !== '..')
            @unlink($tdir . '/' . $f2);
        @rmdir($tdir);
    }
    $rootI = dfr_base() . '/idx';           /* alle aelteren dodp-Indizes weg */
    foreach (scandir($rootI) as $d2) {
        if (!str_starts_with($d2, 'dodp_') || $d2 === 'dodp_' . $day0) continue;
        $p2 = $rootI . '/' . $d2;
        if (!is_dir($p2)) continue;
        foreach (scandir($p2) as $f2) if ($f2 !== '.' && $f2 !== '..')
            @unlink($p2 . '/' . $f2);
        @rmdir($p2);
    }
    file_put_contents($idir . '/DONE', (string)time());
    dfr_state_put(['src' => 'dodp', 'phase' => 'done', 'day0' => $day0,
        'ts' => time(), 'trips' => (int)($st['b_trips'] ?? 0),
        'pairs' => (int)($st['b_pairs'] ?? 0)]);
    dfr_log("fin: DODP-Index $day0 fertig");
    return ['ok' => true, 'phase' => 'fin', 'day0' => $day0, 'src' => 'dodp',
        'trips' => (int)($st['b_trips'] ?? 0),
        'pairs' => (int)($st['b_pairs'] ?? 0)];
}

function dfr_zip_zippath(string $feed): ?string {
    $p = dfr_zipdir() . '/' . $feed . '_latest.zip';
    return is_file($p) ? $p : null;
}

/* ====================================================== INDEX-ZUGRIFF */

class DfrIdx {
    public string $dir = '';
    public string $day = '';
    public string $src = 'free';      /* 'free' | 'dodp' */
    public string $day0 = '';          /* dodp: erster Index-Tag */
    public int $dayIdx = 0;           /* dodp: heutiger Tag 0..127 */
    public string $validUntil = '';   /* dodp: letzter gueltiger Tag */
    public ?string $tripday = null;   /* dodp: seit v0.2.3 ungenutzt (Handle-Zugriff), bleibt aus Kompatibilitaet */
    /** @var resource|null */
    public $fhC = null;              /* Handle-Cache: path => resource */
    public array $h = [];
    public array $nearCache = [];    /* Zubringer */
    public array $bandCache = [];    /* Geo-Transfer-Baender */
    public array $transCache = [];
    public array $tCache = [];       /* 's'.stopNr / tripNr -> Block */
}

function dfr_idx(): DfrIdx {
    static $idx = null;
    if ($idx !== null && $idx->dir !== '') return $idx;
    $idx = new DfrIdx();
    $root = dfr_base() . '/idx';
    if (!is_dir($root)) return $idx;
    $fs = @scandir($root);
    if ($fs === false) return $idx;
    /* 1) frischester gueltiger DODP-Index gewinnt */
    $dodps = [];
    $days = [];
    foreach ($fs as $d) {
        if ($d === '.' || $d === '..') continue;
        if (ctype_digit($d)) {
            if (is_file($root . '/' . $d . '/tprof_255.bin')) $days[] = $d;
            continue;
        }
        if (!str_starts_with($d, 'dodp_')) continue;
        $d0 = substr($d, 5);
        if (!ctype_digit($d0)) continue;
        $p = $root . '/' . $d;
        if (!is_file($p . '/tprof_255.bin') || !is_file($p . '/tripday.bin')) continue;
        $dodps[] = $d0;
    }
    if ($dodps) {
        rsort($dodps);
        $today = dfr_day_zo();
        foreach ($dodps as $d0) {
            $u = dfr_day_diff($d0, $today);
            if ($u >= 0 && $u < DFR_SVC_DAYS) {
                $idx->src = 'dodp';
                $idx->day0 = $d0;
                $idx->dayIdx = $u;
                $idx->validUntil = (new DateTimeImmutable($d0))
                    ->add(new DateInterval('P' . (DFR_SVC_DAYS - 1) . 'D'))->format('Ymd');
                $idx->day = $today;
                $idx->dir = $root . '/dodp_' . $d0;
                return $idx;
            }
        }
    }
    /* 2) Failsafe: neuester free-Tagesindex */
    if (!$days) return $idx;
    rsort($days);
    $idx->day = $days[0];
    $idx->src = 'free';
    $idx->dir = $root . '/' . $idx->day;
    return $idx;
}

/** DODP: laeuft die Fahrt laut 128-Tage-Bitmaske am heutigen Index-Tag?
 *  v0.2.3: Handle + fseek + EIN Byte statt file_get_contents der kompletten
 *  tripday.bin (rund 30 MB bei 1,9 Mio Trips) je Anfrage. */
function dfr_trip_active(DfrIdx $ix, int $tripNr): bool {
    if ($ix->src !== 'dodp') return true;
    $fh = dfr_fopen($ix->dir . '/tripday.bin');
    if ($fh === null) return true;   /* Fehlschutz: Bitmaske weg */
    $o = $tripNr * 16 + ($ix->dayIdx >> 3);
    if (@fseek($fh, $o) !== 0) return false;   /* Fehler: Trip hinter Dateiende => nicht aktiv */
    $b = @fread($fh, 1);
    if ($b === false || $b === '') return false;   /* hinter dem Dateiende: nicht aktiv */
    return ((ord($b) >> ($ix->dayIdx & 7)) & 1) === 1;
}
function dfr_fopen(string $path) {
    global $_dfr_handles;
    if (!isset($_dfr_handles)) $_dfr_handles = [];
    if (!array_key_exists($path, $_dfr_handles)) {
        /* v0.2.10: fd-Limit-Schutz. Ohne Cap koennen bis zu ~1540 Handles
         * je Request offen bleiben (6 Familien x 256 Buckets + fixe Dateien);
         * am nofile-Limit des Webspace lief fopen false. 400 ist sicher unter
         * ueblichen Limits; Schliessen ist gefahrlos, da JEDEr Leser vor
         * fread/fgets explizit fseek setzt (kein Zeiger-Zustand im Cache). */
        if (count($_dfr_handles) >= 400) {
            foreach ($_dfr_handles as $h) { if (is_resource($h)) @fclose($h); }
            $_dfr_handles = [];
        }
        $_dfr_handles[$path] = @fopen($path, 'rb');
        if ($_dfr_handles[$path] === false) $_dfr_handles[$path] = null;
    }
    return $_dfr_handles[$path];
}

function dfr_dprof(DfrIdx $ix, int $stopNr) {
    $n = $stopNr & 255;
    $fh = dfr_fopen($ix->dir . sprintf('/didx_%03d.bin', $n));
    if ($fh === null) return null;
    fseek($fh, ($stopNr >> 8) * 8);
    $raw = fread($fh, 8);
    if (strlen($raw) < 8) return null;
    $off = unpack('P', $raw)[1];
    if ($off === 0) return null;
    $fd = dfr_fopen($ix->dir . sprintf('/dprof_%03d.bin', $n));
    if ($fd === null) return null;
    fseek($fd, $off);
    $hdr = fread($fd, 4);
    if (strlen($hdr) < 4) return null;
    $cnt = unpack('V', $hdr)[1];
    if ($cnt === 0 || $cnt > 20000) return null;
    return [$cnt, fread($fd, $cnt * 8)];
}
function dfr_tprof(DfrIdx $ix, int $tripNr) {
    $n = $tripNr & 255;
    $fh = dfr_fopen($ix->dir . sprintf('/tidx_%03d.bin', $n));
    if ($fh === null) return null;
    fseek($fh, ($tripNr >> 8) * 8);
    $raw = fread($fh, 8);
    if (strlen($raw) < 8) return null;
    $off = unpack('P', $raw)[1];
    if ($off === 0) return null;
    $fd = dfr_fopen($ix->dir . sprintf('/tprof_%03d.bin', $n));
    if ($fd === null) return null;
    fseek($fd, $off);
    $hdr = fread($fd, 2);
    if (strlen($hdr) < 2) return null;
    $cnt = unpack('v', $hdr)[1];
    if ($cnt === 0 || $cnt > 2000) return null;
    return [$cnt, fread($fd, $cnt * 10)];
}
/** Parent-Transfer-Block eines Stops: [[stopNr, mins], ...] */
function dfr_trans_block(DfrIdx $ix, int $stopNr): array {
    $n = $stopNr & 255;
    $fh = dfr_fopen($ix->dir . sprintf('/trx_%03d.bin', $n));
    if ($fh === null) return [];
    fseek($fh, ($stopNr >> 8) * 8);
    $raw = fread($fh, 8);
    if (strlen($raw) < 8) return [];
    $off = unpack('P', $raw)[1];
    if ($off === 0) return [];
    $fd = dfr_fopen($ix->dir . sprintf('/trans_%03d.bin', $n));
    if ($fd === null) return [];
    fseek($fd, $off);
    $hdr = fread($fd, 2);
    if (strlen($hdr) < 2) return [];
    $cnt = unpack('v', $hdr)[1];
    /* v0.2.6: der Bau schreibt pack('Vv') = 6 Byte je Paar; der Leser
     * ging mit 5-Byte-Schritten durch die Records und las Muell/null. */
    $raw = $cnt > 0 ? fread($fd, $cnt * 6) : '';
    $out = [];
    for ($i = 0; $i < $cnt; $i++) {
        $u = unpack('Vb/vm', substr($raw, $i * 6, 6));
        $out[] = [$u['b'], $u['m']];
    }
    return $out;
}
function dfr_stop_name(DfrIdx $ix, int $stopNr): string {
    $fh = dfr_fopen($ix->dir . '/nidx.bin');
    if ($fh === null) return '?';
    fseek($fh, $stopNr * 8);
    $raw = fread($fh, 6);
    if (strlen($raw) < 6) return '?';
    $u = unpack('Voff/vlen', $raw);
    $fm = dfr_fopen($ix->dir . '/nms.bin');
    if ($fm === null) return '?';
    fseek($fm, $u['off']);
    return (string)fread($fm, $u['len']);
}
function dfr_stop_ll(DfrIdx $ix, int $stopNr) {
    $fh = dfr_fopen($ix->dir . '/sll.bin');
    if ($fh === null) return null;
    fseek($fh, $stopNr * 8);
    $raw = fread($fh, 8);
    if (strlen($raw) < 8) return null;
    $u = unpack('gla/glo', $raw);
    return [$u['la'], $u['lo']];
}
function dfr_trip_label(DfrIdx $ix, int $tripNr): string {
    $fh = dfr_fopen($ix->dir . '/trline.bin');
    if ($fh === null) return '';
    fseek($fh, $tripNr * 32);
    $raw = fread($fh, 32);
    $p = strpos($raw, "\0");
    if ($p !== false) $raw = substr($raw, 0, $p);
    return (string)$raw;
}

/** v0.2.9: Kopfziel einer Fahrt (letzte Haltestelle aus dem tprof-Profil) als
 *  Richtungsangabe fuer die App-Darstellung ("Richtung ..."); leer, wenn das
 *  Profil (noch) nicht lesbar ist. Cache je TripNr, da mehrere Conns an
 *  derselben Fahrt haengen koennen. */
function dfr_trip_headsign(DfrIdx $ix, int $tripNr): string {
    static $hsCache = [];
    if (!array_key_exists($tripNr, $hsCache)) {
        $hs = '';
        $d = dfr_tprof($ix, $tripNr);
        if ($d !== null && $d[0] > 0) {
            $u = unpack('vseq/vdep/varr/Vstop',
                substr($d[1], ($d[0] - 1) * 10, 10));
            if ($u !== false) $hs = dfr_stop_name($ix, (int)$u['stop']);
        }
        $hsCache[$tripNr] = $hs;
    }
    return $hsCache[$tripNr];
}

/** v0.2.11: Soll-Verlauf eines Abschnitts aus dem tprof: alle Halt-Eintraege
 *  von der Zustiegs-Seq bis zur Ausstiegs-Station (erste passende nach Seq),
 *  je {name, dep, arr, lat, lon}; Zeilen sind tprof-Sortierung = Seq steigend.
 *  dep/arr je Eintrag in Tagesminuten inkl. Delay-Anteil dM (Soll + Delay);
 *  erster Eintrag uebernimmt die Leg-dep, letzter die Leg-arr (Original der
 *  Welle, inkl. Clamp). Fallback: nur Zustieg + Ausstieg. */
function dfr_trip_seg(DfrIdx $ix, int $tripNr, int $boardSeq, int $boardStop,
    int $exitStop, int $depMin, int $arrMin, int $dM): array {
    $mk = function (int $stop, ?int $dep, ?int $arr) use ($ix): array {
        $ll = dfr_stop_ll($ix, $stop);
        return ['name' => dfr_stop_name($ix, $stop),
            'dep' => $dep === null ? null : dfr_min2hm($dep),
            'arr' => $arr === null ? null : dfr_min2hm($arr),
            'lat' => $ll === null ? null : round($ll[0], 5),
            'lon' => $ll === null ? null : round($ll[1], 5)];
    };
    $d = dfr_tprof($ix, $tripNr);
    if ($d === null || $d[0] < 1)
        return [$mk($boardStop, $depMin, null), $mk($exitStop, null, $arrMin)];
    [$cnt, $pr] = $d;
    $i0 = -1;
    for ($i = 0; $i < $cnt; $i++) {
        $r = unpack('vseq/vdep/varr/Vstop', substr($pr, $i * 10, 10));
        if ($r === false) break;
        if ((int)$r['seq'] === $boardSeq) { $i0 = $i; break; }
        if ((int)$r['seq'] > $boardSeq && $i0 < 0) $i0 = $i;   /* Seq fehlt: naechste nehmen */
    }
    if ($i0 < 0)
        return [$mk($boardStop, $depMin, null), $mk($exitStop, null, $arrMin)];
    /* Ausstieg: erste Station nach der Zustiegs-Seq mit passendem Ziel (bei
     * Rundkursen vorzugsweise die mit uebereinstimmender Ankunftszeit). */
    $i1 = -1; $i1t = -1;
    for ($i = $i0 + 1; $i < $cnt; $i++) {
        $r = unpack('vseq/vdep/varr/Vstop', substr($pr, $i * 10, 10));
        if ($r === false) break;
        if ((int)$r['stop'] !== $exitStop) continue;
        $ar = (int)$r['arr'] + $dM;
        if ($ar > 65535) $ar = 65535;
        if ($i1 < 0) $i1 = $i;
        if ($ar === $arrMin) { $i1t = $i; break; }
    }
    if ($i1t >= 0) $i1 = $i1t;
    if ($i1 < 0) {   /* Ausstieg nicht im Profil (sollte nicht passieren): Mindest-Liste */
        $out = [$mk($boardStop, $depMin, null)];
        if ($exitStop !== $boardStop) $out[] = $mk($exitStop, null, $arrMin);
        return $out;
    }
    $out = [];
    for ($i = $i0; $i <= $i1; $i++) {
        $r = unpack('vseq/vdep/varr/Vstop', substr($pr, $i * 10, 10));
        if ($r === false) break;
        $dp = ((int)$r['dep'] + $dM) & 0xFFFF;
        $ar = (int)$r['arr'] + $dM;
        if ($ar > 65535) $ar = 65535;
        $e = $mk((int)$r['stop'], $dp, $ar);
        if ($i === $i0) $e['dep'] = dfr_min2hm($depMin);       /* Leg-dep uebernehmen */
        if ($i === $i1) $e['arr'] = dfr_min2hm($arrMin);       /* Leg-arr uebernehmen */
        $out[] = $e;
    }
    return $out;
}

/** v0.2.11: Ganzer Lauf einer Fahrt (?trip=<TripNr>): profil-sortierte
 *  Soll-Halteliste mit seq/dep/arr/name/lat/lon plus line/dir/active. */
function dfr_trip_out(DfrIdx $ix, int $tripNr): array {
    $stops = [];
    $d = dfr_tprof($ix, $tripNr);
    if ($d !== null) {
        [$cnt, $pr] = $d;
        for ($i = 0; $i < $cnt; $i++) {
            $r = unpack('vseq/vdep/varr/Vstop', substr($pr, $i * 10, 10));
            if ($r === false) break;
            $ll = dfr_stop_ll($ix, (int)$r['stop']);
            $stops[] = ['seq' => (int)$r['seq'],
                'dep' => dfr_min2hm((int)$r['dep']),
                'arr' => dfr_min2hm((int)$r['arr']),
                'name' => dfr_stop_name($ix, (int)$r['stop']),
                'lat' => $ll === null ? null : round($ll[0], 5),
                'lon' => $ll === null ? null : round($ll[1], 5)];
        }
    }
    return ['ok' => true, 'v' => DFR_V, 'src' => $ix->src, 'day' => $ix->day,
        'trip' => $tripNr, 'line' => dfr_trip_label($ix, $tripNr),
        'dir' => dfr_trip_headsign($ix, $tripNr),
        'active' => dfr_trip_active($ix, $tripNr), 'stops' => $stops];
}

/** Zubringerstops um einen Punkt (3x3 Grid-Zellen, gecacht, naechste zuerst). */
function dfr_near_stops(DfrIdx $ix, float $lat, float $lon, int $radius, int $maxN): array {
    $key = sprintf('%.5f|%.5f|%d', $lat, $lon, $radius);
    if (isset($ix->nearCache[$key])) return array_slice($ix->nearCache[$key], 0, $maxN);
    $out = [];
    $cla = (int)floor($lat * 10); $clo = (int)floor($lon * 10);
    for ($dla = -1; $dla <= 1; $dla++) {
        for ($dlo = -1; $dlo <= 1; $dlo++) {
            $p = $ix->dir . sprintf('/gridb_%d_%d.tsv', $cla + $dla, $clo + $dlo);
            if (!is_file($p)) continue;
            $fh = fopen($p, 'rb');
            if ($fh === false) {   /* v0.2.10: fd-Limit/race statt fgets-TypeError */
                dfr_log('near: fopen failed ' . basename($p));
                continue;
            }
            while (($ln = fgets($fh, 512)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"));
                if (count($t) < 4) continue;
                $d = dfr_dist_m($lat, $lon, (float)$t[2], (float)$t[3]);
                if ($d <= $radius) {
                    $out[] = [(int)$t[0], $t[1], (int)round($d), dfr_walkmin($d),
                        (float)$t[2], (float)$t[3]];
                }
            }
            fclose($fh);
        }
    }
    usort($out, fn($a, $b) => $a[3] <=> $b[3]);
    $ix->nearCache[$key] = $out;
    return array_slice($out, 0, $maxN);
}

/** 3x3-Zellband (lat/lon) fuer Geo-Transfer, einmal je Gruppe gecacht. */
function dfr_cell_band(DfrIdx $ix, int $la, int $lo): array {
    $k = $la * 1000 + $lo;
    if (isset($ix->bandCache[$k])) return $ix->bandCache[$k];
    $out = [];
    for ($dla = -1; $dla <= 1; $dla++) {
        for ($dlo = -1; $dlo <= 1; $dlo++) {
            $p = $ix->dir . sprintf('/gridb_%d_%d.tsv', $la + $dla, $lo + $dlo);
            if (!is_file($p)) continue;
            $fh = fopen($p, 'rb');
            if ($fh === false) {   /* v0.2.10: fd-Limit/race statt fgets-TypeError */
                dfr_log('band: fopen failed ' . basename($p));
                continue;
            }
            while (($ln = fgets($fh, 512)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"));
                if (count($t) < 4) continue;
                $out[] = [(float)$t[2], (float)$t[3], (int)$t[0]];
            }
            fclose($fh);
        }
    }
    $ix->bandCache[$k] = $out;
    return $out;
}

/** Naechste Transfers je Stop: Parent-Paare + Geo-Umfeld (bbox-Vorfilter). */
function dfr_trans_near(DfrIdx $ix, int $stopNr): array {
    if (isset($ix->transCache[$stopNr])) return $ix->transCache[$stopNr];
    $out = [];
    foreach (dfr_trans_block($ix, $stopNr) as [$b2, $m]) {
        if ($b2 !== $stopNr) $out[$b2] = [$m, null];   /* v0.2.12: [min, meter] */
    }
    $ll = dfr_stop_ll($ix, $stopNr);
    if ($ll !== null) {
        $band = dfr_cell_band($ix, (int)floor($ll[0] * 10), (int)floor($ll[1] * 10));
        $la = $ll[0]; $lo = $ll[1];
        $nl = count($band);
        for ($i = 0; $i < $nl; $i++) {
            $e = $band[$i];
            if (abs($e[0] - $la) > 0.0065) continue;     /* ~720 m Breite */
            if (abs($e[1] - $lo) > 0.0095) continue;     /* ~600-1100 m Laenge */
            $nr = $e[2];
            if ($nr === $stopNr || isset($out[$nr])) continue;
            $d = dfr_dist_m($la, $lo, $e[0], $e[1]);
            if ($d <= DFR_TRANS_M) {
                $wm = dfr_walkmin($d);
                if (!isset($out[$nr]) || $wm < $out[$nr][0]) $out[$nr] = [$wm, $d];
            }
        }
    }
    uasort($out, fn($a, $b) => $a[0] <=> $b[0]);
    $res = [];
    $i = 0;
    foreach ($out as $b2 => $mo) {
        if ($i++ >= DFR_TRANS_MAX) break;
        $res[] = [(int)$b2, (int)$mo[0], $mo[1]];
    }
    $ix->transCache[$stopNr] = $res;
    return $res;
}

/* ========================================================= REALTIME */

/** Minimal-Protobuf-Scanner: [field, wire, value(bytes|int)] - Liste. */
function dfr_pb_scan(string $s): array {
    $out = []; $i = 0; $n = strlen($s);
    while ($i < $n) {
        $tag = 0; $sh = 0;
        while ($i < $n) {
            $b = ord($s[$i]); $i++;
            $tag |= ($b & 0x7F) << $sh; $sh += 7;
            if (!($b & 0x80)) break;
        }
        $field = $tag >> 3; $wire = $tag & 7;
        if ($wire === 0) {
            $v = 0; $sh = 0;
            while ($i < $n) {
                $b = ord($s[$i]); $i++;
                $v |= ($b & 0x7F) << $sh; $sh += 7;
                if (!($b & 0x80)) break;
            }
            $out[] = [$field, $wire, $v];
        } elseif ($wire === 2) {
            $len = 0; $sh = 0;
            while ($i < $n) {
                $b = ord($s[$i]); $i++;
                $len |= ($b & 0x7F) << $sh; $sh += 7;
                if (!($b & 0x80)) break;
            }
            $out[] = [$field, $wire, substr($s, $i, $len)];
            $i += $len;
        } elseif ($wire === 5) { $out[] = [$field, $wire, substr($s, $i, 4)]; $i += 4;
        } elseif ($wire === 1) { $out[] = [$field, $wire, substr($s, $i, 8)]; $i += 8;
        } else break;
    }
    return $out;
}

/** trip_id -> tripNr -> Verzoegerung (Sekunden, Trip-Ebene oder Stop-Ebene). */
function dfr_rt_delays(DfrIdx $ix): array {
    static $cache = null; static $t0 = 0;
    if ($cache !== null && time() - $t0 < DFR_MT_TTL) return $cache;
    $cache = [];
    $p = dfr_base() . '/rt_sim.pb';
    $need = true;
    if (is_file($p) && time() - (int)filemtime($p) < DFR_MT_TTL) $need = false;
    if ($need) {
        $ok = false;
        $ctx = stream_context_create(['http' => ['timeout' => 25,
            'user_agent' => 'pixcaelium-delfirouting/0.1']]);
        $raw = @file_get_contents(DFR_RT_URL, false, $ctx);
        if (is_string($raw) && strlen($raw) > 16) {
            file_put_contents($p . '.tmp', $raw);
            rename($p . '.tmp', $p);
            $ok = true;
        } elseif (is_file($p)) $ok = true;               /* Alter Cache faellt zurueck */
        if (!$ok) { $t0 = time(); return []; }
    }
    $pb = (string)file_get_contents($p);
    $rawDelays = [];
    foreach (dfr_pb_scan($pb) as [$field, , $ent]) {
        if ($field !== 2 || !is_string($ent)) continue;
        foreach (dfr_pb_scan($ent) as [$f2, , $tu]) {
            if ($f2 !== 2 || !is_string($tu)) continue;
            $tripId = null; $delay = null;
            foreach (dfr_pb_scan($tu) as [$f3, , $v3]) {
                if ($f3 === 1 && is_string($v3)) {
                    foreach (dfr_pb_scan($v3) as [$f4, , $v4]) {
                        if ($f4 === 1 && is_string($v4)) { $tripId = $v4; break; }
                    }
                } elseif ($f3 === 5 && is_int($v3)) {
                    $delay = $v3;
                } elseif ($f3 === 2 && is_string($v3) && $delay === null) {
                    /* StopTimeUpdate: erstes Arrival/Departure-Delay genuegt */
                    foreach (dfr_pb_scan($v3) as [$f5, , $v5]) {
                        if ($f5 === 2 && is_string($v5)) {
                            foreach (dfr_pb_scan($v5) as [$f6, , $v6]) {
                                if ($f6 === 1 && is_int($v6)) { $delay = $v6; break 2; }
                            }
                        }
                    }
                }
            }
            if ($tripId !== null && $delay !== null) $rawDelays[$tripId] = $delay;
        }
    }
    if (!$rawDelays) { $t0 = time(); return []; }
    /* Join trip_id -> tripNr (trips.tsv einmal scannen) */
    $delays = [];
    $nr = 0;
    $fh = fopen($ix->dir . '/trips.tsv', 'rb');
    if ($fh !== false) {
        while (($ln = fgets($fh, 4096)) !== false) {
            $t = explode("\t", rtrim($ln, "\n"));
            if (count($t) >= 2 && isset($rawDelays[$t[1]])) $delays[$nr] = $rawDelays[$t[1]];
            $nr++;
        }
        fclose($fh);
    }
    $cache = $delays; $t0 = time();
    return $delays;
}

/** v0.2.13: DELFI-Pool-Strom fuer den DODP-Index - gemeinsame Datei mit
 *  delfiproxy.php (../pool.pb) plus eigenem mTLS-Pull bei Bedarf. */
function dfr_rt_pool_file(): string {
    return dirname(dfr_base()) . '/pool.pb';
}
function dfr_rt_pool_certdir(): string {
    return dirname(__DIR__) . '/delficerts';   /* apps/citysurfers/delficerts */
}
function dfr_rt_pool_age(): ?int {
    $p = dfr_rt_pool_file();
    $m = @filemtime($p);
    return $m === false ? null : (time() - (int)$m);
}
function dfr_rt_pool_fetch(): bool {
    $dest = dfr_rt_pool_file();
    $cert = dfr_rt_pool_certdir() . '/cert.pem';
    $key  = dfr_rt_pool_certdir() . '/key.pem';
    if (!is_file($cert) || !is_file($key)) return false;
    $tmp = $dest . '.dfr';
    $ok = false;
    if (function_exists('curl_init')) {
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) return false;
        $ch = curl_init('https://mobilithek.info:8443/mobilithek/api/v1.0/container/subscription?subscriptionID=1061368693622788096');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => 12,          /* v0.2.13: unter dem Anfrage-Budget */
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'pixcaelium-delfirouting/0.2',
            CURLOPT_SSLCERT => $cert,
            CURLOPT_SSLKEY => $key,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = (bool)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $code !== 200) $ok = false;
    } else {
        $combo = dirname($dest) . '/dfr.certkey.pem';
        $c = @file_get_contents($cert); $k = @file_get_contents($key);
        if (!is_string($c) || !is_string($k)) return false;
        if (@file_put_contents($combo, $c . "\n" . $k, LOCK_EX) === false) return false;
        @chmod($combo, 0600);
        $ctx = stream_context_create(['ssl' => [
            'local_cert' => $combo,
            'verify_peer' => true, 'verify_peer_name' => true,
        ], 'http' => ['timeout' => 12]]);
        $src = @fopen('https://mobilithek.info:8443/mobilithek/api/v1.0/container/subscription?subscriptionID=1061368693622788096', 'rb', false, $ctx);
        if (!$src) return false;
        $dst = @fopen($tmp, 'wb');
        if ($dst === false) { fclose($src); return false; }
        $ok = true;
        while (!feof($src)) {
            $chunk = fread($src, 1048576);
            if ($chunk === false || $chunk === '') continue;
            if (fwrite($dst, $chunk) === false) { $ok = false; break; }
        }
        fclose($src); fclose($dst);
    }
    $sz = $ok ? @filesize($tmp) : false;
    if ($sz === false || $sz < DFR_POOL_MIN || $sz > DFR_POOL_MAX) {
        @unlink($tmp);
        return false;
    }
    return @rename($tmp, $dest);   /* atomar: ggf. laufende Leser behalten den alten Strom */
}

/** v0.2.13: Pool-Strom-Parse nach dem PROVEN Feld-Baum von delfiproxy.php.
 *  Rueckgabe: ['trips' => id=>delayS, 'stops' => sid=>[[ts,del],..], 'n' => ..]
 *  - FeedEntity: feld 1 = id (string), feld 3 = TripUpdate
 *  - TripUpdate: feld 1 = TripDescriptor (1 trip_id, 2 start, 3 start_date,
 *    5 route_id), feld 2 = StopTimeUpdate (1 seq, 3 StopEvent {1 delay,
 *    2 time}, 4 stop_id, 5 schedule_relationship), zusaetzlich klassisch
 *    TripUpdate.delay = 5 (varint) falls der Strom viaje hat.
 *  - delay-Praeferenz: TripUpdate.delay, sonst ERSTE StopEvent-Abfahrts-delay,
 *    sonst negativ-verspaetete Restauffassung entfaellt (keine geplante Zeit).
 *  - 'stops'-Katalog-Eintrag nur, wenn StopEvent.time + delay vorliegen:
 *    geplante Passage = time - delay (Sekeliste je stop_id). */
function dfr_rt_pool_parse(string $pb): array {
    $trips = []; $stops = []; $n = 0;
    $today = date('Ymd');
    $yest  = date('Ymd', time() - 86400);
    /* FeedMessage: 1 = Header, 2 = FeedEntity; FeedEntity: 1 = id,
     * 3 = TripUpdate (Feld-Baum wie im bewaehrten delfiproxy.php-Parser). */
    foreach (dfr_pb_scan($pb) as [$fe, , $entBlk]) {
        if ($fe !== 2 || !is_string($entBlk)) continue;   /* FeedEntity */
        foreach (dfr_pb_scan($entBlk) as [$f1, , $d1]) {
            if ($f1 !== 3 || !is_string($d1)) continue;   /* FeedEntity.trip_update = 3 */
        $n++;
        $tripId = null; $tag = null; $tuDelay = null; $firstStop = null;
        foreach (dfr_pb_scan($d1) as [$f2, , $d2]) {
            if ($f2 === 1 && is_string($d2)) {          /* TripUpdate.trip (TripDescriptor) */
                foreach (dfr_pb_scan($d2) as [$f3, , $d3]) {
                    if ($f3 === 1 && is_string($d3)) $tripId = $d3;
                    elseif ($f3 === 3 && is_string($d3)) $tag = $d3;
                }
            } elseif ($f2 === 5 && is_int($d2)) {        /* TripUpdate.delay */
                $tuDelay = $d2;
            } elseif ($f2 === 2 && is_string($d2)) {     /* StopTimeUpdate */
                $seq = null; $delay = null; $time = null; $ref = null;
                foreach (dfr_pb_scan($d2) as [$f4, , $d4]) {
                    if ($f4 === 1 && is_int($d4)) $seq = $d4;
                    elseif ($f4 === 4 && is_string($d4)) $ref = $d4;
                    elseif ($f4 === 3 && is_string($d4)) {   /* StopEvent (dep/arr) */
                        foreach (dfr_pb_scan($d4) as [$f5, , $d5]) {
                            if ($f5 === 1 && is_int($d5)) $delay = $d5;
                            elseif ($f5 === 2 && is_int($d5)) $time = $d5;
                        }
                    }
                }
                if ($ref !== null && $time !== null && $delay !== null)
                    $stops[$ref][] = [$time - $delay, $delay];   /* geplante Passage + Verspaetung */
                if ($seq !== null && (int)$seq <= 1 && $delay !== null && $firstStop === null)
                    $firstStop = $delay;
            }
        }
        if ($tag !== null && $tag !== $today && $tag !== $yest) continue;
        $d = $tuDelay;
        if ($d === null) $d = $firstStop;
        if ($tripId !== null && $d !== null && $d > -3600 && $d < 21600)
            $trips[$tripId] = $d;
        }
    }
    return ['trips' => $trips, 'stops' => $stops, 'n' => $n];
}

/** v0.2.13: Katalog-Zustand (TTL DFR_MT_TTL): Pool-Strom + exakter
 *  DODP-Join. Rueckgabe: ['delays'=>nr=>delayS, 'trips'=>poolIdMap,
 *  'cat'=>sid=>[[ts,delay],...], 'exact'=>n, 'raw'=>n, 'poolAge'=>s|null]. */
function dfr_rt_dodp(DfrIdx $ix): array {
    static $cache = null; static $t0 = 0;
    if ($cache !== null && time() - $t0 < DFR_MT_TTL) return $cache;
    $empty = ['delays' => [], 'trips' => [], 'cat' => [],
        'exact' => 0, 'raw' => 0, 'poolAge' => null, 'catStops' => 0, 'ids' => []];
    $age = dfr_rt_pool_age();
    if ($age === null || $age > DFR_POOL_TTL) {
        $lk = @fopen(dfr_rt_pool_file() . '.lock', 'c');   /* gemeinsames Lock mit delfiproxy.php */
        if ($lk) {
            flock($lk, LOCK_EX);
            $age = dfr_rt_pool_age();
            if ($age === null || $age > DFR_POOL_TTL) dfr_rt_pool_fetch();
            $age = dfr_rt_pool_age();
            flock($lk, LOCK_UN);
            fclose($lk);
        }
    }
    if ($age === null) { $cache = $empty; $t0 = time(); return $cache; }
    $pb = (string)@file_get_contents(dfr_rt_pool_file());
    if (strlen($pb) < DFR_POOL_MIN) {
        $empty['poolAge'] = $age; $cache = $empty; $t0 = time(); return $cache;
    }
    $p = dfr_rt_pool_parse($pb);
    $delays = [];
    if ($p['trips']) {
        $fh = fopen($ix->dir . '/trips.tsv', 'rb');   /* Join trip_id -> tripNr */
        if ($fh !== false) {
            $nr = 0;
            while (($ln = fgets($fh, 4096)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"));
                if (count($t) >= 2 && isset($p['trips'][$t[1]]))
                    $delays[$nr] = $p['trips'][$t[1]];
                $nr++;
            }
            fclose($fh);
        }
    }
    $out = ['delays' => $delays, 'trips' => $p['trips'], 'cat' => $p['stops'],
        'exact' => count($delays), 'raw' => $p['n'], 'poolAge' => $age,
        'catStops' => count($p['stops']),
        'ids' => array_slice(array_keys($p['trips']), 0, 3)];
    $cache = $out; $t0 = time();
    return $out;
}

/** v0.2.13: Heuristik-Stufe des Katalogs - fuer Fahrt-Legs OHNE exakten
 *  Join wird am EINSTIEG die Passage gegen den Pool-Stop-Katalog geprueft
 *  (stop_id gleich, geplante Passage im DFR_HEUR_WIN-Fenster). Nachgetragen
 *  werden dD (Sekunden) und die +delay-Minuten in dep/arr der Leg. */
function dfr_rt_heur_apply(DfrIdx $ix, array $conns, array $cat): array {
    if (!$cat || !$conns) return $conns;
    static $stopIds = null;
    $used = [];
    foreach ($conns as $c) foreach (($c['legs'] ?? []) as $lg)
        if (($lg['k'] ?? '') === 'jny' && ($lg['dD'] ?? 0) === null
            && $lg['tid'] !== null) $used[(int)$lg['tid']] = true;
    if (!$used) return $conns;
    if ($stopIds === null) {                    /* stopNr -> stop_id, einmal je Prozess */
        $stopIds = [];
        $fh = @fopen($ix->dir . '/stops.tsv', 'rb');
        if ($fh !== false) {
            while (($ln = fgets($fh, 1024)) !== false) {
                $t = explode("\t", rtrim($ln, "\n"), 3);
                if (count($t) >= 2) $stopIds[] = $t[1];   /* Zeilennummer = stopNr */
            }
            fclose($fh);
        }
    }
    $d0 = DateTimeImmutable::createFromFormat('!Ymd', $ix->day, new DateTimeZone('Europe/Berlin'));
    $day0 = $d0 === false ? 0 : (int)$d0->getTimestamp();
    if ($day0 <= 0) return $conns;
    $hm2m = function (string $hm): int {
        $m = explode(':', $hm, 2);
        return count($m) === 2 ? (((int)$m[0]) * 60 + (int)$m[1]) : 0;
    };
    foreach (array_keys($used) as $tripNr) {
        $prof = dfr_tprof($ix, (int)$tripNr);
        if ($prof === null) continue;
        [, $pr] = $prof;
        $r = unpack('vseq/vdep/varr/Vstop', substr($pr, 0, 10));
        if ($r === false) continue;
        $sid = $stopIds[(int)$r['stop']] ?? null;
        if ($sid === null || !isset($cat[$sid])) continue;
        $plan = $day0 + (((int)$r['dep']) * 60);
        $best = null;
        foreach ($cat[$sid] as [$ts, $del]) {
            if ($ts + 43200 < $plan || $ts - 43200 > $plan) continue;   /* Tagesrand */
            $diff = abs($plan - $ts);
            if ($diff <= DFR_HEUR_WIN && ($best === null || $diff < $best[0]))
                $best = [$diff, $del];
        }
        if ($best === null) continue;
        $used[$tripNr] = $best[1];               /* delayS uebernehmen */
    }
    foreach ($conns as $ci => $c) {
        foreach ($c['legs'] ?? [] as $li => $lg) {
            if (($lg['k'] ?? '') !== 'jny' || $lg['tid'] === null
                || !is_int($used[(int)$lg['tid']] ?? null)) continue;
            $dS = $used[(int)$lg['tid']];
            $dM = $dS > 0 ? intdiv($dS, 60) : 0;
            $conns[$ci]['legs'][$li]['dD'] = $dS;
            if ($dM > 0) {
                $dep = dfr_min2hm(min(2879, $hm2m($lg['dep'] ?? '00:00') + $dM));
                $arr = dfr_min2hm(min(2879, $hm2m($lg['arr'] ?? '00:00') + $dM));
                $conns[$ci]['legs'][$li]['dep'] = $dep;
                $conns[$ci]['legs'][$li]['arr'] = $arr;
            }
        }
    }
    return $conns;
}

/* ========================================================== ROUTING */

/**
 * Kern-Engine (Multiplelokale: RMX-CSA-Variante mit Wellen).
 * Rueckgabe: ['conns' => [...]] oder ['err' => 'noaccess'|'noconns'].
 */
function dfr_route(DfrIdx $ix, float $fLa, float $fLo, float $tLa, float $tLo,
    int $depMin, int $walkR, int $limit, array $delays, int $tEnd,
    string $vm = 'walk', bool $dbgOn = false, ?array &$dbg = null): array {
    $accFrom = dfr_near_stops($ix, $fLa, $fLo, $walkR, DFR_ACC_MAX);
    $accTo = dfr_near_stops($ix, $tLa, $tLo, $walkR, DFR_ACC_MAX);
    if (!$accFrom || !$accTo) return ['err' => 'noaccess'];
    $walkTo = []; $walkFrom = [];
    foreach ($accTo as $a)
        $walkTo[$a[0]] = dfr_vm_wave($vm, $a[3], (float)$a[2]);   /* v0.2.12 */
    foreach ($accFrom as $a)
        $walkFrom[$a[0]] = [dfr_vm_wave($vm, $a[3], (float)$a[2]), $a[2], $a[1]];

    $rounds = DFR_ROUNDS; $scanMin = DFR_SCAN_MIN; $depCap = DFR_DEP_CAP;
    $wideTried = false;   /* v0.2.16: Early-Exit + Ziel-Hop-Wiederholung */
    while (true) {
    $tau = []; $parent = []; $marked = [];
    $finCut = 26100; $prevMinFin = -1; $stag = 0;   /* v0.2.14: Pruning/Stagnation */
    foreach ($accFrom as $a) {
        $t = $depMin + dfr_vm_wave($vm, $a[3], (float)$a[2]);
        if (!isset($tau[$a[0]]) || $t < $tau[$a[0]]) {
            $tau[$a[0]] = $t;
            $marked[] = $a[0];
        }
    }
    $bestFinish = [];
    if ($dbgOn) $dbg = [];
    for ($r = 0; $r < $rounds; $r++) {
        if (!$marked) break;
        $markedIn = count($marked);
        if (count($marked) > DFR_MARK_CAP) {
            $marked = array_values(array_unique($marked));
            usort($marked, fn($a, $b) => ($tau[$a] ?? 26100) <=> ($tau[$b] ?? 26100));
            if (count($marked) > DFR_MARK_CAP) {
                /* v0.2.13: Kappen VORHER: nur frueheste tau - fernen
                 * Durchfahrts-Halten wurde der Anschluss abgeschnitten.
                 * Neu: (a) Ferne-Frontier = die DFR_MARK_TAIL SPAETESTEN
                 * (Durchfahrts-Ketten laufen weiter, z. B. durchgehende
                 * S-Bahn/Ringbahn nach Potsdam), (b) Ziel-Zubringerstops
                 * (walkTo) sind unkappbar. */
                $head = array_slice($marked, 0, DFR_MARK_CAP);
                $tail = count($marked) > DFR_MARK_TAIL
                    ? array_slice($marked, -DFR_MARK_TAIL) : [];
                $acc = [];
                $seenAcc = [];
                foreach ($marked as $s) {
                    if (isset($walkTo[$s]) && !isset($seenAcc[$s])) {
                        $seenAcc[$s] = 1;
                        $acc[] = $s;
                    }
                }
                $marked = array_values(array_unique(array_merge($head, $tail, $acc)));
                if ($dbgOn) $dbg[] = ['round' => $r, 'in' => $markedIn,
                    'head' => count($head), 'tail' => count($tail),
                    'acc' => count($acc)];
            }
        } elseif ($dbgOn) {
            $dbg[] = ['round' => $r, 'in' => $markedIn, 'head' => $markedIn,
                'tail' => 0, 'acc' => 0];
        }
        $nxt = []; $nxtSet = []; $tripSeq = [];
        foreach ($marked as $stop) {
            if (time() > $tEnd) return ['err' => 'timeout', 'dbg' => $dbgOn ? $dbg : null];   /* v0.2.15: Wellenprofil auch bei Scheitern */
            $ts = $tau[$stop];
            if ($ts >= $finCut) continue;   /* v0.2.14: Ziel-Pruning (bestFin+KEEP_SLACK reicht nie ans Ziel) */
            $skey = 's' . $stop;
            if (!array_key_exists($skey, $ix->tCache)) $ix->tCache[$skey] = dfr_dprof($ix, $stop);
            $blk = $ix->tCache[$skey];
            if ($blk === null) continue;
            [$count, $recs] = $blk;
            $lo = 0; $hi = $count - 1; $first = $count;
            while ($lo <= $hi) {                        /* erste Abfahrt >= ts */
                $mid = ($lo + $hi) >> 1;
                $d = unpack('v', substr($recs, $mid * 8, 2))[1];
                if ($d >= $ts) { $first = $mid; $hi = $mid - 1; }
                else $lo = $mid + 1;
            }
            $seenDep = 0;   /* v0.2.15: RAPTOR-Naeherung, je Welle nur die ersten DFR_DEP_CAP Abfahrten */
            for ($i = $first; $i < $count; $i++) {
                $o = $i * 8;
                $dep = unpack('v', substr($recs, $o, 2))[1];
                if ($dep > $ts + $scanMin) break;   /* v0.2.16: Phase-Variable statt Konstante */
                if (++$seenDep > $depCap) break;   /* v0.2.16: Phase-Variable statt Konstante */
                $rr = substr($recs, $o, 8);
                $u = unpack('vdep/Vtrip/vseq', $rr);
                if (isset($tripSeq[$u['trip']]) && $u['seq'] >= $tripSeq[$u['trip']])
                    continue;                           /* Trip nur ab fruehester Seq */
                if (!dfr_trip_active($ix, $u['trip']))
                    continue;                           /* DODP: nur heute aktive Fahrten */
                $tripSeq[$u['trip']] = $u['seq'];
                if (!array_key_exists($u['trip'], $ix->tCache)) {
                    if (count($ix->tCache) > 400000) $ix->tCache = [];   /* v0.2.14: Speicher-Notkappe (502-Defense) */
                    $ix->tCache[$u['trip']] = dfr_tprof($ix, $u['trip']);
                }
                $prof = $ix->tCache[$u['trip']];
                if ($prof === null) continue;
                [$stops, $pr] = $prof;
                $delayS = $delays[$u['trip']] ?? 0;
                $dM = $delayS > 0 ? intdiv($delayS, 60) : 0;
                for ($k = 0; $k < $stops; $k++) {
                    $o2 = $k * 10;
                    $w2 = unpack('vseq/x2/varr/Vstop', substr($pr, $o2, 10));
                    if ($w2['seq'] <= $u['seq']) continue;
                    $ar = $w2['arr'] + $dM;
                    if ($ar > 65535) $ar = 65535;
                    $hs = $w2['stop'];
                    if (!isset($tau[$hs]) || $ar < $tau[$hs]) {
                        $tau[$hs] = $ar;
                        $parent[$hs] = 'j' . $u['trip'] . '|' . $u['seq'] . '|' .
                            $stop . '|' . ($dep + $dM) . '|' . $ar;
                        if (!isset($nxtSet[$hs])) { $nxtSet[$hs] = 1; $nxt[] = $hs; }
                    }
                }
            }
        }
        /* Transfers von allen verbesserten Stops (Parent + Geo) */
        foreach ($nxt as $hs) {
            $tCur = $tau[$hs] ?? null;
            if ($tCur === null) continue;
            foreach (dfr_trans_near($ix, $hs) as [$g, $wm, $tm]) {
                $mm = dfr_vm_wave($vm, $wm, $tm);   /* v0.2.12: Modus-Minuten */
                $t2 = $tCur + $mm;
                if (!isset($tau[$g]) || $t2 < $tau[$g]) {
                    $tau[$g] = $t2;
                    $parent[$g] = 'w' . $hs . '|' . $mm;
                    if (!isset($nxtSet[$g])) { $nxtSet[$g] = 1; $nxt[] = $g; }
                }
            }
        }
        $marked = $nxt;
        /* Finale: Zielfussweg pruefen */
        foreach ($walkTo as $tStop => $wmin) {
            if (isset($tau[$tStop])) {
                $fin = $tau[$tStop] + $wmin;
                if (!isset($bestFinish[$tStop]) || $fin < $bestFinish[$tStop])
                    $bestFinish[$tStop] = $fin;
            }
        }
        if ($bestFinish) {   /* v0.2.14: finCut nachfuehren + Stagnations-Abbruch */
            $minFin = min($bestFinish);
            $finCut = $minFin + DFR_KEEP_SLACK;
            $stag = ($prevMinFin >= 0 && $minFin >= $prevMinFin) ? $stag + 1 : 0;
            $prevMinFin = $minFin;
            if ($r >= 3 && $stag >= DFR_STAG_ROUNDS) { if ($dbgOn) $dbg[] = ['round' => $r, 'stag_break' => 1]; break; }
            /* v0.2.16: EARLY-EXIT - erste Zielankunft steht, Restbudget < 25-12 s */
            if (time() > $tEnd - (DFR_TIMEOUT_S - DFR_EARLY_S)) {
                if ($dbgOn) $dbg[] = ['round' => $r, 'early_break' => 1];
                break;
            }
        }
        if ($dbgOn) {
            $bestFin = $bestFinish ? min($bestFinish) : null;
            $cnt = count($dbg) - 1;
            $dbg[$cnt] = array_merge($dbg[$cnt] ?? [], ['nxt' => count($nxt), 'fin' => $bestFin]);
        }
    }
    if ($bestFinish) break;   /* v0.2.16: Zielankunft steht - Ketten bauen */
    if ($wideTried || time() > $tEnd) break;   /* Ziel-Hop genau einmal */
    $wideTried = true;
    $rounds = DFR_ROUNDS_WIDE; $scanMin = DFR_SCAN_WIDE; $depCap = DFR_DEP_WIDE;
    if ($dbgOn) $dbg[] = ['retry' => 'wide', 'scan' => $scanMin, 'cap' => $depCap,
        'rounds' => $rounds];
    }   /* v0.2.16: Ende Wiederholungs-Huelle; in der Wiederholung beendet die
         * erste Zielankunft die Suche ueber den Early-Exit (Restbudget klein) */
    if (!$bestFinish) return ['err' => 'noconns', 'dbg' => $dbgOn ? $dbg : null];   /* v0.2.16: dbg auch bei noconns */
    asort($bestFinish);
    /* v0.2.12: Kette je Ziellauf zuerst MIT Stop-Nummern einsammeln, Leg-
     * Aufbau danach mit Merge der Fuss-/Modus-Ketten an BEIDEN Enden. */
    $raw = []; $seen = [];
    foreach ($bestFinish as $tStop => $fin) {
        if (count($raw) >= $limit) break;
        $chain = []; $lines = 0;
        $cur = (int)$tStop;
        for ($n = 0; $n < 40; $n++) {   /* Kette rueckwaerts, REVERSE unten */
            $pd = $parent[$cur] ?? null;
            if ($pd === null) break;
            if ($pd[0] === 'j') {
                $pp = explode('|', substr($pd, 1));   /* v0.2.11: Praefix-Abtrennung */
                $chain[] = ['k' => 'jny', 'trip' => (int)$pp[0],
                    'seq' => (int)$pp[1], 'stop' => (int)$pp[2],
                    'dep' => (int)$pp[3], 'arr' => (int)$pp[4], 'cur' => $cur];
                $lines++;
                $cur = (int)$pp[2];
            } else {
                $pp = explode('|', substr($pd, 1));   /* v0.2.11: Praefix-Abtrennung */
                $chain[] = ['k' => 'walk', 'from' => (int)$pp[0], 'cur' => $cur,
                    'mins' => (int)$pp[1]];
                $cur = (int)$pp[0];
            }
        }
        if (!isset($walkFrom[$cur])) continue;
        $steps = array_merge(
            [['k' => 'start', 'stop' => $cur,
              'mins' => (int)$walkFrom[$cur][0], 'dist' => (int)$walkFrom[$cur][1],
              'name' => $walkFrom[$cur][2]]],
            array_reverse($chain),
            [['k' => 'finish', 'stop' => (int)$tStop,
              'mins' => (int)$walkTo[$tStop]]]);
        $sig = [];
        foreach ($steps as $st2)
            if ($st2['k'] === 'jny') $sig[] = 'j' . $st2['trip'] . '@' . $st2['stop'];
        /* v0.2.12: Dedupe nur ueber Transit-Legs (Hop-Zwillinge entfallen) */
        $sigKey = implode('|', $sig);
        if (isset($seen[$sigKey])) continue;
        $seen[$sigKey] = 1;
        $raw[] = [$steps, $lines, (int)$fin];
    }
    if (!$raw) return ['err' => 'noconns'];
    $conns = [];
    foreach ($raw as [$steps, $lines, $fin]) {
        $fj = -1; $lj = -1;
        $ns = count($steps);
        for ($i = 0; $i < $ns; $i++) {
            if ($steps[$i]['k'] === 'jny') { if ($fj < 0) $fj = $i; $lj = $i; }
        }
        $arrMin = $fin;
        if ($fj < 0) {
            /* kompletter Lauf ohne OEPNV: EINE Laufleg Start -> Ziel */
            [$dd, $mm] = dfr_leg_calc($vm, $fLa, $fLo, $tLa, $tLo);
            $legs = [['k' => 'walk', 'from' => 'Start', 'to' => 'Ziel',
                'mins' => $mm, 'dist' => $dd, 'vm' => $vm]];
            $arrMin = $depMin + $mm;
        } else {
            $legs = [];
            $board = (int)$steps[$fj]['stop'];
            $alight = (int)$steps[$lj]['cur'];
            $bll = dfr_stop_ll($ix, $board);
            if ($bll !== null) {
                [$dd, $mm] = dfr_leg_calc($vm, $fLa, $fLo, $bll[0], $bll[1]);
                $legs[] = ['k' => 'walk', 'from' => 'Start',
                    'to' => dfr_stop_name($ix, $board),
                    'mins' => $mm, 'dist' => $dd, 'vm' => $vm];
            } else {
                $legs[] = ['k' => 'walk', 'from' => 'Start',
                    'to' => dfr_stop_name($ix, $board),
                    'mins' => (int)$steps[0]['mins'],
                    'dist' => (int)$steps[0]['dist'], 'vm' => $vm];
            }
            for ($i = $fj; $i <= $lj; $i++) {
                $st = $steps[$i];
                if ($st['k'] === 'jny') {
                    $dl = $delays[$st['trip']] ?? null;
                    $legs[] = ['k' => 'jny',
                        'line' => dfr_trip_label($ix, $st['trip']),
                        'dir' => dfr_trip_headsign($ix, $st['trip']),
                        'from' => dfr_stop_name($ix, $st['stop']),
                        'to' => dfr_stop_name($ix, $st['cur']),
                        'dep' => dfr_min2hm($st['dep']),
                        'arr' => dfr_min2hm($st['arr']),
                        'dD' => $dl,
                        'tid' => $st['trip'],
                        'stops' => dfr_trip_seg($ix, $st['trip'], $st['seq'],
                            $st['stop'], $st['cur'], $st['dep'], $st['arr'],
                            ($dl !== null && $dl > 0) ? intdiv((int)$dl, 60) : 0),
                    ];
                } else {
                    $legs[] = ['k' => 'walk',
                        'from' => dfr_stop_name($ix, $st['from']),
                        'to' => dfr_stop_name($ix, $st['cur']),
                        'mins' => (int)$st['mins'], 'dist' => null,
                        'vm' => $vm];
                }
            }
            $arrMin = (int)$steps[$lj]['arr'];
            $all2 = dfr_stop_ll($ix, $alight);
            if ($all2 !== null) {
                [$dd, $mm] = dfr_leg_calc($vm, $all2[0], $all2[1], $tLa, $tLo);
                $legs[] = ['k' => 'walk',
                    'from' => dfr_stop_name($ix, $alight), 'to' => 'Ziel',
                    'mins' => $mm, 'dist' => $dd, 'vm' => $vm];
                $arrMin += $mm;
            } else {
                $mm = 0;
                for ($i = $lj + 1; $i < $ns; $i++)
                    $mm += (int)$steps[$i]['mins'];
                $legs[] = ['k' => 'walk',
                    'from' => dfr_stop_name($ix, $alight), 'to' => 'Ziel',
                    'mins' => $mm, 'dist' => null, 'vm' => $vm];
                $arrMin += $mm;
            }
        }
        $depL = null;
        foreach ($legs as $lg) if ($lg['k'] === 'jny') { $depL = $lg['dep']; break; }
        if ($depL === null) $depL = dfr_min2hm($depMin);
        $dur = ($arrMin >= $depMin) ? $arrMin - $depMin : $arrMin + 2880 - $depMin;
        $conns[] = [$arrMin, ['dep' => $depL, 'arr' => dfr_min2hm($arrMin),
            'dur' => $dur, 'chg' => max(0, $lines - 1), 'legs' => $legs]];
    }
    usort($conns, fn($a, $b) => $a[0] <=> $b[0]);   /* v0.2.12: nach echter Ankunft */
    $outC = [];
    foreach ($conns as $c) $outC[] = $c[1];
    return ['conns' => $outC];
}

/* ============================================================= I/O */

function dfr_out(array $j, int $httpCode = 200): void {
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
    }
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') echo "\n";
    exit;
}

/** v0.2.3: Zeilen als STROM zaehlen (O(1) RAM). count(file()) haelt die
 *  komplette Datei als Array von Strings, DODP-Stand sind 1,3 Mio bzw.
 *  1,9 Mio Zeilen; am Speicher-Limit eines Web-SAPI war das ein sofortiger
 *  Memory-Fatal (HTTP 500 ohne Body). Rueckgabe -1 = nicht lesbar. */
function dfr_linecount(string $p): int {
    $fh = @fopen($p, 'rb');
    if ($fh === false) return -1;
    $n = 0;
    while (fgets($fh, 8192) !== false) $n++;
    @fclose($fh);
    return $n;
}
/** v0.2.3: Dateigroesse in MiB (gerundet), 0.0 bei Fehler. */
function dfr_mb(string $p): float {
    $sz = @filesize($p);
    return ($sz === false) ? 0.0 : round($sz / 1048576.0, 1);
}
function dfr_probe(): array {
    $ix = dfr_idx();
    $st = dfr_state_get();
    if (!is_array($st)) $st = [];
    $idx = [];
    if ($ix->dir !== '') {
        $idx = [
            'day' => $ix->day,
            'src' => $ix->src,
            'is_today' => $ix->day === dfr_day_zo(),
            'stops' => null, 'trips' => null,
            'done' => is_file($ix->dir . '/DONE'),   /* v0.2.3: fin-Stand des Index */
        ];
        if ($ix->src === 'dodp') {
            $idx['day0'] = $ix->day0;
            $idx['valid_until'] = $ix->validUntil;
            $idx['day_index'] = $ix->dayIdx;
        }
        /* v0.2.3: Strom-Zaehlung + Groessen statt count(file()) */
        if (is_file($ix->dir . '/stops.tsv')) {
            $idx['stops'] = dfr_linecount($ix->dir . '/stops.tsv');
            $idx['stops_mb'] = dfr_mb($ix->dir . '/stops.tsv');
        }
        if (is_file($ix->dir . '/trips.tsv')) {
            $idx['trips'] = dfr_linecount($ix->dir . '/trips.tsv');
            $idx['trips_mb'] = dfr_mb($ix->dir . '/trips.tsv');
        }
        if (is_file($ix->dir . '/tripday.bin')) $idx['tripday_mb'] = dfr_mb($ix->dir . '/tripday.bin');
        /* v0.2.9: Profil-Gesundheit (Bucket 000): Ein intakter Index hat je
         * nach Verkehrsdichte einige MB je dprof/tprof-Bucket; nach den
         * Format-Zerstoerern (v0.2.6/v0.2.8) kollabierten die Profile auf
         * knapp unter 0,3 MB. Die App gibt ihr delfirouting-Backend erst
         * frei, wenn tprof_mb >= 1 ist (und done + valid_until stimmen). */
        if (is_file($ix->dir . '/dprof_000.bin'))
            $idx['dprof_mb'] = dfr_mb($ix->dir . '/dprof_000.bin');
        if (is_file($ix->dir . '/tprof_000.bin'))
            $idx['tprof_mb'] = dfr_mb($ix->dir . '/tprof_000.bin');
    }
    $dz = null;
    $dz2 = dfr_dodp_find();
    if ($dz2 !== null)
        $dz = ['file' => basename($dz2[0]), 'age_h' => (int)((time() - $dz2[1]) / 3600)];
    /* v0.2.3: laeuft gerade ein Bau-Prozess (Import/warm)? build.lock anfassen,
     * ohne ihn zu halten: Erwerb gelingt nur, wenn KEIN anderer Baeuer ihn haelt. */
    $lf = @fopen(dfr_base() . '/build.lock', 'c');
    $building = null;
    if ($lf !== false) {
        $building = !@flock($lf, LOCK_EX | LOCK_NB);
        if (!$building) @flock($lf, LOCK_UN);   /* nur geprueft, sofort wieder frei */
        @fclose($lf);
    }
    /* v0.2.13: Echtzeit-Katalog-Zustand (DELFI-Pool, exakter DODP-Join).
     * probe ist Wartungsoberflaeche: Nach dem Deploy zeigt 'exact' sofort,
     * ob die Pool-Trip-IDs die DODP-trips.tsv-IDs treffen (Soll: > 0,
     * realistisch: grosser Anteil von raw); 'ids' gibt drei Pool-Proben zum
     * Abgleich mit trips.tsv-Spalte 2. */
    $rtcat = ['pool_age' => dfr_rt_pool_age(),
        'pool_size' => is_file(dfr_rt_pool_file()) ? @filesize(dfr_rt_pool_file()) : null,
        'certs' => is_file(dfr_rt_pool_certdir() . '/cert.pem')
            && is_file(dfr_rt_pool_certdir() . '/key.pem')];
    if ($ix->dir !== '' && $ix->src === 'dodp' && $ix->day === dfr_day_zo()) {
        $cat = dfr_rt_dodp($ix);
        $rtcat['raw'] = $cat['raw'];
        $rtcat['exact'] = $cat['exact'];
        $rtcat['cat_stops'] = $cat['catStops'];
        $rtcat['pool_ids'] = $cat['ids'];
    }
    return ['ok' => true, 'v' => DFR_V,
        'src' => $ix->dir !== '' ? $ix->src : 'none',
        'phase' => $st['phase'] ?? 'idle',
        'state_day' => $st['day'] ?? '', 'state_day0' => $st['day0'] ?? '',
        'idx' => $idx, 'dodp_zip' => $dz, 'rtcat' => $rtcat,
        'building' => $building,
        'php' => PHP_VERSION, 'zip' => class_exists('ZipArchive'),
        'sapi' => PHP_SAPI, 'mem' => @ini_get('memory_limit')];
}

/** v0.2.5: Tiefen-Diagnose des Tagesindex (?diag=1). Liefert Beweise aus dem
 *  fertigen Index, wenn Suchanfragen keine Fahrten finden: (1) wie viele
 *  Fahrten im tripday-Bitmap fuer DEN heutigen dayIdx aktiv sind (Strom in
 *  1-MiB-Bloecken statt Riesen-Array), (2) der erste nicht-leere dprof-Block
 *  (Abfahrten eines Stops) mit Stichproben-Eintraegen samt Aktiv-Bit und
 *  Linien-Label, (3) erster nicht-leerer tprof-Block (Fahrtprofil), (4)
 *  erster nicht-leerer Transfer-Block, (5) Dateigroessen der Kern-Indizes.
 *  Deckt leer oder falsch gelaufene Bau-Phasen ohne Shell-Zugriff auf. */
function dfr_diag(): array {
    $ix = dfr_idx();
    if ($ix->dir === '')
        return ['ok' => false, 'code' => 'nodata', 'v' => DFR_V,
            'msg' => 'Kein Tagesindex.'];
    $out = ['ok' => true, 'v' => DFR_V, 'src' => $ix->src, 'day' => $ix->day,
        'day0' => $ix->day0, 'dayIdx' => $ix->dayIdx,
        'valid_until' => $ix->validUntil];

    /* (1) tripday-Bitmap heute: aktive Fahrten als Strom zaehlen */
    $act = 0; $rows = 0;
    $fh = @fopen($ix->dir . '/tripday.bin', 'rb');
    if ($fh === false) {
        $out['tripday'] = 'missing';
    } else {
        $byte = $ix->dayIdx >> 3; $bit = $ix->dayIdx & 7;
        $rows = intdiv((int)@filesize($ix->dir . '/tripday.bin'), 16);
        for ($t0 = 0; $t0 < $rows; $t0 += 65536) {
            $ct = min(65536, $rows - $t0);
            @fseek($fh, $t0 * 16);
            $c = (string)@fread($fh, $ct * 16);
            $cl = strlen($c);
            for ($i = 0; $i < $ct; $i++) {
                $p = $i * 16 + $byte;
                if ($p >= $cl) break;
                if ((ord($c[$p]) >> $bit) & 1) $act++;
            }
        }
        @fclose($fh);
        $out['trip_rows'] = $rows;
        $out['trip_active_today'] = $act;
    }

    /* (2) erster nicht-leerer dprof-Block (Stop-Abfahrten) */
    $sd = null;
    for ($s = 0; $s < 30000; $s++) {
        if (dfr_dprof($ix, $s) !== null) { $sd = $s; break; }
    }
    if ($sd === null) {
        $out['dprof'] = 'none in stopNr 0..29999';
    } else {
        $d = dfr_dprof($ix, $sd);
        $first = [];
        for ($i = 0; $i < min(3, $d[0]); $i++) {
            $u = unpack('vdep/Vtrip/vseq', substr($d[1], $i * 8, 8));
            $first[] = ['dep' => $u['dep'], 'trip' => $u['trip'],
                'seq' => $u['seq'],
                'active' => dfr_trip_active($ix, $u['trip']),
                'label' => dfr_trip_label($ix, $u['trip'])];
        }
        $out['dprof'] = ['stopNr' => $sd,
            'name' => dfr_stop_name($ix, $sd),
            'll' => dfr_stop_ll($ix, $sd),
            'cnt' => $d[0], 'first' => $first];
    }

    /* (3) erster nicht-leerer tprof-Block (Fahrtprofil) */
    $tt = null; $td = null;
    for ($t = 0; $t < 30000; $t++) {
        $d = dfr_tprof($ix, $t);
        if ($d !== null) { $tt = $t; $td = $d; break; }
    }
    if ($tt === null) {
        $out['tprof'] = 'none in tripNr 0..29999';
    } else {
        $first = [];
        for ($i = 0; $i < min(3, $td[0]); $i++) {
            $u = unpack('vseq/vdep/varr/Vstop', substr($td[1], $i * 10, 10));
            $first[] = ['seq' => $u['seq'], 'dep' => $u['dep'],
                'arr' => $u['arr'], 'stopNr' => $u['stop'],
                'stop' => dfr_stop_name($ix, $u['stop'])];
        }
        $out['tprof'] = ['tripNr' => $tt,
            'label' => dfr_trip_label($ix, $tt),
            'active' => dfr_trip_active($ix, $tt),
            'cnt' => $td[0], 'first' => $first];
    }

    /* (4) erster nicht-leerer Transfer-Block */
    $tr = null;
    for ($s = 0; $s < 30000; $s++) {
        $b = dfr_trans_block($ix, $s);
        if (!empty($b)) { $tr = $s; break; }
    }
    $out['trans'] = $tr === null ? 'none in stopNr 0..29999'
        : ['stopNr' => $tr, 'name' => dfr_stop_name($ix, $tr),
            'pairs' => array_slice(dfr_trans_block($ix, $tr), 0, 3)];

    /* (5) Dateigroessen der Kern-Indexdateien */
    $out['sizes'] = [];
    foreach (['nidx.bin','nms.bin','sll.bin','trline.bin','didx_000.bin',
        'dprof_000.bin','tidx_000.bin','tprof_000.bin','trx_000.bin',
        'trans_000.bin'] as $f) {
        $p = $ix->dir . '/' . $f;
        $out['sizes'][$f] = is_file($p) ? (int)@filesize($p) : -1;
    }
    return $out;
}

function dfr_query_run(array $q): void {
    $t0 = microtime(true);
    $ix = dfr_idx();
    if ($ix->dir === '') {
        dfr_out(['ok' => false, 'code' => 'nodata',
            'msg' => 'Noch kein Tagesindex. Erst import=1 bzw. warm=1 ausfuehren.']);
    }
    $from = $q['from'] ?? ''; $to = $q['to'] ?? '';
    $depS = $q['dep'] ?? '';
    $walk = (int)($q['walk'] ?? 1500);
    $limit = (int)($q['limit'] ?? DFR_RESULTS);
    $vm = strtolower(trim((string)($q['vm'] ?? 'walk')));
    if ($walk < 200) $walk = 200;
    if ($walk > 5000) $walk = 5000;
    if ($limit < 1) $limit = 1;
    if ($limit > 8) $limit = 8;
    if ($vm !== 'walk' && !isset(DFR_VM_KMH[$vm])) $vm = 'walk';   /* v0.2.12 */
    $fm = explode(',', $from); $tm = explode(',', $to);
    if (count($fm) !== 2 || count($tm) !== 2 ||
        !is_numeric($fm[0]) || !is_numeric($fm[1]) ||
        !is_numeric($tm[0]) || !is_numeric($tm[1])) {
        dfr_out(['ok' => false, 'code' => 'params',
            'msg' => 'from/to muessen lat,lon sein (dezimal).']);
    }
    $fLa = (float)$fm[0]; $fLo = (float)$fm[1];
    $tLa = (float)$tm[0]; $tLo = (float)$tm[1];
    if ($fLa < 47 || $fLa > 55.6 || $tLo < 5.8 || $tLo > 15.2 ||
        $tLa < 47 || $tLa > 55.6 || $fLo < 5.8 || $fLo > 15.2) {
        dfr_out(['ok' => false, 'code' => 'params',
            'msg' => 'Koordinaten ausserhalb Deutschlands.']);
    }
    $depMin = null;
    if ($depS !== '') $depMin = dfr_hm2min($depS);
    if ($depMin === null) {
        $depMin = (int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))
            ->format('H') * 60 +
            (int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('i');
    }
    $delays = [];
    $rt = 0;
    $rtSrc = '';
    $heur = 0;
    $cat = null;
    if ($ix->day === dfr_day_zo()) {
        if ($ix->src === 'free') {
            $delays = dfr_rt_delays($ix);    /* free: gtfs.de-Strom, Kurz-IDs */
            $rt = count($delays);
            if ($rt > 0) $rtSrc = 'free';
        } else {                              /* DODP: DELFI-Pool-Katalog (v0.2.13) */
            $cat = dfr_rt_dodp($ix);
            $delays = $cat['delays'];
            $rt = count($delays);
            if ($rt > 0 || $cat['raw'] > 0) $rtSrc = 'pool';
        }
    }
    $dbg = [];
    $wantDbg = isset($q['dbg']);
    $res = dfr_route($ix, $fLa, $fLo, $tLa, $tLo, $depMin, $walk, $limit, $delays,
        (int)($t0 + DFR_TIMEOUT_S), $vm, $wantDbg, $dbg);   /* v0.2.4: microtime-Sekunden als FLOAT,
                                          int-Parameter verlangte strict_types einen
                                          int-Cast (TypeError-Fix der Query-500er) */
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if (isset($res['err'])) {
        $msg = $res['err'] === 'noaccess'
            ? 'Keine OEPNV-Haltestellen im Zubringerbereich von Start und/oder Ziel.'
            : ($res['err'] === 'timeout'
                ? 'Anfrage ueberschreitet das Zeitlimit; bitte erneut versuchen.'
                : 'Keine Verbindung gefunden.');
        if (($res['err'] === 'timeout' || $res['err'] === 'noconns') && $wantDbg && is_array($res['dbg']))
            dfr_out(['ok' => false, 'code' => $res['err'], 'dbg' => $res['dbg'],
                'day' => $ix->day, 'ms' => $ms, 'msg' => $msg]);   /* v0.2.15: Diagnose bei Timeout; v0.2.16: auch noconns */
        else dfr_out(['ok' => false, 'code' => $res['err'],
            'day' => $ix->day, 'ms' => $ms, 'msg' => $msg]);
    }
    $conns = $res['conns'];
    /* v0.2.13: Heuristik-Stufe des Katalogs NACH der Welle (Anzeige-Ebene):
       Legs mit tid, aber ohne exakten Pool-Join, bekommen die Verspaetung
       aus dem stop_id-/Zeitfenster-Katalog nachgetragen. */
    if ($cat !== null && $cat['cat']) {
        $cntD = function (array $cs): int {
            $n = 0;
            foreach ($cs as $c) foreach (($c['legs'] ?? []) as $lg)
                if (($lg['k'] ?? '') === 'jny' && ($lg['dD'] ?? null) !== null) $n++;
            return $n;
        };
        $b = $cntD($conns);
        $conns = dfr_rt_heur_apply($ix, $conns, $cat['cat']);
        $heur = $cntD($conns) - $b;
        if ($heur > 0) $rtSrc = $rtSrc === '' ? 'pool-heur' : 'pool+heur';
    }
    $out = [
        'ok' => true, 'v' => DFR_V, 'day' => $ix->day,
        'src' => $ix->src,
        'rt' => $rt, 'rt_src' => $rtSrc, 'rt_heur' => $heur, 'ms' => $ms,
        'dep_min' => $depMin, 'walk' => $walk, 'vm' => $vm,
        'conns' => array_slice($conns, 0, $limit),
    ];
    if ($wantDbg && is_array($dbg)) $out['dbg'] = $dbg;   /* v0.2.13: &dbg=1 */
    if ($ix->src === 'dodp') {
        $out['day0'] = $ix->day0;
        $out['valid_until'] = $ix->validUntil;
    }
    dfr_out($out);
}

/* ============================================================== MAIN */

(function () {
    $cli = PHP_SAPI === 'cli';
    $q = [];
    if ($cli) {
        global $argv;
        foreach (array_slice($argv ?? [], 1) as $a) {
            $p = explode('=', $a, 2);
            if (count($p) === 2) $q[$p[0]] = $p[1];
            else $q[$a] = '1';
        }
    } else {
        $q = $_GET;
    }
    if (!is_dir(dfr_base()) && !$cli) @mkdir(dfr_base(), 0755, true);

    $mode = 'query';
    if (isset($q['import'])) $mode = 'import';
    elseif (isset($q['warm'])) $mode = 'warm';
    elseif (isset($q['probe'])) $mode = 'probe';
    elseif (isset($q['diag'])) $mode = 'diag';
    elseif (isset($q['trip'])) $mode = 'trip';   /* v0.2.11: ganzer Lauf einer Fahrt */

    /* v0.2.3: Fatale Fehler (TypeError/ValueError/Exception usw.) vorher wurden
     * zum leeren HTTP 500 (display_errors aus); sie kommen jetzt als JSON
     * {ok:false,code:fatal} mit Klasse, Meldung und Zeile zurueck. Ein
     * Memory-Fatal bleibt weiterhin unaufhaltbar leer - die bekannten
     * Riesen-Ladungen sind dafuer in dieser Version beseitigt. */
    try {
    switch ($mode) {
        case 'import':
            if (!$cli) {
                dfr_out(['ok' => false, 'code' => 'clionly',
                    'msg' => 'import ist nur per CLI/Cron erlaubt (php ...delfirouting.php import=1).']);
            }
            $budget = DFR_IMPORT_S;
            if (isset($q['budget'])) {
                $b2 = (int)$q['budget'];
                if ($b2 >= 0) $budget = $b2;    /* budget=0: Langlaeufer ohne Limit */
            }
            @ini_set('memory_limit', '1024M');
            if (isset($q['repair'])) dfr_repair();   /* v0.2.6: stimes-Neubau ohne svc/tabs */
            $r = dfr_build($budget);
            echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            exit(($r['ok'] ?? false) ? 0 : 1);
        case 'warm':
            @set_time_limit(DFR_BUDGET_S + 30);
            @ini_set('memory_limit', '1024M');   /* v0.2.3: wie import; die Resume-Pfade laden stopmap+tripmap, an 512M starb das */
            $r = dfr_build(DFR_BUDGET_S);
            dfr_out($r);
        case 'probe':
            @set_time_limit(90);   /* v0.2.3: Strom-Zaehlung von bis zu 3,2 Mio Zeilen */
            dfr_out(dfr_probe());
        case 'diag':
            @set_time_limit(60);   /* v0.2.5: Bitmap-Strom + Block-Samples */
            dfr_out(dfr_diag());
        case 'trip':   /* v0.2.11: ?trip=<TripNr> - ganzer Lauf (Soll, aus tprof) */
            @set_time_limit(30);
            $ixT = dfr_idx();
            if ($ixT->dir === '') {
                dfr_out(['ok' => false, 'code' => 'nodata', 'v' => DFR_V,
                    'msg' => 'Noch kein Tagesindex. Erst import=1 bzw. warm=1 ausfuehren.']);
            }
            dfr_out(dfr_trip_out($ixT, (int)($q['trip'] ?? 0)));
        default:
            if (isset($q['from']) && isset($q['to'])) {
                @ini_set('memory_limit', DFR_MEM);
                dfr_query_run($q);
            }
            dfr_out(['ok' => false, 'code' => 'params',
                'msg' => 'Parameter: from=lat,lon to=lat,lon dep=HH:MM walk=200..5000 limit=1..8 vm=walk|bike|ebike|scoot dbg=1 | probe=1 | warm=1 | trip=<TripNr>']);
    }
    } catch (Throwable $e) {
        dfr_out(['ok' => false, 'code' => 'fatal',
            'msg' => get_class($e) . ': ' . $e->getMessage(),
            'file' => basename($e->getFile()), 'line' => $e->getLine(),
            'v' => DFR_V, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
            'mem' => @ini_get('memory_limit')]);
    }
})();

