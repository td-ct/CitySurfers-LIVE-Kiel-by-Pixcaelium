<?php
/* donkeyproxy.php – Mini-Proxy für CitySurfers LIVE @ Kiel (v1.3.17+)
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
 *   fu (current_fuel_percent, 0-100, kann null sein), rm (current_range_meters),
 *   rs (is_reserved), dis (is_disabled), st (station_id, wenn bekannt),
 *   dl (miet-deeplink aus rental_uris.android/ios/web, wenn vorhanden).
 * Stations-Objekt (s): id, name, lat, lon, cap (capacity, kann null sein).
 *
 * Cache: Datei donkeycache.json neben diesem Skript, 60 Sekunden, mit flock
 * gesichert; bei Upstream-Fehlern wird ein alter Cache-Stand noch gnädig
 * ausgeliefert (grace), damit die App kurzzeitige Feed-Störungen nicht merkt.
 *
 * Empfehlung Deployment: https://pixcaelium.timo-damm.de/apps/citysurfers/
 * (ins selbe Verzeichnis wie die index.html); das Verzeichnis muss für PHP
 * schreibbar sein (Cache-Datei), sonst entfällt nur der Cache-Mechanismus und
 * jede Anfrage fragt die Upstream-Feeds direkt ab.
 */

declare(strict_types=1);

const DP_SYSTEMS   = ['donkey_kiel', 'donkey_kielsmile', 'donkey_schleswig'];
const DP_FEEDBASE  = 'https://stables.donkey.bike/api/public/gbfs/3.0/';
const DP_TTL_S     = 60;          // Sekunden Cache-Haltbarkeit (Feeds haben TTL 60 s)
const DP_TIMEOUT_S = 7;           // Socket-Timeout je Upstream-Abfrage
const DP_UA        = 'CitySurfersLIVE-Kiel/1.3 (+https://pixcaelium.timo-damm.de/apps/citysurfers/)';

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

/* Alle drei Systeme zusammensetzen, deduplizieren (vehicle_id / station_id). */
function dp_collect(): ?array {
    $veh = []; $sta = []; $seenV = []; $seenS = [];
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
                $rm = $v['current_range_meters'] ?? null;
                $dl = null;
                if (isset($v['rental_uris']) && is_array($v['rental_uris'])) {
                    $ru = $v['rental_uris'];
                    $dl = $ru['android'] ?? $ru['ios'] ?? $ru['web'] ?? null;
                }
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
                $nm = $s['name'] ?? ('Station ' . $id);
                $sta[] = [
                    'id'   => $id,
                    'name' => is_string($nm) ? $nm : ('Station ' . $id),
                    'lat'  => round((float) $lat, 6),
                    'lon'  => round((float) $lon, 6),
                    'cap'  => is_numeric($cap) ? (int) $cap : null,
                ];
            }
        }
    }
    if (!$any) return null;          // alle Feeds tot → Cache-grace statt leere Welt
    return ['v' => $veh, 's' => $sta];
}

/* Cache lesen/schreiben (flock, 60 s); bei Upstream-Ausfall letzten Stand liefern. */
function dp_cached(): ?array {
    $cacheFile = __DIR__ . '/donkeycache.json';
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
        $feat[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$s['lon'], $s['lat']]],
            'properties' => ['name' => 'P ' . $s['name'], 'description' => $desc],
        ];
    }
    return ['type' => 'FeatureCollection', 'features' => $feat];
}

/* ---- Hauptprogramm -------------------------------------------------------- */
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
