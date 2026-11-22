<?php
/* ==========================================================================
 * delfiproxy.php - CitySurfers LIVE @ Kiel (v0.1.1)
 * DELFI-Pool-Realtime-Partner fuer pixcaelium.timo-damm.de
 * ==========================================================================
 *  * Zweck: Abfahrtsbretter mit Echtzeitverspaetungen fuer die App, mit
 *   zwei sauber getrennten Datenquellen:
 *     1. SOLLDATEN: nah.sh-GTFS (Fahrplandaten Schleswig-Holstein,
 *        Open-Data-Portal SH, CC BY 4.0). Deckt SH inklusive Kiel/KVG,
 *   Eisenbahn und angrenzende Durchbindungen (VBN, HVV-Rand, NI/HH) ab.
 *     2. ECHTZEIT: DELFI-Pool-Livestrom der Mobilithek (Subscription,
 *        mTLS mit Maschinenzertifikat). Das ist derselbe Strom, den auch
 *   gtfsproxy als free-Ableger kennt - nur ohne gtfs.de-Anonymisierung:
 *        die Pool-Stop-Referenzen sind offizielle de:-Kenner und treffen
 *        exakt die nah.sh-Stop-IDs.
 *
 *  * Aufruf (identisch zum gtfsproxy-Vertrag, damit die App nur die
 *    GTFS_PROXY_URL umschalten muss):
 *      delfiproxy.php?lat=54.3142&lon=10.1314
 *        -> { "ok": true, "ts": .., "age": .., "st": "ready", "key": "..",
 *             "n": 12, "at": [ { "l": "91", "d": 120, "p": "18:05",
 *             "s": 16, "t": 534095762 }, ... ] }
 *      - l Linie (route_short_name), d Verspaetung in Sekunden (int),
 *        p Plan-Abfahrt HH:MM, s stop_sequence, t trip_id.
 *      - Ausgefallene Halte (DELFI schedule_relationship = 1) werden aus
 *        dem Brett entfernt statt mit Verspaetung gezeigt.
 *      delfiproxy.php?probe=1    -> Status aller Cache-Stufen (Wartung).
 *      delfiproxy.php?warm=1     -> wie probe, startet den Tagesbau.
 *      delfiproxy.php?probe=1&warm=1
 *
 *  * Verzeichnis-Layout neben dieser Datei:
 *      delficerts/cert.pem  - Maschinenzertifikat: Leaf + Chain
 *                             (= maschine-cert-full.pem aus der
 *                             Zertifikats-E-Mail)
 *      delficerts/key.pem   - unverschluesselter Privatschluessel
 *                             (= maschine-key.pem)
 *      delficerts/.htaccess - "Require all denied" (s. u.)
 *      delfidata/           - PHP-beschreibbar (Caches, Tagesdaten);
 *                             ebenfalls per .htaccess sperren
 *    Die Zertifikate werden zur LAUFZEIT gelesen (kein Klartext in dieser
 *    Datei): fuer den Ritualaustausch genuegt es, die beiden PEM-Dateien
 *    zu ersetzen, am besten mit frischem Zip-Download davor (?warm=1),
 *    damit der Overlay neu aufgebaut wird.
 *
 *  * Einrichtung auf dem Server (Netcup):
 *      - Datei neben index.html / donkeyproxy.php / gtfsproxy.php legen
 *        und delficerts/ + delfidata/ anlegen, delfidata/ dem Web-User
 *        schreibbar geben (0775 oeki je nach SuEXEC-Recht).
 *      - .htaccess in BEIDE Verzeichnisse:
 *          Require all denied
 *      - Optionaler Cron (Tagesbau um 5:15 Uhr vor dem ersten Nutzer):
 *          15 5 * * * curl -s "https://pixcaelium.timo-damm.de/apps/citysurfers/delfiproxy.php?warm=1" > /dev/null
 *
 *  * Fehler-Philosophie: wie donkeyproxy/gtfsproxy - dieser Proxy darf
 *    niemals abstuerzen, immer JSON antworten. Wenn die Solldaten (noch)
 *    nicht gebaut sind: ok:true, st:"warm", at:[]. Ohne Zertifikat/
 *    Pool-Strom: das Brett laeuft mit Solldaten und d=0 weiter, ?probe
 *    meldet den Grund. Falsche Koordinaten: ok:false, e:"coords".
 *
 *  * Quellen und Format-Erkenntnisse (Stand 2026-11-22, empirisch am
 *    DELFI-Pool-Strom verschiedenes Reverse Engineering):
 *      - Der Protobuf-Strom ist KEIN oeffentliches GTFS-RT-FeedMessage-
 *        Format (trotz Versions-Header "2.0"), sondern DELFI-eigene mit
 *        GTFS-RT-artiger Semantik:
 *          f1 Header { ts }, f2 je Eintrag (Fahrt):
 *            f1 id "Betriebsnummer|Betriebstag",
 *            f3 { f1 Fahrtbeschreibung { f2 Plan-Start, f5 Linien-Ressource },
 *                 f2* Halt-Bloecke { f1 stop_sequence,
 *                                    f3 { f1 Verspaetung int64 (signed),
 *                                         f2 Absolutzeit Epoch },
 *                                    f4 Stop-Referenz,
 *                                    f5 schedule_relationship (1=SKIPPED) } }
 *      - Verspaetungen sind signed int64 im Two's-Complement-Varint
 *        (frueher = negativ), Fix siehe dp_varint().
 *      - Die Join-Kaskade zur nah.sh-Statik (belegt in Tests vom
 *        2026-11-22): Linien-Ressource (normiert) == GTFS route_id,
 *        Plan-Startzeit == erste Abfahrt des GTFS-Trips (99,4 % der
 *        SH-Fahrten trefferbildlich), Stop-Referenz-Basis == GTFS
 *        stop_id (75 % direkt, Rest mit Bahnsteig-Suffix "::B2" oeki,
 *        nach Normalisierung ~100 %). Betriebsnummer und GTFS-trip_id
 *        sind NICHT identisch - der Join laeuft ausschliesslich ueber
 *        Linie+Startzeit und bleibt damit robust gegen gtfs.de-weite
 *        ID-Anonymisierung (die gtfs.de-free-Zips haben NULL de:-IDs).
 * ==========================================================================
 */

