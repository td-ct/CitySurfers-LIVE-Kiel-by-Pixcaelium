<?php
/* donkeyproxy.php – Mini-Proxy für CitySurfers LIVE @ Kiel (v1.3.17+, Akku-Fix v1.4.6, Rad-Namen v1.4.16, Namen-Cache v1.4.18, Datenverzeichnis v1.4.19)
 * ---------------------------------------------------------------------------
 * Zweck: Die öffentlichen GBFS-3.0-Feeds von Donkey Republic / SprottenFlotte
 * (Kiel: Systeme donkey_kiel, donkey_kielsmile und donkey_schleswig) senden
 * keine CORS-Header und sind deshalb im Browser nicht direkt abrufbar. Dieser
 * Proxy holt die Feeds serverseitig, führt die drei Systeme zusammen,
 * dedupliziert Fahrzeuge und Stationen und liefert sie entweder als kompaktes
 * JSON (App-intern) oder als GeoJSON FeatureCollection (für uMap, Parameter
 * ?geojson=1, dort via dataUrl=... serverseitig geladen).
 *
 * Aufrufe:
 *   donkeyproxy.php          → { "ts": unixzeit, "ttl": 60,
 *                               "v": [ rad-objekte ... ],
 *                               "s": [ stations-objekte ... ] }
 *   donkeyproxy.php?geojson=1 → GeoJSON FeatureCollection (Fahrzeuge + Stationen)
 *
 * Fahrzeug-Objekt (v): id, t ("bike"|"ebike"|"cargo"|"ecargo"|...), lat, lon,
 *   fu (current_fuel_percent, 0-100, kann null sein; Donkey liefert 0..1, wird
 *   v1.4.6 vor dem Runden auf Prozent gehoben), rm (current_range_meters),
 *   rs (is_reserved), dis (is_disabled), st (station_id, wenn bekannt),
 *   dl (miet-deeplink aus rental_uris.android/ios/web, wenn vorhanden),
 *   n (Vorname des Rads aus der Donkey-Hub-API, v1.4.16; null, wenn die
 *   Hub-API gerade keinen Namen liefert; app-seitig optional).
 * Stations-Objekt (s): id, name, lat, lon, cap (capacity, kann null sein).
 *
 * v1.4.16 Rad-Namen: Donkey vergibt jedem Rad einen Namen, der GBFS-Feed kennt
 * sie aber nicht. Die undokumentierte Hub-API
 *   https://stables.donkey.bike/api/public/hubs/hub_{STATION_ID}/vehicles
 *   (Header: Accept: application/com.donkeyrepublic.v7)
 * liefert je Hub alle Fahrzeuge mit name, latitude, longitude (Strings),
 * battery_level, state. hub-ID == GBFS-station_id. Der Proxy fragt nach dem
 * Feed-Sammeln alle Stationen mit mindestens einem Rad parallel via curl_multi
 * ab (Fallback ohne curl: sequenziell, begrenzt) und ordnet die Namen per
 * Position zu (lat/lon auf 5 Dezimalstellen gerundet, ca. 1 m Toleranz),
 * weil die Hub-API andere IDs als GBFS hat. Findet die Hub-API zu einer
 * Position keinen Namen, bleibt n null; das Befüllen ist Best Effort und
 * darf den Feed-Lauf niemals scheitern lassen.
 * v1.4.18: Die Hub-Abfrage schlägt auf dem Webserver für einzelne Stationen
 * gelegentlich fehl (Timeout, ~170 parallele Abfragen). Die Namen werden deshalb
 * je Station in donkeydata/donkeynames.json gecacht (Ablauf gestreut 6 bis 12
 * Stunden, flock beim Schreiben); fehlgeschlagene Stationen bleiben ohne Cache-
 * Eintrag und werden beim nächsten Aufruf erneut probiert, bis alle Namen
 * stehen. Die Hub-Last sinkt damit auf einen Bruchteil.
 * v1.4.19: Einheitliche Datenverzeichnisse wie bei gtfsproxy (gtfsdata/) und
 * delfiproxy (delfidata/): alle Cache-Dateien liegen jetzt in donkeydata/
 * neben diesem Skript. Der Proxy legt das Verzeichnis SELBST an, falls es
 * fehlt; existieren noch alte Cache-Dateien direkt neben dem Skript, werden
 * sie beim ersten Aufruf automatisch dorthin verschoben (Bestand bleibt
 * erhalten). Ist das Verzeichnis nicht beschreibbar, entfällt wie bisher nur
 * der Cache-Mechanismus und jede Anfrage fragt die Upstream-Feeds direkt ab.
 *
 * Cache: Datei donkeydata/donkeycache.json, 60 Sekunden, mit flock gesichert;
 * bei Upstream-Fehlern wird ein alter Cache-Stand noch gnädig ausgeliefert
 * (grace), damit die App kurzzeitige Feed-Störungen nicht merkt.
 *
 * Empfehlung Deployment: https://pixcaelium.timo-damm.de/apps/citysurfers/
 * (ins selbe Verzeichnis wie die index.html); donkeydata/ muss für PHP
 * schreibbar sein (wird sonst selbst angelegt), sonst entfällt nur der
 * Cache-Mechanismus und jede Anfrage fragt die Upstream-Feeds direkt ab.
 */

