<?php
/* gtfsproxy.php - DELFI-Verspaetungs-Overlay (gtfs.de free realtime) fuer
 * CitySurfers LIVE @ Kiel (v1.5.2, App-Aufruf mit ?lat=..&lon=..)
 * ---------------------------------------------------------------------------
 * Zweck: Der kostenlose Sammelstream von gtfs.de (realtime.gtfs.de, Trip
 * Updates des Nah-, Regional- und Fernverkehrs, Datenbasis DELFI, gtfs.de
 * free, Lizenz CC BY 4.0, Namensnennung gtfs.de) liefert bundesweit aktuelle
 * Verspaetungen, ist aber ein ca. 10 MB grosser Protokollpuffer, den keine
 * reine Browser-App sinnvoll laden kann. Dieser Proxy holt den Strom
 * serverseitig, haelt ihn kurz im Cache und beantwortet JE Station mit
 * einer kleinen JSON-Liste der dort aktiven Fahrten samt Linie und
 * Verspaetung in Sekunden. Die App legt diese Werte als Overlay ueber die
 * geplanten Zeilen ihrer Fern-Stationen ohne eigenen regionalen Live-Dienst
 * (Frankfurt, Koeln, Leipzig, Hamburg soweit im Strom geliefert, ...).
 *
 * Aufrufe:
 *   gtfsproxy.php?lat=50.1069&lon=8.6637 -> { "ok": true, "ts": .., "age": ..,
 *     "st": "ready", "key": "..", "n": 12, "at": [ { "l": "RB10", "d": 120,
 *     "p": "14:35", "s": 3, "t": "12jkm" }, ... ] }
 *     l = Linie (route_short_name aus der routes-Tabelle des Tagesfeeds),
 *     d = Verspaetung in Sekunden (negativ = frueher), p = planmaessige
 *     Abfahrt HH:MM am Halt (nur Schiene aus stop_times rv/fv, sonst null),
 *     s = stop_sequence, t = Trip-ID (gtfs.de-Kurzcode).
 *   gtfsproxy.php?probe=1 -> Status aller Caches (Wartung, Tests).
 *   gtfsproxy.php?warm=1 -> wie probe, baut die Tagesdaten vorab auf
 *     (optionaler Tages-Cron, siehe Deployment unten).
 *
 * Architektur (alles in ./gtfsdata/ neben diesem Skript):
 *   rt.pb - der Realtime-Strom, 60 s Cache, flock, Grace bei Upstream-Fehler.
 *   ex.json - Extrakt des aktuellen Stroms FUR DIE BEKANNTEN Stationen:
 *     unter sd je Stop-ID die aktiven Fahrten [Linie, Delay, Folge, Trip],
 *     unter sq je Station die plangebundenen Schienen-Fahrten [Linie,
 *     Plan-HH:MM, Delay, Folge, Trip]. Bei jeder neuen rt.pb-Generation baut
 *     der erste Anfrager den Extrakt einmal neu (flock), alle anderen lesen.
 *   wanted.json - je Station (Koordinate auf 4 Nachkommastellen als
 *     Schluessel) die Stop-IDs im 600-m-Umkreis, LRU, persistent.
 *   sched/<key>.gtf - je Station die Schienen-Abfahrten aus rv_free und
 *     fv_free stop_times (Trip, HH:MM, Folge), Grundlage des exakten
 *     Zeilen-Matchings; ein neuer Stations-Schluessel oder der Tageswechsel
 *     loesen einen begrenzten Rebuild (nur die zwei Schienenfeeds) aus,
 *     typisch 1 bis 3 Anfragen.
 *   resolved.json - Trip-ID -> Linie NUR fuer aktive Trips, je Tagesstand;
 *     fehlende IDs werden hoechstens alle 5 Minuten per Einmal-Scan ueber
 *     trips.gtf (rv + fv + nv) nachgetragen.
 *   routes.gtf / trips.gtf / stopb_<NN>.gtf - die kleinen Tabellen der
 *     gtfs.de-Tagesfeeds; Stops in Halbgrad-Latituden-Bucket-Dateien.
 *   st_ok.txt / st_state.json - Tagesdaten stehen / im Bau (Phasenmaschine,
 *     je Anfrage bis zu 20 Sekunden Bauarbeit, wenn noetig).
 *
 * Statische Tagesdaten: gtfs.de generiert die Feeds taeglich (Fahrplan 30
 * Tage). Der Proxy laedt pro Tag nur die benoetigten Zip-MEMBER per
 * HTTP-Range-Anfrage (routes + trips + stops von nv, rv und fv, dort
 * zusaetzlich stop_times; zusammen ca. 34 MB statt 282 MB nv-Gesamtzip)
 * und baut daraus sein Format; bis dahin antwortet der Proxy ehrlich mit
 * st = "warm" und einer leeren Fahrtenliste.
 *
 * Fehler-Philosophie wie donkeyproxy.php: An diesem Proxy darf niemand
 * scheitern. Antwortet etwas nicht, bleibt die Antwort { ok: false, ... }
 * bei HTTP 200, und die App zeigt einfach kein Overlay, ohne Crash.
 *
 * Lizenz / Namensnennung: Daten gtfs.de (gtfs.de/de/realtime/ und
 * gtfs.de/de/feeds/, Creative Commons BY 4.0, Datenbasis DELFI). Die App
 * nennt gtfs.de im Ueber-Reiter und in der LED-Legende (LED gtfs.de).
 *
 * Deployment: https://pixcaelium.timo-damm.de/apps/citysurfers/, selbes
 * Verzeichnis wie index.html und donkeyproxy.php (Timos Upload-Skript laedt
 * diese Datei mit hoch). Das Verzeichnis muss fuer PHP schreibbar sein,
 * damit gtfsdata/ entstehen kann, sonst meldet ?probe=1 readonly und das
 * Overlay bleibt stumm, ohne irgendetwas zu brechen. Optionaler Cron
 * (erlaubt, nicht noetig):
 *   15 5 * * * curl -s "https://pixcaelium.timo-damm.de/apps/citysurfers/gtfsproxy.php?warm=1" > /dev/null
 */

declare(strict_types=1);