const DP_V          = 'delfiproxy 0.1.1';
const DP_SCHEMA     = 'delfiproxy-stimes-2';   // v0.1.1: Weicht der Stempel ab, baut der Proxy den Tagesbau neu auf
const DP_NAHSH_URL  = 'https://opendata.schleswig-holstein.de/dataset/5e0652d8-7f59-42fc-92ab-a6358f800e1d/resource/f0eff75f-88de-411c-8bdc-0a55bb67d9fd/download/fahrplandaten';
const DP_SUB_URL    = 'https://mobilithek.info:8443/mobilithek/api/v1.0/container/subscription?subscriptionID=1061368693622788096';
const DP_POOL_TTL   = 60;        // DELFI-Pool: Anbieter-Rhythmus 1 Minute
const DP_POOL_MIN_B = 400000;    // Plausibilitaet: Strom ~7 MB
const DP_POOL_MAX_B = 40000000;
const DP_STAT_TTL  = 71400;     // ~20 h: nah.sh-Zip und Tagesdaten
const DP_BUDGET_S  = 20.0;      // Bau-Arbeit je Anfrage (Sekunden)
const DP_RADIUS_M  = 600;      // Stop-Umkreis je Anfrage (wie gtfsproxy)
const DP_MAX_STOP  = 400;      // Obergrenze Stops im Umkreis
const DP_MAX_ROWS  = 400;      // Obergrenze Bretteintraege
const DP_WIN_BACK  = 40;       // Plan-Fenster: 40 Minuten zurueck ...
const DP_WIN_FWD   = 300;      // ... bis 5 Stunden voraus
const DP_TIMEOUT_S = 25;       // Socket-Timeout Upstream
const DP_UA        = 'CitySurfersLIVE-Kiel/1.5.2 (+https://pixcaelium.timo-damm.de/apps/citysurfers/)';

define('DP_DIR',   __DIR__ . '/delfidata');
define('DP_TMP',   DP_DIR . '/tmp');
define('DP_BK',    DP_DIR . '/bk');          // Stop-Buckets des Tages
define('DP_ZIP',   DP_DIR . '/nahsh.zip');
define('DP_PB',    DP_DIR . '/pool.pb');     // roher DELFI-Strom
define('DP_OVL',   DP_DIR . '/overlay.json.gz'); // verarbeitete Verspaetungen
define('DP_OVL_STAMP', DP_DIR . '/ovl.stamp');
define('DP_STATE', DP_DIR . '/state.json');  // Phasen des Tagesbaus
define('DP_SCHEMA_F', DP_DIR . '/schema.txt'); // v0.1.1: Schema-Stempel des stimes-Baus
define('DP_DAY',   DP_DIR . '/day.txt');     // YYYYMMDD des fertigen Baus
define('DP_CERT_DIR', __DIR__ . '/delficerts');

date_default_timezone_set('Europe/Berlin');

/* ---------- Kleinkram: Verzeichnis, Dateien ------------------------------ */

function dp_dir_ready(): bool {
    if (!is_dir(DP_DIR))  @mkdir(DP_DIR,  0775, true);
    if (!is_dir(DP_TMP))  @mkdir(DP_TMP,  0775, true);
    if (!is_dir(DP_BK))   @mkdir(DP_BK,   0775, true);
    return is_dir(DP_DIR) && is_writable(DP_DIR);
}

function dp_file_age(string $f): int {
    $m = @filemtime($f);
    return $m ? (time() - $m) : PHP_INT_MAX;
}

function dp_json_read(string $f): ?array {
    $raw = @file_get_contents($f);
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function dp_atomic_put(string $f, string $content): void {
    $tmp = $f . '.new';
    if (@file_put_contents($tmp, $content, LOCK_EX) !== false) @rename($tmp, $f);
}

/* ---------- Protobufpuffer-Werkzeug (ohne Extension) --------------------- */

/* Varint, 64 Bit, signed im Two's-Complement: DELFI-Verspaetungen sind
 * int64 - zehn Bytes bedeuten negativ, dann ist $v der Betrag des
 * Komplements. gtfsprobes gp_varint() nachempfunden. */
function dp_varint(string $s, int &$p, int $n): int {
    $v = 0; $sh = 0; $cnt = 0;
    while ($p < $n) {
        $b = ord($s[$p]); $p++; $cnt++;
        if ($sh <= 56) $v |= ($b & 0x7f) << $sh;
        if (($b & 0x80) === 0) break;
        $sh += 7;
    }
    if ($cnt >= 10) return -(PHP_INT_MAX - $v + 1); // negativ: Two's-Complement
    return $v;
}

/* Felder einer Nachricht: je Eintrag [feld, wire, a, b] - wire 2: Daten
 * via substr($s, $a, $b); wire 0: a = Varint-Wert. */
function dp_fields(string $s, int $p0, int $n, int $max): array {
    $out = []; $p = $p0;
    while ($p < $n && count($out) < $max) {
        $key = dp_varint($s, $p, $n);
        if ($key < 0) break;
        $f = $key >> 3; $w = $key & 7;
        if ($w === 0) { $v = dp_varint($s, $p, $n); $out[] = [$f, 0, $v, 0]; }
        elseif ($w === 1) { if ($p + 8 > $n) break; $out[] = [$f, 1, substr($s, $p, 8), 0]; $p += 8; }
        elseif ($w === 2) {
            $l = dp_varint($s, $p, $n);
            if ($l < 0 || $p + $l > $n) break;
            $out[] = [$f, 2, $p, $l]; $p += $l;
        }
        elseif ($w === 5) { if ($p + 4 > $n) break; $out[] = [$f, 5, substr($s, $p, 4), 0]; $p += 4; }
        else break;
    }
    return $out;
}

/* ---------- HTTP ---------------------------------------------------------- */

function dp_http_to_file(string $url, string $dest, int $timeout = DP_TIMEOUT_S): bool {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
        'user_agent' => DP_UA,
        'header' => 'User-Agent: ' . DP_UA . "\r\nAccept: */*\r\nAccept-Encoding: identity\r\n"]]);
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
    fclose($src); fclose($dst);
    return $ok && @filesize($dest) > 0;
}

/* Zertifikate: Pfad-Konstanten lesen wir zur LAUFZEIT - nichts steht im
 * Quelltext. Rueckgabe: null = ok (cert+key vorhanden und lesbar),
 * sonst Klartext-Grund fuer ?probe. */