declare(strict_types=1);

const DP_SYSTEMS   = ['donkey_kiel', 'donkey_kielsmile', 'donkey_schleswig'];
const DP_FEEDBASE  = 'https://stables.donkey.bike/api/public/gbfs/3.0/';
const DP_TTL_S     = 60;          // Sekunden Cache-Haltbarkeit (Feeds haben TTL 60 s)
const DP_TIMEOUT_S = 7;           // Socket-Timeout je Upstream-Abfrage
const DP_UA        = 'CitySurfersLIVE-Kiel/1.4.16 (+https://pixcaelium.timo-damm.de/apps/citysurfers/)';

/* HTTP-GET mit Timeout, gibt dekodiertes JSON oder null zurück. */
function dp_fetch_json(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => DP_TIMEOUT_S,
        'ignore_errors' => true,
        'user_agent'    => DP_UA,
        'header'        => "Accept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/* Ein GBFS-Feed für ein System; null bei jedem Fehler. */
function dp_feed(string $system, string $file): ?array {
    $j = dp_fetch_json(DP_FEEDBASE . rawurlencode($system) . '/' . $file);
    if (!is_array($j) || !isset($j['data'])) return null;
    // GBFS 3.0: { "data": { "vehicles": [...] } } bzw. { "data": { "stations": [...] } },
    // je Feed-Typ genau ein Schluessel – gemeinsam mit "updated_at".
    $data = $j['data'];
    foreach (['vehicles', 'stations'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
    }
    return null;
}

/* Stationsnamen: Donkey liefert GBFS 3.0 mehrsprachig als
 * [{language: "en", text: "Rathausplatz"}, ...] – bevorzugt de, sonst en,
 * sonst erster Text; Filter/dedupe wie bisher, Fallback 'Station <id>'.
 * address/post_code sind im Kiel-Feed meist leer, werden aber ab v1.4.4
 * als ad/pc durchgereicht, sobald der Anbieter sie liefert. */
function dp_name(array $s, string $id): string {
    $nm = $s['name'] ?? null;
    if (is_string($nm)) { $t = trim($nm); if ($t !== '') return $t; }
    if (is_array($nm)) {
        foreach (['de', 'en'] as $lg)
            foreach ($nm as $e)
                if (is_array($e) && (string) ($e['language'] ?? '') === $lg) {
                    $t = trim((string) ($e['text'] ?? ''));
                    if ($t !== '') return $t;
                }
        foreach ($nm as $e)
            if (is_array($e)) {
                $t = trim((string) ($e['text'] ?? ''));
                if ($t !== '') return $t;
            }
    }
    return 'Station ' . $id;
}

/* v1.4.19: Einheitliches Datenverzeichnis donkeydata/ (wie gtfsdata/ beim
 * gtfsproxy und delfidata/ beim delfiproxy). Der Proxy legt es selbst an;
 * alte Cache-Dateien von direkt neben dem Skript wandern beim ersten Aufruf
 * automatisch hinein. Bei unbeschreibbarem Verzeichnis greifen wie bisher die
 * stillen @-Fehlschläge: ohne Cache, direkte Upstream-Abfragen, Feed trotzdem
 * gültig. Rückgabe: true, wenn donkeydata/ nutzbar ist. */
define('DP_DIR',              __DIR__ . '/donkeydata');
define('DP_NAMES_FILE',       DP_DIR . '/donkeynames.json');
define('DP_CACHE_FILE',        DP_DIR . '/donkeycache.json');
define('DP_NAMES_FILE_LEGACY', __DIR__ . '/donkeynames.json');
define('DP_CACHE_FILE_LEGACY', __DIR__ . '/donkeycache.json');

function dp_dir_ready(): bool {
    if (!is_dir(DP_DIR)) @mkdir(DP_DIR, 0775, true);
    if (!(is_dir(DP_DIR) && is_writable(DP_DIR))) return false;
    /* Migration: einmalige Übernahme der Alt-Dateien neben dem Skript. */
    foreach ([['f' => DP_NAMES_FILE_LEGACY, 't' => DP_NAMES_FILE],
              ['f' => DP_CACHE_FILE_LEGACY,  't' => DP_CACHE_FILE]] as $m)
        if (is_file($m['f']) && !is_file($m['t'])) @rename($m['f'], $m['t']);
    return true;
}

/* v1.4.16: Vornamen der Räder aus der Donkey-Hub-API. Donkey nennt seine
 * Räder bei Namen ("Frieda", "E-bike Fischi", ...), der GBFS-Feed führt sie
 * nicht; die Hub-API tut es (Accept-Header zwingend, sonst 406). Rückgabe:
 * Map "lat5,lon5" -> Name (Position gerundet auf 5 Dezimalstellen). */
define('DP_NAMES_TTL', 21600);   // Basis 6 h, gestreut bis 12 h

/* v1.4.18: Namens-Cache je Station (donkeynames.json, flock beim Schreiben).
 * Erfolgreich abgefragte Stationen landen mit gestreuter Ablaufzeit im Cache,
 * fehlgeschlagene bleiben ohne Eintrag und werden beim nächsten Aufruf erneut
 * probiert. Beim Schreiben werden nur Stationen mit Rädern behalten. */
function dp_names_read(): array {
    if (!is_file(DP_NAMES_FILE)) return [];
    $raw = @file_get_contents(DP_NAMES_FILE);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}
function dp_names_write(array $cache, array $keepIds): void {
    $keep = [];
    foreach ($keepIds as $id)
        if (isset($cache[$id]['n']) && is_array($cache[$id]['n']))
            $keep[$id] = ['e' => $cache[$id]['e'], 'n' => $cache[$id]['n']];
    @file_put_contents(DP_NAMES_FILE, json_encode($keep), LOCK_EX);
}
function dp_hub_names(array $stIds): array {
    $out = [];
    $stIds = array_values(array_unique(array_filter(array_map('strval', $stIds), 'strlen')));
    if (!$stIds) return $out;
    $cache = dp_names_read();
    $now = time();
    $todo = [];                       // Stationen ohne frischen Cache-Eintrag
    foreach ($stIds as $id) {
        $e = $cache[$id]['e'] ?? 0;
        if (!is_numeric($e) || $now > (int)$e) $todo[] = $id;
    }
    if ($todo) {
        $fresh = [];                  // stId => Map "lat5,lon5" -> Name
        if (function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $hs = []; $hIds = [];
            foreach ($todo as $id) {
                $ch = curl_init('https://stables.donkey.bike/api/public/hubs/hub_' . rawurlencode($id) . '/vehicles');
                if (!$ch) continue;
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER   => true,
                    CURLOPT_FOLLOWLOCATION   => true,
                    CURLOPT_CONNECTTIMEOUT   => 5,
                    CURLOPT_TIMEOUT          => 12,
                    CURLOPT_HTTPHEADER       => ['Accept: application/com.donkeyrepublic.v7'],
                    CURLOPT_USERAGENT        => DP_UA,
                ]);
                curl_multi_add_handle($mh, $ch);
                $hs[] = $ch; $hIds[] = $id;
            }
            if ($hs) {
                $run = 0;
                do {                   // bis alle Handles fertig sind
                    curl_multi_exec($mh, $run);
                    if ($run > 0) curl_multi_select($mh, 0.2);
                } while ($run > 0);
                foreach ($hs as $i => $ch) {
                    $one = [];
                    dp_hub_parse((string) curl_multi_getcontent($ch), $one);
                    if ($one) $fresh[$hIds[$i]] = $one;   // nur Treffer cachen, Fehler erneut probieren
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                }
            }
            curl_multi_close($mh);
        } else {
            // Fallback ohne curl: sequenziell, begrenzt, kurzer Timeout
            $ctx = stream_context_create(['http' => [
                'timeout'    => 4,
                'user_agent' => DP_UA,
                'header'     => "Accept: application/com.donkeyrepublic.v7\r\n",
            ]]);
            foreach (array_slice($todo, 0, 60) as $id) {
                $raw = @file_get_contents('https://stables.donkey.bike/api/public/hubs/hub_'
                    . rawurlencode($id) . '/vehicles', false, $ctx);
                $one = [];
                if (is_string($raw)) dp_hub_parse($raw, $one);
                if ($one) $fresh[$id] = $one;
            }
        }
        foreach ($fresh as $id => $one)
            $cache[$id] = ['e' => $now + DP_NAMES_TTL + mt_rand(0, DP_NAMES_TTL), 'n' => $one];
        if ($fresh) dp_names_write($cache, $stIds);
    }
    foreach ($stIds as $id) {         // gemergtes Ergebnis der gewünschten Stationen
        $ent = $cache[$id]['n'] ?? null;
        if (!is_array($ent)) continue;
        foreach ($ent as $k => $nm)
            if (!isset($out[$k])) $out[$k] = $nm;   // erste Position gewinnt
    }
    return $out;
}