const GP_V         = 'gtfsproxy 1.5.2';
const GP_RT_URL    = 'https://realtime.gtfs.de/realtime-free.pb';
const GP_RT_TTL    = 60;        // Sekunden Cache fuer den Realtime-Strom
const GP_RT_MIN_B  = 300000;    // Plausibilitaet: Strom groesser 300 kB ...
const GP_RT_MAX_B  = 64000000;  // ... und kleiner 64 MB, sonst kein Uebernehmen.
const GP_FEEDS     = ['nv' => 'https://download.gtfs.de/germany/nv_free/latest.zip',
                      'rv' => 'https://download.gtfs.de/germany/rv_free/latest.zip',
                      'fv' => 'https://download.gtfs.de/germany/fv_free/latest.zip'];
const GP_MEMBERS   = ['nv' => ['routes.txt', 'trips.txt', 'stops.txt'],
                      'rv' => ['routes.txt', 'trips.txt', 'stops.txt', 'stop_times.txt'],
                      'fv' => ['routes.txt', 'trips.txt', 'stops.txt', 'stop_times.txt']];
const GP_STAT_TTL  = 71400;    // ~20 h: taeglicher Neubau der statischen Daten
const GP_BUDGET_S  = 20.0;     // Bau-Arbeit je Anfrage (Sekunden)
const GP_RADIUS_M  = 600;      // Stop-Umkreis je Station
const GP_MAX_STOP  = 400;      // Obergrenze Stops im Umkreis je Station
const GP_WANTED_MAX = 40;      // Stationen im wanted-LRU
const GP_SCHED_KEEP = 24;      // Plan-Extrakte im LRU
const GP_RES_TTL   = 300;      // Linen-Scan seltener als alle 5 min
const GP_WIN_BACK  = 40;       // Plan-Fenster: 40 Minuten zurueck ...
const GP_WIN_FWD   = 300;      // ... bis 5 Stunden voraus
const GP_TIMEOUT_S = 25;       // Socket-Timeout Upstream
const GP_UA        = 'CitySurfersLIVE-Kiel/1.5.2 (+https://pixcaelium.timo-damm.de/apps/citysurfers/)';

define('GP_DIR',   __DIR__ . '/gtfsdata');
define('GP_TMP',   GP_DIR . '/tmp');
define('GP_RT',    GP_DIR . '/rt.pb');
define('GP_EX',    GP_DIR . '/ex.json');
define('GP_WANT',  GP_DIR . '/wanted.json');
define('GP_RES',   GP_DIR . '/resolved.json');
define('GP_OK',    GP_DIR . '/st_ok.txt');
define('GP_ST',    GP_DIR . '/st_state.json');
define('GP_SST',   GP_DIR . '/sched_state.json');
define('GP_SDONE', GP_DIR . '/sched_day');
define('GP_SCHED', GP_DIR . '/sched');

/* ---------- Kleinkram: Verzeichnis, HTTP, Cache-Dateien ------------------ */

function gp_dir_ready(): bool {
    if (!is_dir(GP_DIR)) @mkdir(GP_DIR, 0775, true);
    if (!is_dir(GP_TMP)) @mkdir(GP_TMP, 0775, true);
    if (!is_dir(GP_SCHED)) @mkdir(GP_SCHED, 0775, true);
    return is_dir(GP_DIR) && is_writable(GP_DIR);
}

/* HTTP-GET (optional mit Range) IN eine Datei streamen; false bei Fehler. */
function gp_http_to_file(string $url, string $dest, ?string $range = null, int $timeout = GP_TIMEOUT_S): bool {
    $hdrs = 'User-Agent: ' . GP_UA . "\r\nAccept: */*\r\nAccept-Encoding: identity\r\n";
    if ($range !== null) $hdrs .= 'Range: ' . $range . "\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
        'user_agent' => GP_UA, 'header' => $hdrs]]);
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) return false;
    $dst = @fopen($dest, 'wb');
    if (!$dst) { fclose($src); return false; }
    $ok = true;
    while (!feof($src)) {
        $chunk = fread($src, 1048576);
        if ($chunk === false || $chunk === '') continue;
        if (fwrite($dst, $chunk) === false) { $ok = false; break; }
    }
    $meta = stream_get_meta_data($src);
    fclose($src); fclose($dst);
    if ($range !== null) {
        // Range muss mit 206 beantwortet werden; sonst schickt der Server
        // die GESAMTE Datei (nv_free.zip: 282 MB) und das waere kein Fortschritt.
        $code = 0;
        foreach (($meta['wrapper_data'] ?? []) as $h)
            if (stripos((string)$h, 'HTTP/') === 0
                && preg_match('/\s(\d{3})\s/', (string)$h, $m)) { $code = (int)$m[1]; break; }
        if ($code !== 206 || !$ok) { @unlink($dest); return false; }
        return true;
    }
    return $ok && filesize($dest) > 0;
}

function gp_file_age(string $f): int {
    $m = @filemtime($f);
    return $m ? (time() - $m) : PHP_INT_MAX;
}