function dp_certs_problem(): ?string {
    if (!is_file(DP_CERT_DIR . '/cert.pem')) return 'cert.pem fehlt in delficerts/';
    if (!is_readable(DP_CERT_DIR . '/cert.pem')) return 'cert.pem nicht lesbar';
    if (!is_file(DP_CERT_DIR . '/key.pem')) return 'key.pem fehlt in delficerts/';
    if (!is_readable(DP_CERT_DIR . '/key.pem')) return 'key.pem nicht lesbar';
    return null;
}

/* DELFI-Pool-Strom per mTLS holen. Bevorzugt cURL (getrennte cert/key-
 * Pfade), sonst Stream-Kontext mit zusammengefuegter PEM-Datei (wird in
 * delfidata zwischengehalten, dort greift die .htaccess-Sperre). */
function dp_pool_fetch(): bool {
    if (dp_certs_problem() !== null) return false;
    $cert = DP_CERT_DIR . '/cert.pem';
    $key  = DP_CERT_DIR . '/key.pem';
    $dest = DP_PB . '.new';
    if (function_exists('curl_init')) {
        $fh = @fopen($dest, 'wb');
        if (!$fh) return false;
        $ch = curl_init(DP_SUB_URL);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => DP_TIMEOUT_S + 45,
            CURLOPT_CONNECTTIMEOUT => DP_TIMEOUT_S,
            CURLOPT_USERAGENT => DP_UA,
            CURLOPT_SSLCERT => $cert,
            CURLOPT_SSLKEY => $key,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $code !== 200) { @unlink($dest); return false; }
    } else {
        $combo = DP_DIR . '/certkey.pem';
        $c = @file_get_contents($cert); $k = @file_get_contents($key);
        if (!is_string($c) || !is_string($k)) return false;
        if (@file_put_contents($combo, $c . "\n" . $k, LOCK_EX) === false) return false;
        @chmod($combo, 0600);
        $ctx = stream_context_create(['ssl' => [
            'local_cert' => $combo,
            'verify_peer' => true, 'verify_peer_name' => true,
        ]]);
        $src = @fopen(DP_SUB_URL, 'rb', false, $ctx);
        if (!$src) return false;
        $dst = @fopen($dest, 'wb');
        if (!$dst) { fclose($src); return false; }
        $ok = true;
        while (!feof($src)) {
            $chunk = fread($src, 1048576);
            if ($chunk === false || $chunk === '') continue;
            if (fwrite($dst, $chunk) === false) { $ok = false; break; }
        }
        fclose($src); fclose($dst);
        if (!$ok) { @unlink($dest); return false; }
    }
    $sz = @filesize($dest);
    if ($sz === false || $sz < DP_POOL_MIN_B || $sz > DP_POOL_MAX_B) {
        @unlink($dest);
        return false;
    }
    @rename($dest, DP_PB);
    return true;
}

function dp_pool_refresh(): void {
    $lock = @fopen(DP_PB . '.lock', 'c');
    if (!$lock) return;
    flock($lock, LOCK_EX);
    if (dp_file_age(DP_PB) > DP_POOL_TTL) {
        // Fehlschlag: alten Strom behalten (Grace, bis DP_STAT_TTL alt).
        dp_pool_fetch();
    }
    flock($lock, LOCK_UN); fclose($lock);
}

/* ---------- Lokales Zip: Zentralverzeichnis, Member-Dekomprimierung ----- */