/* Einen Hub-API-Ausdruck in die Namens-Map einarbeiten (Best Effort). */
function dp_hub_parse(string $raw, array &$out): void {
    if ($raw === '') return;
    $j = json_decode($raw, true);
    if (!is_array($j)) return;
    foreach ($j as $v) {
        if (!is_array($v)) continue;
        $nm = trim((string) ($v['name'] ?? ''));
        if ($nm === '') continue;
        $lat = $v['latitude'] ?? null; $lon = $v['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) continue;
        $k = round((float) $lat, 5) . ',' . round((float) $lon, 5);
        if (!isset($out[$k])) $out[$k] = $nm;   // erste Position gewinnt
    }
}

/* Alle drei Systeme zusammensetzen, deduplizieren (vehicle_id / station_id). */
function dp_collect(): ?array {
    $veh = []; $sta = []; $seenV = []; $seenS = []; $idx5 = [];
    $any = false;
    foreach (DP_SYSTEMS as $sys) {
        $vs = dp_feed($sys, 'vehicle_status.json');
        if (is_array($vs)) {
            $any = true;
            foreach ($vs as $v) {
                if (!is_array($v)) continue;
                $id = $v['vehicle_id'] ?? null;
                $lat = $v['lat'] ?? null; $lon = $v['lon'] ?? null;
                if (!is_string($id) && !is_numeric($id)) continue;
                $id = (string) $id;
                if (isset($seenV[$id])) continue;
                if (!is_numeric($lat) || !is_numeric($lon)) continue;   // nur verortbare Räder
                $seenV[$id] = true;
                $fu = $v['current_fuel_percent'] ?? null;
                // v1.4.6: Donkey liefert current_fuel_percent als Bruch 0..1
                // (GBFS schreibt 0..100 vor, der Feed haelt sich nicht daran);
                // vorher rundete der Proxy den Bruch auf 0/1 Prozent. Werte <= 1
                // werden vor dem Runden auf Prozent gehoben (0 bleibt 0, 1 wird 100).
                if (is_numeric($fu) && (float) $fu >= 0 && (float) $fu <= 1)
                    $fu = (float) $fu * 100;
                $rm = $v['current_range_meters'] ?? null;
                $dl = null;
                if (isset($v['rental_uris']) && is_array($v['rental_uris'])) {
                    $ru = $v['rental_uris'];
                    $dl = $ru['android'] ?? $ru['ios'] ?? $ru['web'] ?? null;
                }
                $idx5[] = round((float) $lat, 5) . ',' . round((float) $lon, 5);
                $veh[] = [
                    'id'  => $id,
                    't'   => is_string($v['vehicle_type_id'] ?? null) ? $v['vehicle_type_id'] : 'bike',
                    'lat' => round((float) $lat, 6),
                    'lon' => round((float) $lon, 6),
                    'fu'  => is_numeric($fu) ? (int) round((float) $fu) : null,
                    'rm'  => is_numeric($rm) ? (int) round((float) $rm) : null,
                    'rs'  => !empty($v['is_reserved']),
                    'dis' => !empty($v['is_disabled']),
                    'st'  => isset($v['station_id']) && is_string($v['station_id']) ? $v['station_id'] : null,
                    'dl'  => is_string($dl) ? $dl : null,
                ];
            }
        }
        $ss = dp_feed($sys, 'station_information.json');
        if (is_array($ss)) {
            $any = true;
            foreach ($ss as $s) {
                if (!is_array($s)) continue;
                $id = $s['station_id'] ?? null;
                $lat = $s['lat'] ?? null; $lon = $s['lon'] ?? null;
                if (!is_string($id) && !is_numeric($id)) continue;
                $id = (string) $id;
                if (isset($seenS[$id])) continue;
                if (!is_numeric($lat) || !is_numeric($lon)) continue;
                $seenS[$id] = true;
                    $cap = $s['capacity'] ?? null;
                $ad = trim((string) ($s['address'] ?? ''));
                $pc = trim((string) ($s['post_code'] ?? ''));
                $sta[] = [
                    'id'   => $id,
                    'name' => dp_name($s, $id),
                    'ad'   => $ad !== '' ? $ad : null,
                    'pc'   => $pc !== '' ? $pc : null,
                    'lat'  => round((float) $lat, 6),
                    'lon'  => round((float) $lon, 6),
                    'cap'  => is_numeric($cap) ? (int) $cap : null,
                ];
            }
        }
    }
    if (!$any) return null;          // alle Feeds tot → Cache-grace statt leere Welt

    /* v1.4.16: Rad-Namen aus der Hub-API nachladen, nur für Stationen mit
     * mindestens einem Rad. Scheitert die komplette Abfrage, bleiben alle
     * n einfach null und der Feed ist trotzdem gültig. */
    $stWith = [];
    foreach ($veh as $v)
        if (is_string($v['st'])) $stWith[$v['st']] = true;
    $names = dp_hub_names(array_keys($stWith));
    foreach ($idx5 as $i => $k5)
        $veh[$i]['n'] = $names[$k5] ?? null;

    return ['v' => $veh, 's' => $sta];
}

/* Cache lesen/schreiben (flock, 60 s); bei Upstream-Ausfall letzten Stand liefern. */
function dp_cached(): ?array {
    $cacheFile = DP_CACHE_FILE;
    // 1) frischen Cache sofort zurückgeben
    if (is_file($cacheFile)) {
        $fp = @fopen($cacheFile, 'r');
        if ($fp) {
            flock($fp, LOCK_SH);
            $raw = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j) && isset($j['ts']) && is_array($j['d'])
                && (time() - (int) $j['ts']) < DP_TTL_S) {
                return $j['d'];
            }
        }
    }
    // 2) Upstream holen; eine Simple-Sperr-Datei verhindert, dass parallele
    //    Requests gleichzeitig die Upstream-Feeds hämmern (Stampeding).
    $lockFp = @fopen($cacheFile . '.lock', 'c');
    if ($lockFp) flock($lockFp, LOCK_EX);        // wartet bis der erste Läufer fertig ist
    // nach dem Warten erneut den frischen Cache pruefen (jemand anders war schneller)
    if (is_file($cacheFile)) {
        $j = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($j) && isset($j['ts']) && is_array($j['d'])
            && (time() - (int) $j['ts']) < DP_TTL_S) {
            if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
            return $j['d'];
        }
    }
    $d = dp_collect();
    if ($d === null) {
        // Grace: veralteten Cache notfalls ausliefern statt gänzlich zu scheitern
        $j = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;
        if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
        if (is_array($j) && isset($j['d']) && is_array($j['d'])) {
            return $j['d'];
        }
        return null;
    }
    @file_put_contents($cacheFile, json_encode(['ts' => time(), 'd' => $d]), LOCK_EX);
    if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
    return $d;
}