function gp_json_read(string $f): ?array {
    $raw = @file_get_contents($f);
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function gp_atomic_put(string $f, string $content): void {
    $tmp = $f . '.new';
    if (@file_put_contents($tmp, $content, LOCK_EX) !== false) @rename($tmp, $f);
}

/* ---------- GTFS-RT-Wireformat (Protokollpuffer ohne Extension) ---------- */

/* Varint, 64 Bit, mit Two's-Complement: Verspaetungen liegen als signed
 * int64 vor (z.B. -63 s als zehnbyteige Varint). Zehn Bytes heisst negativ,
 * dann ist $v der Absolutwert im Two's-Complement-Komplement-Format. */
function gp_varint(string $s, int &$p, int $n): int {
    $v = 0; $sh = 0; $cnt = 0;
    while ($p < $n) {
        $b = ord($s[$p]); $p++; $cnt++;
        if ($sh <= 56) $v |= ($b & 0x7f) << $sh;
        if (($b & 0x80) === 0) break;
        $sh += 7;
    }
    if ($cnt >= 10) return -(PHP_INT_MAX - $v + 1);
    return $v;
}

/* Felder einer Nachricht als Listen [feld, wire, a, b]:
 *   wire 0: a = Varint-Wert, b = 0
 *   wire 1: a = 8-Byte-String, b = 0    wire 5: a = 4-Byte-String, b = 0
 *   wire 2: a = Startbyte, b = Laenge (Daten via substr($s, $a, $b)) */
function gp_fields(string $s, int $p0, int $n, int $max): array {
    $out = [];
    $p = $p0;
    while ($p < $n && count($out) < $max) {
        $key = gp_varint($s, $p, $n);
        if ($key < 0) break;
        $f = $key >> 3; $w = $key & 7;
        if ($w === 0) { $v = gp_varint($s, $p, $n); $out[] = [$f, 0, $v, 0]; }
        elseif ($w === 1) { if ($p + 8 > $n) break; $out[] = [$f, 1, substr($s, $p, 8), 0]; $p += 8; }
        elseif ($w === 2) {
            $l = gp_varint($s, $p, $n);
            if ($l < 0 || $p + $l > $n) break;
            $out[] = [$f, 2, $p, $l]; $p += $l;
        }
        elseif ($w === 5) { if ($p + 4 > $n) break; $out[] = [$f, 5, substr($s, $p, 4), 0]; $p += 4; }
        else break;
    }
    return $out;
}

/* ---------- Realtime-Strom ------------------------------------------------ */

function gp_rt_refresh(): void {
    $lock = @fopen(GP_RT . '.lock', 'c');
    if (!$lock) return;
    flock($lock, LOCK_EX);
    if (gp_file_age(GP_RT) > GP_RT_TTL) {
        if (gp_http_to_file(GP_RT_URL, GP_RT . '.new')) {
            $sz = @filesize(GP_RT . '.new');
            if ($sz !== false && $sz >= GP_RT_MIN_B && $sz <= GP_RT_MAX_B) {
                @rename(GP_RT . '.new', GP_RT);
            } else { @unlink(GP_RT . '.new'); }
        }
        // Download fehlgeschlagen: alten Strom behalten (Grace, s.o.)
    }
    flock($lock, LOCK_UN); fclose($lock);
}

/* ---------- Statische Tagesdaten: Zip-Member per Range laden -------------- */

function gp_http_size(string $url): ?int {
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => GP_TIMEOUT_S,
        'ignore_errors' => true, 'user_agent' => GP_UA]]);
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) return null;
    $meta = stream_get_meta_data($src);
    fclose($src);
    if (empty($meta['wrapper_data']) || !is_array($meta['wrapper_data'])) return null;
    foreach ($meta['wrapper_data'] as $h)
        if (stripos((string)$h, 'Content-Length:') === 0) return (int) trim(substr((string)$h, 15));
    return null;
}

function gp_http_range(string $url, int $a, int $b): ?string {
    $f = GP_TMP . '/range.bin';
    if (!gp_http_to_file($url, $f, 'bytes=' . $a . '-' . $b)) return null;
    $d = @file_get_contents($f);
    @unlink($f);
    return is_string($d) ? $d : null;
}

/* Zentralverzeichnis eines Remote-Zips: Member => [Offset, gepackt, entpackt]. */
function gp_zip_central(string $url): ?array {
    $size = gp_http_size($url);
    if (!$size || $size < 200) return null;
    $tailStart = max(0, $size - 65536);
    $tail = gp_http_range($url, $tailStart, $size - 1);
    if (!is_string($tail)) return null;
    $e = strrpos($tail, "PK\x05\x06");
    if ($e === false) return null;
    $cdSize = unpack('V', substr($tail, $e + 12, 4))[1];
    $cdOff  = unpack('V', substr($tail, $e + 16, 4))[1];
    $cd = ($cdOff >= $tailStart)
        ? substr($tail, $cdOff - $tailStart, $cdSize)
        : gp_http_range($url, $cdOff, $cdOff + $cdSize - 1);
    if (!is_string($cd) || substr($cd, 0, 4) !== "PK\x01\x02") return null;
    $out = []; $j = 0;
    while ($j + 46 <= strlen($cd) && substr($cd, $j, 4) === "PK\x01\x02") {
        $csize = unpack('V', substr($cd, $j + 20, 4))[1];
        $nl    = unpack('v', substr($cd, $j + 28, 2))[1];
        $el    = unpack('v', substr($cd, $j + 30, 2))[1];
        $cl    = unpack('v', substr($cd, $j + 32, 2))[1];
        $off   = unpack('V', substr($cd, $j + 42, 4))[1];
        $out[substr($cd, $j + 46, $nl)] = [$off, $csize];
        $j += 46 + $nl + $el + $cl;
    }
    return $out ?: null;
}

/* EINEN Zip-Member (roh, deflate) runterladen und cachen; eine Datei, die
 * juenger als GP_STAT_TTL ist, wird wiederverwendet (Tagesstand). */
function gp_member_file(string $feed, string $member, array $cd): ?string {
    if (!isset($cd[$member])) return null;
    $dest = GP_TMP . '/' . $feed . '_' . str_replace('.txt', '', $member) . '.defl';
    if (is_file($dest) && gp_file_age($dest) < GP_STAT_TTL) return $dest;
    [$off, $csize] = $cd[$member];
    $lh = gp_http_range(GP_FEEDS[$feed], $off, $off + 29);
    if (!is_string($lh) || substr($lh, 0, 4) !== "PK\x03\x04") return null;
    $nlen = unpack('v', substr($lh, 26, 2))[1];
    $elen = unpack('v', substr($lh, 28, 2))[1];
    $start = $off + 30 + $nlen + $elen;
    if (!gp_http_to_file(GP_FEEDS[$feed], $dest, 'bytes=' . $start . '-' . ($start + $csize - 1))) return null;
    if (abs((int)filesize($dest) - $csize) > 16) { @unlink($dest); return null; }
    return $dest;
}

/* Eine Deflate-Datei zeilenweise abarbeiten; $cb($line) je Zeile. */
function gp_deflate_lines(string $file, callable $cb): bool {
    $fh = @fopen($file, 'rb');
    if (!$fh) return false;
    $inf = @inflate_init(ZLIB_ENCODING_RAW);
    if ($inf === false) { fclose($fh); return false; }
    $buf = '';
    while (!feof($fh)) {
        $comp = fread($fh, 1048576);
        if ($comp === false || $comp === '') continue;
        $out = @inflate_add($inf, $comp);
        if ($out === false) { fclose($fh); return false; }
        if ($out === '') continue;
        $buf .= $out;
        $nl = strrpos($buf, "\n");
        if ($nl !== false) {
            foreach (explode("\n", substr($buf, 0, $nl)) as $ln) $cb($ln);
            $buf = substr($buf, $nl + 1);
        }
    }
    $flush = @inflate_add($inf, '', ZLIB_FINISH);
    if (is_string($flush) && $flush !== '') $buf .= $flush;
    if ($buf !== '') $cb($buf);
    fclose($fh);
    return true;
}