/* members name -> [offset, csize] aus dem LOKALEI Zip. */
function dp_zip_central_local(string $zip): ?array {
    $sz = @filesize($zip);
    if (!$sz || $sz < 200) return null;
    $fh = @fopen($zip, 'rb');
    if (!$fh) return null;
    $tailLen = min($sz, 65536);
    fseek($fh, $sz - $tailLen);
    $tail = fread($fh, $tailLen);
    if (!is_string($tail)) { fclose($fh); return null; }
    $e = strrpos($tail, "PK\x05\x06");
    if ($e === false) { fclose($fh); return null; }
    $cdSize = unpack('V', substr($tail, $e + 12, 4))[1];
    $cdOff  = unpack('V', substr($tail, $e + 16, 4))[1];
    if ($cdOff + $cdSize > $sz) { fclose($fh); return null; }
    fseek($fh, $cdOff);
    $cd = fread($fh, $cdSize);
    fclose($fh);
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

/* EINEN Zip-Member zeilenweise dekomprimieren und je Zeile $cb(rufen). */
function dp_zip_member_lines(string $zip, string $member, callable $cb): bool {
    $cd = dp_zip_central_local($zip);
    if (!is_array($cd) || !isset($cd[$member])) return false;
    [$off, $csize] = $cd[$member];
    $fh = @fopen($zip, 'rb');
    if (!$fh) return false;
    fseek($fh, $off);
    $lh = fread($fh, 30);
    if (!is_string($lh) || strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") { fclose($fh); return false; }
    $nlen = unpack('v', substr($lh, 26, 2))[1];
    $elen = unpack('v', substr($lh, 28, 2))[1];
    fseek($fh, $off + 30 + $nlen + $elen);
    $inf = @inflate_init(ZLIB_ENCODING_RAW);
    if ($inf === false) { fclose($fh); return false; }
    $left = $csize;
    $buf = '';
    while ($left > 0) {
        $comp = fread($fh, min(1048576, $left));
        if (!is_string($comp) || $comp === '') break;
        $left -= strlen($comp);
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

/* CSV-Zeile (GTFS ist RFC-4180-ish mit Doppelten Hochkommata). */
function dp_csv(string $ln): array {
    if ($ln === '') return [];
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

function dp_time_sec(string $h): int {
    $p = explode(':', $h);
    if (count($p) < 2) return -1;
    $hh = (int)$p[0]; $mm = (int)($p[1] ?? 0); $ss = (int)($p[2] ?? 0);
    return $hh * 3600 + $mm * 60 + $ss;
}

/* Stop-Referenz-Basis "de:01002:49076::B2" -> "de:01002:49076". */
function dp_ref_base(string $ref): string {
    $p = strpos($ref, '::');
    return $p === false ? $ref : substr($ref, 0, $p);
}

/* Linien-Ressource normieren: leere End-Segmente und Richtungs-Suffix
 * "_d" fallen lassen, damit "de:NAHSH:01002|91:_1" auf die GTFS-route_id
 * "de:NAHSH:01002|91:" passt. */
function dp_norm_route(string $s): string {
    $s = preg_replace('/_\d+$/', '', trim($s));
    $t = explode(':', $s);
    while (count($t) > 1 && trim($t[count($t) - 1]) === '') array_pop($t);
    return implode(':', $t);
}

/* Buckets: deterministischer, sicherer Dateiname je Stop-ID. */
function dp_bucket_name(string $stopId): string {
    return preg_replace('/[^A-Za-z0-9_.-]/', '_', $stopId) . '.' . substr(md5($stopId), 0, 6) . '.tsv';
}

/* ---------- Statischer Tagesbau (Phasen, budgetiert) --------------------- */
/* Phasen: zip -> svc -> stops -> routes -> trips -> stimes -> fin
 * Der stimes-Lauf streamar stop_times.txt (1,5 Mio Zeilen) und hakt
 * sich per Zeilen-Offset selbst fort; jede Anfrage traegt bis zu
 * DP_BUDGET_S Sekunden bei (wie gtfsproxy Zeus-Maschinen diese moven). */

function dp_state_load(): array {
    $s = dp_json_read(DP_STATE);
    if (!is_array($s)) $s = [];
    $s += ['phase' => 'zip', 'off' => 0, 'done' => [], 'today' => null];
    return $s;
}

function dp_state_save(array $s): void {
    dp_atomic_put(DP_STATE, json_encode($s));
}

function dp_today_int(): int {
    return (int)date('Ymd');
}

/* Aktive GTFS-Services des Tages (calendar + calendar_dates). */
function dp_active_services(string $zip): array {
    $today = date('Ymd');
    $dow = (int)date('N');  // 1=Mo .. 7=So
    $bit = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 16, 6 => 32, 7 => 64][$dow] ?? 0;
    $act = [];
    dp_zip_member_lines($zip, 'calendar.txt', function ($ln) use (&$act, $today, $bit) {
        $c = dp_csv($ln);
        if (count($c) < 10) return;
        if ($c[0] === 'service_id') return;
        if (strcmp($today, $c[8]) < 0 || strcmp($today, $c[9]) > 0) return;
        $on = (int)$c[$bit] ?? 0;
        $act[$c[0]] = ($on === 1);
    });
    dp_zip_member_lines($zip, 'calendar_dates.txt', function ($ln) use (&$act, $today) {
        $c = dp_csv($ln);
        if (count($c) < 3) return;
        if ($c[0] === 'service_id') return;
        if ($c[1] !== $today) return;
        $act[$c[0]] = ((int)$c[2] === 1);
    });
    return array_keys(array_filter($act, function ($v) { return $v; }));
}

/* Eine Runde am Tagesbau. Rueckgabe: true, wenn der Bau heute fertig ist. */
function dp_build_round(&$budget): bool {
    $s = dp_state_load();
    $today = dp_today_int();

    // Neuer Tag -> alles zuruecksetzen (Buckets des Vortags fallen weg,
    // Alter Zustand wuerde ein Brett mit falschen Diensten erzeugen).
    if ((int)$s['today'] !== $today) {
        $s = ['phase' => 'zip', 'off' => 0, 'done' => [], 'today' => $today];
        @rename(DP_BK, DP_BK . '.old');
        @mkdir(DP_BK, 0775, true);
        dp_rrmdir(DP_BK . '.old');
        dp_state_save($s);
    }

    // v0.1.1: Schema-Stempel des stimes-Baus. Weicht er ab (z. B. nach dem
    // Austausch dieser Datei gegen eine neuere Proxy-Version oder nach einem
    // unvollstaendigen Bau aehnlich dem Resume-Sprung-Bug von v0.1.0), baut
    // der Proxy die Stop-Buckets des Tages sauber neu auf; das nah.sh-Zip
    // bleibt liegen, solange es frisch ist, und firstdep/tmap werden neu
    // erzeugt. So heilt sich ein Bilderbuch-Deploy per ?warm=1 selbst.
    if (trim((string)@file_get_contents(DP_SCHEMA_F)) !== DP_SCHEMA) {
        @rename(DP_BK, DP_BK . '.old');
        @mkdir(DP_BK, 0775, true);
        dp_rrmdir(DP_BK . '.old');
        @unlink(DP_DIR . '/firstdep.idx');
        @unlink(DP_DIR . '/firstdep.part');
        @unlink(DP_DIR . '/tmap.ser');
        dp_atomic_put(DP_SCHEMA_F, DP_SCHEMA);
        $s = ['phase' => 'zip', 'off' => 0, 'seen' => 0, 'done' => [], 'today' => $today];
        dp_state_save($s);
    }

    $zipOk = is_file(DP_ZIP) && dp_file_age(DP_ZIP) < DP_STAT_TTL;
    if (!$zipOk) {
        if (microtime(true) + 1.0 > $budget) return false;
        if (!dp_http_to_file(DP_NAHSH_URL, DP_ZIP . '.new', 120)) return false;
        if (@filesize(DP_ZIP . '.new') < 1000000) { @unlink(DP_ZIP . '.new'); return false; }
        @rename(DP_ZIP . '.new', DP_ZIP);
        // Neuer Feedstand: Tagesbau sauber neu starten, sonst wuerden die
        // Stop-Buckets (Append-Modus) Zeilen doppelt bekommen.
        $s = ['phase' => 'svc', 'off' => 0, 'seen' => 0, 'done' => [], 'today' => $today];
        @rename(DP_BK, DP_BK . '.old');
        @mkdir(DP_BK, 0775, true);
        dp_rrmdir(DP_BK . '.old');
        @unlink(DP_DIR . '/firstdep.part');
        @unlink(DP_DIR . '/tmap.ser');
        dp_state_save($s);
    }

    switch ($s['phase']) {
        case 'zip':  // Zip existiert schon frisch -> weiter mit svc
            $s['phase'] = 'svc';
            dp_state_save($s);
            return false;

        case 'svc':
            $t0 = microtime(true);
            $svc = dp_active_services(DP_ZIP);
            if (!count($svc)) return false;
            dp_atomic_put(DP_DIR . '/svc.txt', implode("\n", $svc));
            $s['phase'] = 'stops';
            $s['off'] = 0;
            dp_state_save($s);
            $budget -= (microtime(true) - $t0);
            return microtime(true) < $budget;

        case 'stops':
            $t0 = microtime(true);
            $out = [];
            dp_zip_member_lines(DP_ZIP, 'stops.txt', function ($ln) use (&$out) {
                $c = dp_csv($ln);
                if (count($c) < 6) return;
                if ($c[0] === 'stop_id') return;   // Kopf
                /* nah.sh-Spalten: 0 stop_id, 4 stop_lat, 5 stop_lon. */
                $la = (float)$c[4]; $lo = (float)$c[5];
                if ($la < 45 || $la > 56 || $lo < 5 || $lo > 16) return;
                $out[] = $c[0] . "\t" . $la . "\t" . $lo;
            });
            dp_atomic_put(DP_DIR . '/stops.idx', implode("\n", $out) . "\n");
            unset($out);
            $s['phase'] = 'routes';
            $s['off'] = 0;
            dp_state_save($s);
            $budget -= (microtime(true) - $t0);
            return microtime(true) < $budget;

        case 'routes':
            /* route_id \t Kurzname \t route_type. Der Typ entscheidet am
             * Ende, ob eine Brettzeile als Schiene antwortet (p gesetzt,
             * gtfsproxy-Kontrakt: rv/fv = Schiene = Planzeit, nv = Bus =
             * p null). Die App wertet genau danach (rsOverlayGtfs). */
            $t0 = microtime(true);
            $out = [];
            dp_zip_member_lines(DP_ZIP, 'routes.txt', function ($ln) use (&$out) {
                $c = dp_csv($ln);
                if (count($c) < 5) return;
                if ($c[0] === 'route_id') return;
                $short = $c[2] !== '' ? $c[2] : ($c[3] !== '' ? $c[3] : $c[0]);
                $out[] = $c[0] . "\t" . $short . "\t" . (int)$c[4];
            });
            dp_atomic_put(DP_DIR . '/routes.idx', implode("\n", $out) . "\n");
            unset($out);
            $s['phase'] = 'trips';
            $s['off'] = 0;
            dp_state_save($s);
            $budget -= (microtime(true) - $t0);
            return microtime(true) < $budget;

        case 'trips':
            /* trips: aktive Services laden, trip->route-Karte aufbauen und
             * zusammen mit den Stop-Bucket-Haendlern an stimes weitergeben.
             * Da stop_times sehr gross ist, schreiben wir die Karte als
             * kompaktes PHP-serialisiertes Zwischenprodukt. */
            $t0 = microtime(true);
            $svc = [];
            $raw = @file_get_contents(DP_DIR . '/svc.txt');
            if (is_string($raw) && $raw !== '') {
                foreach (explode("\n", $raw) as $sid) if ($sid !== '') $svc[$sid] = true;
            }
            if (!count($svc)) return false;
            $tmap = [];
            dp_zip_member_lines(DP_ZIP, 'trips.txt', function ($ln) use (&$tmap, &$svc) {
                static $hdr = true;
                if ($hdr) { $hdr = false; return; }
                $c = dp_csv($ln);
                if (count($c) < 3) return;
                if (!isset($svc[$c[1]])) return;
                $tmap[$c[2]] = $c[0];
            });
            if (!count($tmap)) return false;
            @file_put_contents(DP_DIR . '/tmap.ser', serialize($tmap), LOCK_EX);
            unset($tmap);
            $s['phase'] = 'stimes';
            $s['off'] = 0;
            $s['seen'] = 0;
            $s['first'] = 0;
            $s['rows'] = 0;
            dp_state_save($s);
            $budget -= (microtime(true) - $t0);
            return microtime(true) < $budget;

        case 'stimes':
            /* Streamender Durchlauf durch stop_times.txt mit Offset-Fort-
             * schritt alle 20000 Zeilen (Unterbrechungssicherheit: je Zeile
             * nach Offset geprueft, doppelte Zeilen sind harmlos). */
            $t0 = microtime(true);
            $tmap = @unserialize((string)@file_get_contents(DP_DIR . '/tmap.ser'));
            if (!is_array($tmap) || !count($tmap)) { $s['phase'] = 'trips'; dp_state_save($s); return false; }
            $routes = [];
            $raw = @file_get_contents(DP_DIR . '/routes.idx');
            if (is_string($raw) && $raw !== '') {
                foreach (explode("\n", $raw) as $ln) {
                    $p = explode("\t", $ln, 3);
                    if (count($p) === 3) $routes[$p[0]] = [$p[1], (int)$p[2]];
                }
            }
            $off = (int)$s['off'];
            $seen = 0;        // v0.1.1: nur Zeilen-Zaehler DIESES Aufrufs, Resume laeuft ausschliesslich ueber $off
            $proc = 0;        // v0.1.1: in diesem Aufruf verarbeitete Zeilen (Basis fuer den Resume-Offset)
            $firstCnt = (int)$s['first'];
            $rowsCnt = (int)$s['rows'];
            $handles = [];
            $fdh = @fopen(DP_DIR . '/firstdep.part', $off === 0 ? 'w' : 'a');
            $done = false;
            dp_zip_member_lines(DP_ZIP, 'stop_times.txt', function ($ln) use (&$seen, &$proc, &$off, &$firstCnt, &$rowsCnt, &$tmap, &$routes, &$handles, &$fdh, &$budget, &$done) {
                if ($seen < $off) { $seen++; return; }   // fruehere Runden: Zeile nur ueberspringen
                $seen++;
                if ($done) return;                       // Budget durch: Rest nur zaehlen, verarbeitet bleibt verarbeitet
                $proc++;
                $c = dp_csv($ln);
                if (count($c) < 4) return;
                if ($c[0] === 'trip_id') return;
                $trip = $c[0];
                if (!isset($tmap[$trip])) return;
                $depS = dp_time_sec($c[7] ?? '');
                if ($depS < 0) $depS = dp_time_sec($c[6] ?? '');
                if ($depS < 0) return;
                $seq = (int)$c[2];
                $stop = $c[1];
                $rt = $routes[$tmap[$trip]] ?? null;
                if (!is_array($rt) || $rt[0] === '') return;
                $line = $rt[0];
                $rowsCnt++;
                $bf = DP_BK . '/' . dp_bucket_name($stop);
                $h = $handles[$stop] ?? ($handles[$stop] = @fopen($bf, 'a'));
                if ($h) fwrite($h, $depS . "\t" . $trip . "\t" . $seq . "\t" . $line . "\t" . $rt[1] . "\n");
                /* Erste Abfahrt je Trip fuer den DELFI-Join notieren:
                 * Zeilen liegen nach trip gruppiert, seq 0 markiert start. */
                if ($seq === 0 && $fdh) {
                    fwrite($fdh, dp_norm_route($tmap[$trip]) . '|' . $depS . "\t" . $trip . "\n");
                    $firstCnt++;
                }
                /* v0.1.1: Budget-Check NACH dem Schreiben. Wichtig ist der
                 * Resume-Offset: Er darf nur um VERARBEITETE Zeilen wachsen,
                 * nicht ans Dateiende springen, sonst verschluckt der naechste
                 * Lauf den Rest und der Bau gilt falsch als fertig (v0.1.0
                 * hatte dadurch nur ca. 1000 statt 7600 Haltestellen-Buckets). */
                if ($proc % 20000 === 0 && microtime(true) > $budget) { $done = true; }
            });
            foreach ($handles as $h) if (is_resource($h)) @fclose($h);
            if ($fdh) @fclose($fdh);
            $s['off'] = $off + $proc;           // v0.1.1: genau hier macht der naechste Aufruf weiter
            $s['seen'] = $off + $seen;          // Zeilen, die mindestens einmal konsumiert sind (nur Diagnose)
            $s['first'] = $firstCnt;
            $s['rows'] = $rowsCnt;
            if (!$done) $s['phase'] = 'fin';
            dp_state_save($s);
            unset($tmap, $routes);
            return $s['phase'] === 'fin';

        case 'fin':
            @rename(DP_DIR . '/firstdep.part', DP_DIR . '/firstdep.idx');
            dp_atomic_put(DP_DAY, (string)dp_today_int());
            @unlink(DP_DIR . '/tmap.ser');
            $s['phase'] = 'done';
            dp_state_save($s);
            return true;
    }
    return false;
}

function dp_rrmdir(string $d): void {
    if (!is_dir($d)) return;
    $it = @opendir($d);
    if (!$it) return;
    while (($f = readdir($it)) !== false) {
        if ($f === '.' || $f === '..') continue;
        @unlink($d . '/' . $f);
    }
    closedir($it);
    @rmdir($d);
}

function dp_static_ready(): bool {
    return is_file(DP_DAY) && (int)@file_get_contents(DP_DAY) === dp_today_int()
        && is_file(DP_DIR . '/firstdep.idx')
        && trim((string)@file_get_contents(DP_SCHEMA_F)) === DP_SCHEMA;   // v0.1.1: Schema-Stempel, sonst Neubau
}

/* Baut in Schritten bis das Tagesbrett steht; Rueckgabe: fertig? */
function dp_build_until_ready(&$budget): bool {
    while (microtime(true) < $budget) {
        if (dp_static_ready()) return true;
        if (!dp_build_round($budget)) return dp_static_ready();
    }
    return dp_static_ready();
}

/* ---------- DELFI-Overlay: Parse + Join + Cache --------------------------- */

/* Handshake-Pruefung des Maschinenzertifikats fuer ?probe (openssl-ext
 * ist ueblich auf gemeinsamen Hosts; ohne sie spaert nur die Nutzung). */
function dp_cert_info(): array {
    $out = ['present' => false];
    $p = dp_certs_problem();
    if ($p !== null) { $out['error'] = $p; return $out; }
    $out['present'] = true;
    if (function_exists('openssl_x509_parse')) {
        $c = @openssl_x509_parse((string)@file_get_contents(DP_CERT_DIR . '/cert.pem'));
        if (is_array($c)) {
            $out['cn'] = $c['subject']['CN'] ?? '';
            $out['valid_to'] = isset($c['validTo_time_t']) ? (int)$c['validTo_time_t'] : null;
            $out['expired'] = isset($c['validTo_time_t']) ? ((int)$c['validTo_time_t'] < time()) : null;
        }
    }
    return $out;
}

/* EINEN Pool-Strom in inode-freundliche Overlay-Bloecke zerlegen.
 * Rueckgabe: [stats, trip|seq -> [delay, time, rel]] */
function dp_overlay_from_pool(): array {
    $raw = @file_get_contents(DP_PB);
    if (!is_string($raw) || strlen($raw) < 1000) return [null, null];
    $n = strlen($raw);
    $today = date('Ymd');
    $yest = date('Ymd', time() - 86400);
    $blocks = [];
    $st = ['trips' => 0, 'sh' => 0, 'joined' => 0, 'amb' => 0, 'blocks' => 0, 'skip' => 0, 'ts' => 0];

    /* Join-Index nur laden, wenn wir ueberhaupt parstern muessen. */
    $fd = [];
    if (is_file(DP_DIR . '/firstdep.idx')) {
        $fh = @fopen(DP_DIR . '/firstdep.idx', 'rb');
        if ($fh) {
            while (!feof($fh)) {
                $ln = fgets($fh, 1024);
                if (!is_string($ln)) break;
                $p = explode("\t", trim($ln), 2);
                if (count($p) === 2) {
                    if (!isset($fd[$p[0]])) $fd[$p[0]] = $p[1];
                    else $fd[$p[0]] .= ',' . $p[1]; // Kandidaten, mit Komma
                }
            }
            fclose($fh);
        }
    }

    foreach (dp_fields($raw, 0, $n, 200000) as [$f0, $w0, $a0, $b0]) {
        if ($f0 === 1 && $w0 === 2) {   // Header: ts verschachtelt in Feld 3
            foreach (dp_fields($raw, $a0, $a0 + $b0, 8) as [$fh, $wh, $ah, $bh])
                if ($fh === 3 && $wh === 0) $st['ts'] = $ah;
        } elseif ($f0 === 2 && $w0 === 2) {
            $st['trips']++;
            $id = null; $start = null; $tag = null; $line = null; $stops = [];
            foreach (dp_fields($raw, $a0, $a0 + $b0, 8) as [$f1, $w1, $a1, $b1]) {
                if ($f1 === 1 && $w1 === 2) $id = substr($raw, $a1, $b1);
                elseif ($f1 === 3 && $w1 === 2) {
                    foreach (dp_fields($raw, $a1, $a1 + $b1, 2000) as [$f2, $w2, $a2, $b2]) {
                        if ($f2 === 1 && $w2 === 2) {
                            foreach (dp_fields($raw, $a2, $a2 + $b2, 8) as [$f3, $w3, $a3, $b3]) {
                                if ($w3 === 2) {
                                    if ($f3 === 2) $start = substr($raw, $a3, $b3);
                                    elseif ($f3 === 3) $tag = substr($raw, $a3, $b3);
                                    elseif ($f3 === 5) $line = substr($raw, $a3, $b3);
                                }
                            }
                        } elseif ($f2 === 2 && $w2 === 2) {
                            /* Halt-Block */
                            $seq = null; $delay = null; $time = null; $ref = null; $rel = 0;
                            foreach (dp_fields($raw, $a2, $a2 + $b2, 8) as [$f4, $w4, $a4, $b4]) {
                                if ($f4 === 1 && $w4 === 0) $seq = $a4;
                                elseif ($f4 === 3 && $w4 === 2) {
                                    foreach (dp_fields($raw, $a4, $a4 + $b4, 4) as [$f5, $w5, $a5]) {
                                        if ($f5 === 1 && $w5 === 0) $delay = $a5;
                                        elseif ($f5 === 2 && $w5 === 0) $time = $a5;
                                    }
                                } elseif ($f4 === 4 && $w4 === 2) $ref = substr($raw, $a4, $b4);
                                elseif ($f4 === 5 && $w4 === 0) $rel = $a4;
                            }
                            if ($id === null) $id = 'e' . $st['trips'];
                            $stops[] = [$id, $seq, $delay, $time, $ref, $rel];
                        }
                    }
                }
            }
            /* SH-Filter: nah.sh-Kennzeichen oder NAHSH/Linie. */
            $isSh = ($line !== null && strncmp($line, 'de:NAHSH', 8) === 0);
            if (!$isSh) {
                foreach ($stops as $sb) if ($sb[4] !== null && strncmp($sb[4], 'de:010', 6) === 0) { $isSh = true; break; }
            }
            if (!$isSh) continue;
            if ($tag !== null && $tag !== $today && $tag !== $yest) continue;
            $st['sh']++;
            /* Join: route_norm|start -> trip(s) */
            $trips = null;
            if ($line !== null && $start !== null) {
                $key = dp_norm_route($line) . '|' . dp_time_sec($start);
                if (isset($fd[$key])) {
                    $cs = explode(',', $fd[$key]);
                    if (count($cs) === 1) { $trips = $cs[0]; $st['joined']++; }
                    else { $trips = $cs[0]; $st['joined']++; $st['amb'] += count($cs) - 1; }
                }
            }
            foreach ($stops as $sb) {
                $st['blocks']++;
                if ((int)$sb[5] === 1) $st['skip']++;
                if ($trips === null || $sb[1] === null) continue;
                $k = $trips . '|' . $sb[1];
                if (isset($blocks[$k])) {
                    /* Neuere Information (Zeit dem Delay vorziehen) nicht
                     * ueberschreiben: erste Probe gewinnt. */
                    continue;
                }
                $blocks[$k] = [$sb[2], $sb[3], (int)$sb[5]];
            }
        }
    }
    return [$st, $blocks];
}

function dp_overlay_refresh(array &$stats): bool {
    $lock = @fopen(DP_OVL . '.lock', 'c');
    if (!$lock) { if (is_file(DP_OVL)) return true; return false; }
    flock($lock, LOCK_EX);
    $pbT = @filemtime(DP_PB);
    $ovT = @filemtime(DP_OVL_STAMP);
    if (!$pbT || !$ovT || $pbT > $ovT) {
        [$st, $blocks] = dp_overlay_from_pool();
        if (is_array($blocks)) {
            $gz = gzencode(json_encode(['st' => $st, 'b' => $blocks]), 6);
            if (is_string($gz)) {
                dp_atomic_put(DP_OVL, $gz);
                dp_atomic_put(DP_OVL_STAMP, (string)$pbT);
                $stats = $st;
                flock($lock, LOCK_UN); fclose($lock);
                return true;
            }
        }
    }
    flock($lock, LOCK_UN); fclose($lock);
    if (is_file(DP_OVL)) {
        $j = dp_overlay_read();
        if (is_array($j)) $stats = $j['st'] ?? [];
        return is_array($j);
    }
    return false;
}

function dp_overlay_read(): ?array {
    $raw = @file_get_contents(DP_OVL);
    if (!is_string($raw) || $raw === '') return null;
    if (substr($raw, 0, 2) === "\x1f\x8b") $raw = @gzdecode($raw);
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/* ---------- Antwort fuer eine Station ------------------------------------- */

function dp_stops_near(float $lat, float $lon): array {
    $out = [];
    $raw = @file_get_contents(DP_DIR . '/stops.idx');
    if (!is_string($raw)) return $out;
    $deg = 0.006;   // ~600 m Breitengrad-Vorfilter, Laengengrad weiter
    $degLon = 0.012; // (cos-Breite), exakt prueft die Haversine darunter
    foreach (explode("\n", $raw) as $ln) {
        if ($ln === '') continue;
        $p = explode("\t", $ln);
        if (count($p) !== 3) continue;
        $la = (float)$p[1]; $lo = (float)$p[2];
        if (abs($la - $lat) > $deg || abs($lo - $lon) > $degLon) continue;
        $dLa = ($la - $lat) * 111194.9;
        $dLo = ($lo - $lon) * 111194.9 * cos(deg2rad($lat));
        $m = sqrt($dLa * $dLa + $dLo * $dLo);
        if ($m <= DP_RADIUS_M) { $out[$p[0]] = (int)$m; if (count($out) >= DP_MAX_STOP) break; }
    }
    return $out;
}

function dp_out_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
}

function dp_respond(float $lat, float $lon): void {
    dp_out_headers();
    if (!dp_dir_ready()) {
        echo json_encode(['ok' => false, 'v' => DP_V, 'e' => 'readonly-dir'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    /* Realtime im Hintergrund frisch halten (mit Grace und Lock). */
    dp_pool_refresh();

    /* Tagesbau, budgetiert je Anfrage. */
    $budget = microtime(true) + DP_BUDGET_S;
    if (!dp_static_ready()) {
        if (!@is_file(DP_STATE . '.lock')) @file_put_contents(DP_STATE . '.lock', '1');
        $lk = @fopen(DP_STATE . '.lock', 'c');
        if ($lk && flock($lk, LOCK_EX | LOCK_NB)) {
            dp_build_until_ready($budget);
            flock($lk, LOCK_UN);
        }
        if ($lk) fclose($lk);
        if (!dp_static_ready()) {
            echo json_encode(['ok' => true, 'v' => DP_V, 'ts' => time(), 'st' => 'warm', 'at' => []],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
    }

    /* Overlay cachen (TTL: Pool-Rhythmus). */
    $stats = [];
    if (dp_file_age(DP_OVL) > DP_POOL_TTL) dp_overlay_refresh($stats);
    $ov = dp_overlay_read();
    $blocks = is_array($ov) && isset($ov['b']) && is_array($ov['b']) ? $ov['b'] : [];
    if (is_array($ov) && isset($ov['st'])) $stats = $ov['st'];

    $ids = dp_stops_near($lat, $lon);
    $key = sprintf('%.4f|%.4f', $lat, $lon);
    if (!count($ids)) {
        echo json_encode(['ok' => true, 'v' => DP_V, 'ts' => time(), 'age' => dp_file_age(DP_PB),
            'st' => 'ready', 'key' => $key, 'n' => 0, 'at' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    /* Tagesfenster: Plan-Sekunden relativ zu Heute-0-Uhr, GTFS schreibt
     * Fahrten nach Mitternacht als > 86400 s - das passt hierzu. */
    $day0 = strtotime('today 00:00');
    $nowS = time() - $day0;
    $at = [];
    foreach (array_keys($ids) as $sid) {
        $f = DP_BK . '/' . dp_bucket_name($sid);
        if (!is_file($f)) continue;
        $raw = @file_get_contents($f);
        if (!is_string($raw)) continue;
        foreach (explode("\n", $raw) as $ln) {
            if ($ln === '') continue;
            $p = explode("\t", $ln);
            if (count($p) !== 5) continue;
            $dep = (int)$p[0];
            $rel = $dep - $nowS;
            if ($rel < -DP_WIN_BACK * 60 || $rel > DP_WIN_FWD * 60) continue;
            $trip = $p[1]; $seq = (int)$p[2];
            $k = $trip . '|' . $seq;
            $d = 0; $skip = false; $absT = 0;
            if (isset($blocks[$k])) {
                $b = $blocks[$k];
                $d = (int)($b[0] ?? 0);
                $absT = (int)($b[1] ?? 0);
                if ($d === 0 && $absT > 1000000000) {
                    /* Nur Absolutprognose: Verspaetung = Prognose minus
                     * Plan (Plan absolut = Tagesanfang + GTFS-Sekunden,
                     * Mitternacht-Fahrten 86400+ sind automatisch drin). */
                    $d = $absT - ($day0 + $dep);
                }
                if ((int)($b[2] ?? 0) === 1) $skip = true;
            }
            if ($skip) continue;   // Halt faellt aus (DELFI SKIPPED)
            /* Schiene (route_type 2) antwortet mit Planzeit p - genau der
             * gtfsproxy-Kontrakt, den rsOverlayGtfs der App auswertet. */
            $isRail = ((int)$p[4] === 2);
            $at[] = ['l' => $p[3], 'd' => $d,
                'p' => $isRail ? sprintf('%02d:%02d', intdiv($dep, 3600) % 24, intdiv($dep % 3600, 60)) : null,
                's' => $seq, 't' => $trip];
        }
    }
    usort($at, function ($a, $b) {
        return [$a['p'] ?? 'zz', $a['l'], $a['s']] <=> [$b['p'] ?? 'zz', $b['l'], $b['s']];
    });
    if (count($at) > DP_MAX_ROWS) $at = array_slice($at, 0, DP_MAX_ROWS);
    echo json_encode(['ok' => true, 'v' => DP_V, 'ts' => time(), 'age' => dp_file_age(DP_PB),
        'st' => 'ready', 'key' => $key, 'n' => count($at), 'at' => $at],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- Probe ---------------------------------------------------------- */

function dp_probe(bool $warm): void {
    dp_out_headers();
    if (!dp_dir_ready()) {
        echo json_encode(['ok' => false, 'v' => DP_V, 'e' => 'readonly-dir'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    if ($warm) {
        dp_pool_refresh();
        $budget = microtime(true) + 60.0;
        $lk = @fopen(DP_STATE . '.lock', 'c');
        if ($lk && flock($lk, LOCK_EX | LOCK_NB)) {
            dp_build_until_ready($budget);
            flock($lk, LOCK_UN);
        }
        if ($lk) fclose($lk);
        $stats = [];
        dp_overlay_refresh($stats);
    }
    $state = dp_state_load();
    $ov = dp_overlay_read();
    echo json_encode([
        'ok' => true, 'v' => DP_V, 'now' => time(),
        'certs' => dp_cert_info(),
        'static' => [
            'ready' => dp_static_ready(),
            'day' => is_file(DP_DAY) ? trim((string)@file_get_contents(DP_DAY)) : null,
            'zip_age' => is_file(DP_ZIP) ? dp_file_age(DP_ZIP) : null,
            'stops' => is_file(DP_DIR . '/stops.idx') ? substr_count((string)@file_get_contents(DP_DIR . '/stops.idx'), "\n") : 0,
            'buckets' => count(glob(DP_BK . '/*.tsv') ?: []),
            'phase' => $state['phase'] ?? null,
            'stimes_off' => (int)($state['off'] ?? 0),
        ],
        'rt' => [
            'pool_age' => is_file(DP_PB) ? dp_file_age(DP_PB) : null,
            'pool_size' => is_file(DP_PB) ? @filesize(DP_PB) : null,
            'overlay_age' => is_file(DP_OVL) ? dp_file_age(DP_OVL) : null,
            'stats' => is_array($ov) ? ($ov['st'] ?? null) : null,
            'blocks' => is_array($ov) ? count($ov['b'] ?? []) : 0,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- Hauptprogramm --------------------------------------------------- */

try {
    if (isset($_GET['probe'])) { dp_probe(isset($_GET['warm'])); exit; }
    if (isset($_GET['warm'])) { dp_probe(true); exit; }
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
    if ($lat === null || $lon === null || $lat < 45 || $lat > 56 || $lon < 5 || $lon > 16) {
        dp_out_headers();
        echo json_encode(['ok' => false, 'v' => DP_V, 'e' => 'coords'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    dp_respond($lat, $lon);
} catch (Throwable $e) {
    if (!headers_sent()) dp_out_headers();
    echo json_encode(['ok' => false, 'v' => DP_V, 'e' => 'server', 'msg' => substr((string)$e->getMessage(), 0, 200)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