/* GeoJSON FeatureCollection (Fahrzeuge + Stationen) für uMap (dataUrl). */
function dp_geojson(array $d): array {
    $feat = [];
    $stName = [];
    foreach ($d['s'] as $s) $stName[$s['id']] = $s;   // Schnellzugriff Stationsdaten
    foreach ($d['v'] as $v) {
        $ty = $v['t'] === 'ebike' ? '⚡ E-Bike' : (($v['t'] === 'cargo' || $v['t'] === 'ecargo') ? '🚲 Lastenrad' : '🚲 Standardrad');
        $desc = 'Donkey Republic / SprottenFlotte';
        if (is_string($v['n'] ?? null) && $v['n'] !== '') $desc = '"' . $v['n'] . '" · ' . $desc;
        if ($v['fu'] !== null) $desc .= ' · Akku ' . $v['fu'] . ' %';
        if ($v['rm'] !== null) $desc .= ' · ca. ' . max(1, (int) round($v['rm'] / 1000)) . ' km Reichweite';
        if (!empty($v['rs'])) $desc .= ' · gerade reserviert';
        if (!empty($v['dis'])) $desc .= ' · außer Betrieb';
        if ($v['st'] !== null && isset($stName[$v['st']]))
            $desc .= ' · bei Station ' . $stName[$v['st']]['name'];
        if (is_string($v['dl'])) $desc .= ' · ' . $v['dl'];
        $feat[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$v['lon'], $v['lat']]],
            'properties' => ['name' => $ty, 'description' => $desc],
        ];
    }
    foreach ($d['s'] as $s) {
        $desc = 'Rückgabe-Station (Donkey Republic / SprottenFlotte)';
        if ($s['cap'] !== null) $desc .= ' · Kapazität ' . $s['cap'];
        if (!empty($s['ad'])) $desc .= ' · ' . $s['ad'] . (!empty($s['pc']) ? ', ' . $s['pc'] : '');
        $feat[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$s['lon'], $s['lat']]],
            'properties' => ['name' => 'P ' . $s['name'], 'description' => $desc],
        ];
    }
    return ['type' => 'FeatureCollection', 'features' => $feat];
}

/* ---- Hauptprogramm -------------------------------------------------------- */
dp_dir_ready();   // v1.4.19: donkeydata/ selbst anlegen + Alt-Caches übernehmen
$d = dp_cached();
if ($d === null) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    echo '{"error":"upstream_unreachable"}';
    exit;
}
header('Access-Control-Allow-Origin: *');
if (isset($_GET['geojson'])) {
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    echo json_encode(dp_geojson($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    echo json_encode(['ts' => time(), 'ttl' => DP_TTL_S, 'v' => $d['v'], 's' => $d['s']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