function gp_csv(string $ln): array {
    if (strpos($ln, '"') === false) return explode(',', $ln);
    $fld = []; $cur = ''; $q = false;
    $n = strlen($ln);
    for ($i = 0; $i < $n; $i++) {
        $c = $ln[$i];
        if ($q) {
            if ($c === '"') { if ($i + 1 < $n && $ln[$i+1] === '"') { $cur .= '"'; $i++; } else $q = false; }
            else $cur .= $c;
        } elseif ($c === '"') $q = true;
        elseif ($c === ',') { $fld[] = $cur; $cur = ''; }
        else $cur .= $c;
    }
    $fld[] = $cur;
    return $fld;
}

/* ---------- Statischer Bau: Phasen, budgetiert je Anfrage ----------------- */

/* TRUE, wenn noch ein Member dieses Feeds geladen werden musste. */
function gp_dl_one(string $feed, array &$state): bool {
    foreach (GP_MEMBERS[$feed] as $m) {
        $tag = $feed . ':' . $m;
        if (in_array($tag, $state['done2'], true)) continue;
        $file = gp_member_file($feed, $m, $state['cds'][$feed]);
        if ($file === null) return false;   // Downloadfehler: naechste Anfrage probiert erneut
        $state['done2'][] = $tag;
        return true;
    }
    return false;
}

/* Je Feed eine eigene Part-Datei: routes.<feed>.part, trips.<feed>.part,
 * stopb_<NN>.<feed>.part, sched/<key>.<feed>.part. Jede Phasen-Ausfuehrung
 * startet mit einem Reset der EIGENEN Part-Datei (Unterbrechungen duerfen
 * nie Doppel-Zeilen erzeugen), fin verschmilzt die Feed-Teile. */

function gp_phase_routes(string $feed, array &$state): bool {
    $file = gp_member_file($feed, 'routes.txt', $state['cds'][$feed]);
    if ($file === null) return false;
    @unlink(GP_TMP . '/routes.' . $feed . '.part');
    $fh = @fopen(GP_TMP . '/routes.' . $feed . '.part', 'a');
    if (!$fh) return false;
    gp_deflate_lines($file, function (string $ln) use ($fh, $feed) {
        static $first = true;
        if ($first) { $first = false; return; }
        $c = gp_csv($ln);
        if (count($c) < 5) return;
        $rid = trim((string)$c[4]); $short = trim((string)$c[1]); $long = trim((string)$c[0]);
        if ($rid === '') return;
        $name = $short !== '' ? $short : $long;
        fwrite($fh, $rid . "\t" . $name . "\t" . trim((string)$c[3]) . "\t" . $feed . "\n");
    });
    fclose($fh);
    $state['done2'][] = $feed . ':routes';
    return true;
}

function gp_phase_stops(string $feed, array &$state): bool {
    $file = gp_member_file($feed, 'stops.txt', $state['cds'][$feed]);
    if ($file === null) return false;
    foreach (glob(GP_TMP . '/stopb_*.' . $feed . '.part') ?: [] as $old) @unlink($old);
    $handles = [];
    $r = gp_deflate_lines($file, function (string $ln) use (&$handles) {
        static $first = true;
        if ($first) { $first = false; return; }
        $c = gp_csv($ln);
        if (count($c) < 5) return;
        $sid = trim((string)$c[2]); $par = trim((string)$c[1]);
        $la = trim((string)$c[3]); $lo = trim((string)$c[4]);
        if ($sid === '' || !is_numeric($la) || !is_numeric($lo)) return;
        $b = (string)(int)floor(((float)$la) * 2);
        if (!isset($handles[$b])) $handles[$b] = @fopen(GP_TMP . '/stopb_' . $b . '.' . $feed . '.part', 'a');
        if ($handles[$b]) fwrite($handles[$b], $sid . "\t" . $par . "\t" . $la . "\t" . $lo . "\n");
    });
    foreach ($handles as $h) fclose($h);
    if (!$r) return false;
    $state['done2'][] = $feed . ':stops';
    return true;
}

function gp_phase_trips(string $feed, array &$state): bool {
    $file = gp_member_file($feed, 'trips.txt', $state['cds'][$feed]);
    if ($file === null) return false;
    @unlink(GP_TMP . '/trips.' . $feed . '.part');
    $fh = @fopen(GP_TMP . '/trips.' . $feed . '.part', 'a');
    if (!$fh) return false;
    gp_deflate_lines($file, function (string $ln) use ($fh, $feed) {
        static $first = true;
        if ($first) { $first = false; return; }
        $c = gp_csv($ln);
        if (count($c) < 3) return;
        $tid = trim((string)$c[2]);
        if ($tid === '') return;
        fwrite($fh, $tid . "\t" . trim((string)$c[0]) . "\t" . $feed . "\n");
    });
    fclose($fh);
    $state['done2'][] = $feed . ':trips';
    return true;
}

/* stop_times von rv bzw. fv: Zeilen der WANTED Stops in Stations-Extrakte.
 * $map: stop_id -> [stationsKey, ...]. */
function gp_phase_sched(string $feed, array &$state, array $map): bool {
    if (!$map) { $state['done2'][] = $feed . ':sched'; return true; }
    $file = gp_member_file($feed, 'stop_times.txt', $state['cds'][$feed]);
    if ($file === null) return false;
    foreach (glob(GP_SCHED . '/*.' . $feed . '.part') ?: [] as $old) @unlink($old);
    $handles = [];
    $r = gp_deflate_lines($file, function (string $ln) use (&$handles, $map) {
        static $first = true;
        if ($first) { $first = false; return; }
        $c = gp_csv($ln);
        if (count($c) < 5) return;
        $stops = $map[trim((string)$c[3])] ?? null;
        if ($stops === null) return;
        $dep = trim((string)$c[2]);
        if ($dep === '' || strlen($dep) < 4) $dep = trim((string)$c[1]);
        if ($dep === '' || strlen($dep) < 4) return;
        foreach ($stops as $skey) {
            if (!isset($handles[$skey])) $handles[$skey] = @fopen(GP_SCHED . '/' . $skey . '.' . $feed . '.part', 'a');
            if ($handles[$skey]) fwrite($handles[$skey], trim((string)$c[0]) . "\t" . $dep . "\t" . trim((string)$c[4]) . "\n");
        }
    });
    foreach ($handles as $h) fclose($h);
    if (!$r) return false;
    $state['done2'][] = $feed . ':sched';
    return true;
}

/* Part-Dateien der drei Feeds zusammenfuehren und an Ort und Stelle bringen. */
function gp_fin_static(): void {
    foreach (['routes', 'trips'] as $tab) {
        $out = @fopen(GP_DIR . '/' . $tab . '.gtf', 'w');
        if (!$out) continue;
        foreach (['nv', 'rv', 'fv'] as $fd) {
            $src = GP_TMP . '/' . $tab . '.' . $fd . '.part';
            if (!is_file($src)) continue;
            $in = @fopen($src, 'rb');
            if (!$in) continue;
            while (!feof($in)) { $d = fread($in, 1048576); if ($d !== false && $d !== '') fwrite($out, $d); }
            fclose($in);
        }
        fclose($out);
    }
    // Stop-Buckets je Nummer aus allen Feeds zusammensetzen
    $buckets = [];
    foreach (glob(GP_TMP . '/stopb_*.part') ?: [] as $pf) {
        $bn = basename($pf);            // stopb_<NN>.<feed>.part
        $nn = (int)substr($bn, 6, strrpos($bn, '.') - 6);
        $buckets[$nn][] = $pf;
    }
    foreach ($buckets as $nn => $srcs) {
        $out = @fopen(GP_DIR . '/stopb_' . $nn . '.gtf', 'w');
        if (!$out) continue;
        foreach (['nv', 'rv', 'fv'] as $fd)
            foreach ($srcs as $src)
                if (strpos(basename($src), '.' . $fd . '.part') !== false) {
                    $in = @fopen($src, 'rb');
                    if (!$in) continue;
                    while (!feof($in)) { $d = fread($in, 1048576); if ($d !== false && $d !== '') fwrite($out, $d); }
                    fclose($in);
                }
        fclose($out);
    }
    // Sched-Part-Dateien (Schienenplan je Station) verschmelzen
    $byKey = [];
    foreach (glob(GP_SCHED . '/*.part') ?: [] as $pf) {
        $bn = basename($pf);                     // <key>.<feed>.part
        $noExt = substr($bn, 0, -5);             // <key>.<feed>
        $fd = substr($noExt, strrpos($noExt, '.') + 1);
        $skey = substr($noExt, 0, -1 - strlen($fd));
        $byKey[$skey][$fd][] = $pf;
    }
    foreach ($byKey as $skey => $byFeed) {
        $out = @fopen(GP_SCHED . '/' . $skey . '.gtf', 'w');
        if (!$out) continue;
        foreach (['rv', 'fv'] as $fd)
            foreach ($byFeed[$fd] ?? [] as $src) {
                $in = @fopen($src, 'rb');
                if (!$in) continue;
                while (!feof($in)) { $d = fread($in, 1048576); if ($d !== false && $d !== '') fwrite($out, $d); }
                fclose($in);
            }
        fclose($out);
    }
    foreach (glob(GP_SCHED . '/*.part') ?: [] as $pf) @unlink($pf);
    @touch(GP_OK);
    gp_atomic_put(GP_RES, json_encode(['gen' => filemtime(GP_OK), 'ts' => 0, 'm' => []]));
    @unlink(GP_EX);
    foreach (glob(GP_TMP . '/routes.*.part') ?: [] as $p) @unlink($p);
    foreach (glob(GP_TMP . '/trips.*.part') ?: [] as $p) @unlink($p);
    foreach (glob(GP_TMP . '/stopb_*.part') ?: [] as $p) @unlink($p);
    // GP_SDONE bewusst NICHT hier: der Tages-Marker zaehlt nur den fertig
    // gebundenen Schienenplan der WANTED-Stationen (siehe gp_phases).
}

/* Eine Runde am Bau: je Anfrage bis GP_BUDGET_S Sekunden. $schedOnly baut
 * NUR die Schienen-Plan-Extrakte (neue Station, Tageswechsel). */
function gp_phases(string $stateFile, bool $schedOnly, array $schedMap): array {
    $state = gp_json_read($stateFile);
    if (!is_array($state) || !is_string($state['phase'] ?? null)
        || ((time() - (int)($state['started'] ?? 0)) > 14400)) {
        $state = ['phase' => 'head', 'started' => time(), 'done2' => [], 'cds' => []];
    }
    $deadline = microtime(true) + GP_BUDGET_S;
    while (microtime(true) < $deadline) {
        $okstep = true;
        switch ($state['phase']) {
            case 'head':
                foreach (['nv', 'rv', 'fv'] as $fd) {
                    if (isset($state['cds'][$fd]) || ($schedOnly && $fd === 'nv')) continue;
                    $cd = gp_zip_central(GP_FEEDS[$fd]);
                    if (!is_array($cd)) { $okstep = false; break; }
                    $state['cds'][$fd] = $cd;
                }
                if ($okstep) $state['phase'] = 'dl';
                break;
            case 'dl':
                $did = false;
                foreach (['nv', 'rv', 'fv'] as $fd) {
                    if ($schedOnly && $fd === 'nv') continue;
                    if (gp_dl_one($fd, $state)) { $did = true; break; }
                }
                if (!$did) { $okstep = false; $state['phase'] = $schedOnly ? 'sched' : 'build'; $okstep = true; }
                break;
            case 'build':
                $did = false;
                foreach (['routes', 'stops', 'trips'] as $tab) {
                    foreach (['nv', 'rv', 'fv'] as $fd) {
                        if (in_array($fd . ':' . $tab, $state['done2'], true)) continue;
                        $fn = 'gp_phase_' . $tab;
                        if ($fn($fd, $state)) $did = true;
                        break 2;
                    }
                }
                if (!$did) $state['phase'] = 'sched';
                break;
            case 'sched':
                $did = false;
                foreach (['rv', 'fv'] as $fd) {
                    if (in_array($fd . ':sched', $state['done2'], true)) continue;
                    if (gp_phase_sched($fd, $state, $schedMap)) $did = true;
                    break;
                }
                if (!$did) {
                    gp_fin_static();
                    if ($schedOnly) @touch(GP_SDONE);   // Tages-Marker: Plan der wanted-Stationen steht
                    @unlink($stateFile);
                    return ['phase' => 'done'];
                }
                break;
            default:
                $okstep = false;
                break;
        }
        if (!$okstep) break;
    }
    if (is_array($state) && $state['phase'] !== 'done') gp_atomic_put($stateFile, json_encode($state));
    return $state;
}

/* ---------- Station: Stops im Umkreis, wanted.json ------------------------- */

function gp_hav(float $la1, float $lo1, float $la2, float $lo2): float {
    $R = 6371000.0;
    $p1 = deg2rad($la1); $p2 = deg2rad($la2);
    $dp = $p2 - $p1; $dl = deg2rad($lo2 - $lo1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

/* Stop-IDs im Umkreis GP_RADIUS_M um (lat, lon), via Halbgrad-Buckets. */
function gp_stops_near(float $lat, float $lon): array {
    $out = [];
    $b0 = (int)floor($lat * 2);
    foreach ([$b0 - 1, $b0, $b0 + 1] as $b) {
        $f = GP_DIR . '/stopb_' . $b . '.gtf';
        if (!is_file($f)) continue;
        $fh = @fopen($f, 'rb');
        if (!$fh) continue;
        while (!feof($fh)) {
            $ln = fgets($fh);
            if ($ln === false) break;
            $p = explode("\t", trim($ln));
            if (count($p) < 4) continue;
            $la = (float)$p[2]; $lo = (float)$p[3];
            if (abs($la - $lat) > 0.008) continue;
            if (gp_hav($la, $lo, $lat, $lon) > GP_RADIUS_M) continue;
            $out[$p[0]] = true;
            if (count($out) >= GP_MAX_STOP) break;
        }
        fclose($fh);
        if (count($out) >= GP_MAX_STOP) break;
    }
    return $out;
}

function gp_wanted_load(): array {
    $j = gp_json_read(GP_WANT);
    if (!is_array($j) || !isset($j['st']) || !is_array($j['st'])) return ['st' => []];
    return $j;
}

/* Station vermerken (Schluessel = Koordinate auf 4 Nachkommastellen, ~11 m
 * Raster). Rueckgabe: [key, istNeu, map(stop_id -> [key, ...]), alleKeys]. */
function gp_wanted_station(float $lat, float $lon, array $ids): array {
    $key = sprintf('%.4f|%.4f', $lat, $lon);
    $w = gp_wanted_load();
    $isNew = !isset($w['st'][$key]);
    if ($isNew) {
        $w['st'][$key] = ['ids' => array_keys($ids), 'ts' => time()];
        if (count($w['st']) > GP_WANTED_MAX) {
            uasort($w['st'], function ($a, $b) { return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0); });
            $w['st'] = array_slice($w['st'], -GP_WANTED_MAX, null, true);
        }
        gp_atomic_put(GP_WANT, json_encode($w));
    }
    $map = [];
    foreach ($w['st'] as $k => $v)
        foreach ($v['ids'] ?? [] as $id) $map[$id][] = $k;
    return [$key, $isNew, $map, array_keys($w['st'])];
}

function gp_sched_lru(): void {
    $files = glob(GP_SCHED . '/*.gtf') ?: [];
    if (count($files) <= GP_SCHED_KEEP) return;
    usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
    foreach (array_slice($files, 0, count($files) - GP_SCHED_KEEP) as $f) @unlink($f);
}

/* ---------- Linien-Aufloesung (trip_id -> Linie) --------------------------- */

function gp_res_load(): array {
    $j = gp_json_read(GP_RES);
    $gen = @filemtime(GP_OK) ?: 0;
    if (!is_array($j) || (int)($j['gen'] ?? -1) !== $gen) return ['gen' => $gen, 'ts' => 0, 'm' => []];
    if (!is_array($j['m'] ?? null)) $j['m'] = [];
    return $j;
}

/* Fehlende Trip-IDs per Einmal-Scan ueber trips.gtf (mit routes.gtf) ergaenzen;
 * hoechstens alle GP_RES_TTL Sekunden. */
function gp_resolve(array $need, array &$res): void {
    $missing = [];
    foreach (array_keys($need) as $tid) if (!isset($res['m'][$tid])) $missing[$tid] = true;
    if (!$missing) return;
    if ((time() - (int)($res['ts'] ?? 0)) < GP_RES_TTL) return;
    $routeName = [];
    $rf = @fopen(GP_DIR . '/routes.gtf', 'rb');
    if ($rf) {
        while (!feof($rf)) {
            $ln = fgets($rf);
            if ($ln === false) break;
            $p = explode("\t", trim($ln));
            if (count($p) >= 2) $routeName[$p[0]] = $p[1];
        }
        fclose($rf);
    }
    $tf = @fopen(GP_DIR . '/trips.gtf', 'rb');
    if ($tf) {
        while (!feof($tf)) {
            $ln = fgets($tf);
            if ($ln === false) break;
            $tab1 = strpos($ln, "\t");
            if ($tab1 === false) continue;
            $tid = substr($ln, 0, $tab1);
            if (!isset($missing[$tid])) continue;
            $rest = substr($ln, $tab1 + 1);
            $tab2 = strpos($rest, "\t");
            $rid = trim($tab2 === false ? $rest : substr($rest, 0, $tab2));
            $res['m'][$tid] = array_key_exists($rid, $routeName) ? $routeName[$rid] : '';
            unset($missing[$tid]);
            if (!$missing) break;
        }
        fclose($tf);
    }
    $res['ts'] = time();
    gp_atomic_put(GP_RES, json_encode($res));
}

/* ---------- Extrakt des Realtime-Stroms (je rt.pb-Generation) ------------- */

/* Baut sd (sid -> aktive Fahrten) und sq (Station -> plangebundene Schienen-
 * Fahrten) fuer ALLE wanted-Stationen. $map: stop_id -> [key, ...]. */
function gp_extract_build(array $map, array $keys): array {
    $pb = @file_get_contents(GP_RT);
    if (!is_string($pb) || strlen($pb) < 128) return ['sd' => [], 'sq' => []];
    $n = strlen($pb);
    $sd = []; $tdelay = [];
    $ids = array_fill_keys(array_keys($map), true);
    foreach (gp_fields($pb, 0, $n, 200000) as $ent) {
        if ($ent[0] !== 2 || $ent[1] !== 2) continue;
        $eb = $ent[2]; $el = $ent[3];
        $tu = null;
        foreach (gp_fields($pb, $eb, $eb + $el, 8) as $f) {
            if ($f[0] === 3) { $tu = $f; break; }   // trip_update
        }
        if ($tu === null) continue;
        $tb = $tu[2]; $tl = $tu[3];
        $tripId = null;
        $stus = [];
        foreach (gp_fields($pb, $tb, $tb + $tl, 1000000) as $f) {
            if ($f[0] === 1 && $f[1] === 2) {
                foreach (gp_fields($pb, $f[2], $f[2] + $f[3], 8) as $tf)
                    if ($tf[0] === 1) { $tripId = substr($pb, $tf[2], $tf[3]); break; }
            } elseif ($f[0] === 2) {
                $stus[] = $f;
            }
        }
        if ($tripId === null || $tripId === '') continue;
        foreach ($stus as $stu) {
            $sb = $stu[2]; $sl = $stu[3];
            $sid = null; $delay = null; $seq = 0;
            foreach (gp_fields($pb, $sb, $sb + $sl, 12) as $f) {
                if ($f[0] === 4 && $f[1] === 2) $sid = substr($pb, $f[2], $f[3]);
                elseif ($f[0] === 1 && $f[1] === 0) $seq = $f[2];
                elseif (($f[0] === 2 || $f[0] === 3) && $f[1] === 2 && $delay === null) {
                    foreach (gp_fields($pb, $f[2], $f[2] + $f[3], 4) as $ev)
                        if ($ev[0] === 1) { $delay = $ev[2]; break; }
                }
            }
            // Plausibilitaet: der free-Strom enthaelt bei ca. 6 Prozent der
            // Trips Absurd-Werte nahe INT64_MIN (gesichtet: um -2^63 + 0,04 s
            // bis 0,4 s) sowie Ausreisser. Alles ausserhalb -1 h .. +6 h
            // behandelt der Proxy wie "keine Angabe".
            if ($delay !== null && ($delay < -3600 || $delay > 21600)) $delay = null;
            if ($sid !== null && isset($ids[$sid])) {
                if (!isset($sd[$sid][$tripId])) $sd[$sid][$tripId] = [$delay, $seq];
                elseif ($sd[$sid][$tripId][0] === null && $delay !== null) $sd[$sid][$tripId] = [$delay, $seq];
            }
            if ($delay !== null && !isset($tdelay[$tripId])) $tdelay[$tripId] = $delay;
        }
    }
    // Plan-Extracte: Zeitfenster GP_WIN_BACK Minuten zurueck bis GP_WIN_FWD voraus
    $nowMin = (int)date('G') * 60 + (int)date('i');
    $sq = [];
    $schedTrips = [];
    foreach ($keys as $skey) {
        $f = GP_SCHED . '/' . $skey . '.gtf';
        if (!is_file($f)) continue;
        $fh = @fopen($f, 'rb');
        if (!$fh) continue;
        while (!feof($fh)) {
            $ln = fgets($fh);
            if ($ln === false) break;
            $p = explode("\t", trim($ln));
            if (count($p) < 3) continue;
            $dep = $p[1];
            if (strlen($dep) < 4 || $dep[2] !== ':') continue;
            $hh = (int)substr($dep, 0, 2);   // Tagesueberlauf: 24:xx und 25:xx mod 24
            $mm = (int)substr($dep, 3, 2);
            $dm = ($hh % 24) * 60 + $mm;
            $diff = $dm - $nowMin;
            if ($diff > 720) $diff -= 1440;
            if ($diff < -720) $diff += 1440;
            if ($diff < -GP_WIN_BACK || $diff > GP_WIN_FWD) continue;
            $schedTrips[$p[0]][$skey][] = [sprintf('%02d:%02d', $hh % 24, $mm), (int)$p[2]];
        }
        fclose($fh);
    }
    // Linien fehlender Trips aufloesen (rate-limited)
    $need = $schedTrips;
    foreach ($sd as $byTrip) foreach ($byTrip as $tid => $_) $need[$tid] = 1;
    $res = gp_res_load();
    gp_resolve($need, $res);
    $sdl = [];
    foreach ($sd as $sid => $byTrip) {
        foreach ($byTrip as $tid => [$delay, $seq]) {
            $line = isset($res['m'][$tid]) ? $res['m'][$tid] : null;
            if ($line === null || $line === '') continue;
            $sdl[$sid][] = [$line, $delay, $seq, $tid];
        }
    }
    foreach ($schedTrips as $tid => $byKey) {
        $line = isset($res['m'][$tid]) ? $res['m'][$tid] : null;
        if ($line === null || $line === '') continue;
        $td = array_key_exists($tid, $tdelay) ? $tdelay[$tid] : null;
        if ($td === null) continue;   // Plan ohne Live-Delay: fuer die App wertlos
        foreach ($byKey as $skey => $rows)
            foreach ($rows as [$plan, $seq])
                $sq[$skey][] = [$line, $plan, $td, $seq, $tid];
    }
    return ['sd' => $sdl, 'sq' => $sq];
}

/* Extrakt lesen oder (unter flock) neu bauen. Frisch = rt.pb-mtime und der
 * wanted-Stand ($stamp) stimmen ueberein. */
function gp_extract(array $map, array $keys, string $key, string $stamp): array {
    $gen = @filemtime(GP_RT) ?: 0;
    $ex = gp_json_read(GP_EX);
    if (is_array($ex) && (int)($ex['gen'] ?? -1) === $gen && (string)($ex['stamp'] ?? '') === $stamp) {
        if (!isset($ex['sq'][$key])) $ex['sq'][$key] = [];   // Station ohne Schienenplan
        return $ex;
    }
    $lock = @fopen(GP_EX . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $ex = gp_json_read(GP_EX);
    if (!(is_array($ex) && (int)($ex['gen'] ?? -1) === $gen && (string)($ex['stamp'] ?? '') === $stamp)) {
        $built = gp_extract_build($map, $keys);
        gp_atomic_put(GP_EX, json_encode([
            'gen' => $gen, 'stamp' => $stamp, 'ts' => time(),
            'sd' => $built['sd'], 'sq' => $built['sq']]));
        $ex = gp_json_read(GP_EX);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    if (!is_array($ex)) return ['sd' => [], 'sq' => []];
    if (!isset($ex['sq'][$key])) $ex['sq'][$key] = [];
    return $ex;
}

/* ---------- Antwort fuer eine Station -------------------------------------- */

function gp_respond(float $lat, float $lon): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    if (!gp_dir_ready()) {
        echo json_encode(['ok' => false, 'v' => GP_V, 'e' => 'readonly-dir'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    gp_rt_refresh();
    if (!(is_file(GP_OK) && gp_file_age(GP_OK) < GP_STAT_TTL)) {
        $lock = @fopen(GP_ST . '.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            gp_phases(GP_ST, false, []);
            flock($lock, LOCK_UN);
        }
        if ($lock) fclose($lock);
        if (!(is_file(GP_OK) && gp_file_age(GP_OK) < GP_STAT_TTL)) {
            echo json_encode(['ok' => true, 'v' => GP_V, 'ts' => time(), 'st' => 'warm', 'at' => []],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
    }
    $ids = gp_stops_near($lat, $lon);
    if (!$ids) {
        echo json_encode(['ok' => true, 'v' => GP_V, 'ts' => time(), 'st' => 'ready', 'at' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    [$key, $isNew, $map, $keys] = gp_wanted_station($lat, $lon, $ids);
    // Schienen-Rebuild: bei NEUER Station und einmal je Tag (nach 4:30 Uhr).
    // Ohne Lock (jemand baut gerade): nur weitermachen, der Extrakt bleibt
    // ohne sq, bis der naechste Anfrager den fertigen Stand vorfindet.
    $schedDue = $isNew || !is_file(GP_SDONE) || @filemtime(GP_SDONE) < strtotime('today 04:30');
    if ($schedDue && $map) {
        $lock = @fopen(GP_SST . '.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            if (!is_file(GP_SST))
                gp_atomic_put(GP_SST, json_encode(['phase' => 'head', 'started' => time(), 'done2' => [], 'cds' => []]));
            $t0 = time();
            gp_phases(GP_SST, true, $map);
            flock($lock, LOCK_UN);
            if (is_file(GP_SDONE) && (int)@filemtime(GP_SDONE) >= $t0) @unlink(GP_EX);
        }
        if ($lock) fclose($lock);
        gp_sched_lru();
    }
    $stamp = (string)@filemtime(GP_WANT) . '|' . (string)@filemtime(GP_SDONE);
    $ex = gp_extract($map, $keys, $key, $stamp);
    $at = [];
    $taken = [];
    foreach ((array)($ex['sq'][$key] ?? []) as $row) {
        [$line, $plan, $delay, $seq, $tid] = $row;
        if (!is_string($line) || $line === '') continue;
        $at[] = ['l' => $line, 'd' => $delay, 'p' => $plan, 's' => $seq, 't' => $tid];
        $taken[$tid] = true;
    }
    foreach (array_keys($ids) as $sid) {
        foreach ((array)($ex['sd'][$sid] ?? []) as $row) {
            [$line, $delay, $seq, $tid] = $row;
            if (!is_string($line) || $line === '') continue;
            if (isset($taken[$tid])) continue;
            $at[] = ['l' => $line, 'd' => $delay, 'p' => null, 's' => $seq, 't' => $tid];
            $taken[$tid] = true;
        }
    }
    usort($at, function ($a, $b) { return [$a['p'] ?? 'zz', $a['l'], $a['s']] <=> [$b['p'] ?? 'zz', $b['l'], $b['s']]; });
    if (count($at) > 400) $at = array_slice($at, 0, 400);
    echo json_encode(['ok' => true, 'v' => GP_V, 'ts' => (int)($ex['ts'] ?? time()), 'age' => gp_file_age(GP_RT),
        'st' => 'ready', 'key' => $key, 'n' => count($at), 'at' => $at],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- Probe-Werkzeug -------------------------------------------------- */

function gp_probe(bool $warm): void {
    if (!gp_dir_ready()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['ok' => false, 'v' => GP_V, 'e' => 'readonly-dir'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    if ($warm) {
        gp_rt_refresh();
        if (!(is_file(GP_OK) && gp_file_age(GP_OK) < GP_STAT_TTL)) {
            // Warm baut nur die statischen Tagesdaten; der Schienenplan
            // gehoert zu den wanted-Stationen und wird beim ersten echten
            // Aufruf (oder nach 4:30 Uhr am Tag) gebunden. GP_SDONE wird hier
            // bewusst NICHT beruehrt, sonst wuerde der Cron den Plan-Durchlauf
            // als erledigt markieren, ohne eine Station je gebunden zu haben.
            $lock = @fopen(GP_ST . '.lock', 'c');
            if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                gp_phases(GP_ST, false, []);
                flock($lock, LOCK_UN);
            }
            if ($lock) fclose($lock);
        }
    }
    $w = gp_wanted_load();
    $ex = gp_json_read(GP_EX);
    $res = gp_res_load();
    $state = gp_json_read(GP_ST);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => true, 'v' => GP_V, 'now' => time(),
        'static' => ['ok' => (is_file(GP_OK) && gp_file_age(GP_OK) < GP_STAT_TTL),
                     'age' => is_file(GP_OK) ? gp_file_age(GP_OK) : null,
                     'phase' => is_array($state) ? ($state['phase'] ?? null) : null],
        'sched' => ['files' => count(glob(GP_SCHED . '/*.gtf') ?: []),
                    'day' => is_file(GP_SDONE) ? gp_file_age(GP_SDONE) : null],
        'rt' => ['age' => is_file(GP_RT) ? gp_file_age(GP_RT) : null,
                 'size' => is_file(GP_RT) ? filesize(GP_RT) : null],
        'ex' => ['age' => is_file(GP_EX) ? gp_file_age(GP_EX) : null,
                 'sd' => is_array($ex) ? count($ex['sd'] ?? []) : 0,
                 'sq' => is_array($ex) ? count($ex['sq'] ?? []) : 0],
        'wanted' => count($w['st'] ?? []),
        'resolved' => count($res['m'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- Hauptprogramm --------------------------------------------------- */

try {
    if (isset($_GET['probe'])) { gp_probe(isset($_GET['warm'])); exit; }
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
    if ($lat === null || $lon === null || $lat < 45 || $lat > 56 || $lon < 5 || $lon > 16) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'v' => GP_V, 'e' => 'coords'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    gp_respond($lat, $lon);
} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => false, 'v' => GP_V, 'e' => 'server', 'msg' => substr((string)$e->getMessage(), 0, 200)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
