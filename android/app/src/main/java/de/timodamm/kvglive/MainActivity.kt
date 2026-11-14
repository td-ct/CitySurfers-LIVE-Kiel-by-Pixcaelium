package de.timodamm.kvglive

import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.Menu
import android.view.MenuItem
import android.view.inputmethod.EditorInfo
import android.webkit.GeolocationPermissions
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.Toast
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

class MainActivity : Activity() {

    companion object {
        const val DEFAULT_URL = "file:///android_asset/index.html"
        // App-Funktionen (Brücken-Methoden) DIESER APK-Version. Die Seite trägt
        // denselben Katalog im <!-- CSLK-BRIDGE: ... -->-Marker; beim "Web-Version
        // übernehmen" wird verglichen und vor fehlenden Funktionen gewarnt.
        private val BRIDGE_FEATURES = setOf(
            "vibrate", "vibrateLong", "setAlarm", "cancelAlarm", "ringingAlarms",
            "syncAlarms", "notify", "cancelNotify", "hasNotifPerm", "requestNotifPerm",
            "syncGeofences", "pendingGeofenceEvents", "openMenu",
            "checkWebUpdate", "appVersion")
        // gehostete Seite (eigener Server); dort liegt auch cslk.apk zum Download
        const val DEFAULT_WEB_URL =
            "https://pixcaelium.timo-damm.de/apps/citysurfers/cslk.html"
        private const val REQ_PERMS = 41
    }

    private lateinit var web: WebView
    private var geoCallback: GeolocationPermissions.Callback? = null
    private var geoOrigin: String? = null
    private var pendingTab: String? = null   // Reiter-Sprung aus Wecker-Benachrichtigung
    private var pendingShare: String? = null // geteilter Text (Adresse/Koordinaten/Link)
    private var askedBackgroundLoc = false    // ACCESS_BACKGROUND_LOCATION nur 1x nachfragen

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        web = WebView(this)
        setContentView(web)

        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            setGeolocationEnabled(true)
            mediaPlaybackRequiresUserGesture = false
            cacheMode = WebSettings.LOAD_DEFAULT
        }
        web.webViewClient = object : WebViewClient() {
            // externe Links (z. B. nah.sh) im Browser öffnen, App-Inhalt im WebView behalten.
            // cslk.apk (Download neben der Seite) bewusst ebenfalls im Browser/Downloader,
            // damit der Download-Dialog des Systems erscheint.
            override fun shouldOverrideUrlLoading(view: WebView, req: WebResourceRequest): Boolean {
                val u = req.url
                return if (u.scheme == "http" || u.scheme == "https") {
                    val s = u.toString()
                    if (s.endsWith(".apk")) {
                        try { startActivity(Intent(Intent.ACTION_VIEW, u)); true } catch (e: Exception) { false }
                    } else {
                        try { startActivity(Intent(Intent.ACTION_VIEW, u)); true } catch (e: Exception) { false }
                    }
                } else false
            }
            override fun onPageFinished(view: WebView, url: String) {
                // Sprung aus einer Wecker-Benachrichtigung, falls die Seite
                // beim Klick noch nicht fertig geladen war
                val t = pendingTab
                if (t != null) {
                    pendingTab = null
                    view.evaluateJavascript(
                        "window.kvgGotoTab(${JSONObject.quote(t)})", null)
                }
                // geteilter Text (Senden-Ziel), der vor dem Laden der Seite ankam
                val s = pendingShare
                if (s != null) {
                    pendingShare = null
                    view.evaluateJavascript(
                        "window.kvgImportLocation(${JSONObject.quote(s)})", null)
                }
                // im Hintergrund ausgelöste Geofence-Wecker an die Seite melden
                val evs = org.json.JSONArray()
                for (e in GeofenceStore.drainEvents(applicationContext)) evs.put(e)
                if (evs.length() > 0)
                    view.evaluateJavascript("window.kvgGeofenceEvents(${evs.toString()})", null)
            }
        }
        web.webChromeClient = object : WebChromeClient() {
            override fun onGeolocationPermissionsShowPrompt(
                origin: String, callback: GeolocationPermissions.Callback) {
                if (hasPerm(android.Manifest.permission.ACCESS_FINE_LOCATION) ||
                    hasPerm(android.Manifest.permission.ACCESS_COARSE_LOCATION)) {
                    callback.invoke(origin, true, false)
                } else {
                    geoCallback = callback
                    geoOrigin = origin
                    requestPerms()
                }
            }
        }
        web.addJavascriptInterface(NativeBridge(this), "KvgNative")
        requestPerms()
        // Start: übernommene Web-Version (falls vorhanden), sonst gebündelte Seite
        web.loadUrl(currentTarget())
        takeTabFrom(intent)
        takeShareFrom(intent)
    }

    // ---------- Senden-Ziel: geteilter Text (Adresse/Koordinaten/Locator/Link) ----------

    /** ACTION_SEND-Text ins Adressfeld der Seite übernehmen und prüfen lassen. */
    private fun takeShareFrom(intent: Intent?) {
        if (intent?.action != Intent.ACTION_SEND) return
        val type = intent.type ?: return
        if (!type.startsWith("text/")) return
        val text = intent.getStringExtra(Intent.EXTRA_TEXT)
            ?: intent.getStringExtra(Intent.EXTRA_TITLE)
            ?: return
        if (text.isBlank()) return
        pendingShare = text
        // läuft die Seite schon, sofort übernehmen; sonst übernimmt onPageFinished
        try {
            web.evaluateJavascript(
                "window.kvgImportLocation(${JSONObject.quote(text)})", null)
        } catch (e: Exception) { }
    }

    // ---------- Wecker-Benachrichtigungen: Sprung zum Reiter ----------

    /** Tab-Extra ("tab" = Haltestellen-ShortName) aus einem Benachrichtigungs-Tap. */
    private fun takeTabFrom(intent: Intent?) {
        val t = intent?.getStringExtra("tab") ?: return
        if (t.isEmpty()) return
        pendingTab = t
        // läuft die Seite schon, sofort wechseln; sonst übernimmt onPageFinished
        try {
            web.evaluateJavascript("window.kvgGotoTab(${JSONObject.quote(t)})", null)
        } catch (e: Exception) { }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        takeTabFrom(intent)
        takeShareFrom(intent)
    }

    override fun onDestroy() {
        // App beendet: Wecker-Benachrichtigungen mit verschwinden lassen
        try {
            val nm = getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
            for (id in NativeBridge.alarmNotifs.toList()) nm.cancel(id)
            NativeBridge.alarmNotifs.clear()
            for (id in NativeBridge.notifIds.toList()) nm.cancel(id)
            NativeBridge.notifIds.clear()
        } catch (e: Exception) { }
        super.onDestroy()
    }

    // ---------- Seiten-Stand: gebündelt vs. übernommene Web-Version ----------

    private fun webFile(): File = File(File(filesDir, "web"), "index.html")

    private fun currentTarget(): String {
        val f = webFile()
        return if (f.exists()) Uri.fromFile(f).toString() else DEFAULT_URL
    }

    /** Aktuelle Seite von einer URL laden und dauerhaft in die App übernehmen. */
    private fun adoptWebVersion(url: String) {
        Toast.makeText(this, "Lade aktuelle Web-Version …", Toast.LENGTH_SHORT).show()
        thread {
            var ok = false
            var msg = ""
            var body = ""
            try {
                val conn = URL(url).openConnection() as HttpURLConnection
                conn.connectTimeout = 15000
                conn.readTimeout = 15000
                conn.setRequestProperty("Cache-Control", "no-cache")
                if (conn.responseCode in 200..299) {
                    body = conn.inputStream.bufferedReader().use { it.readText() }
                    if (body.contains("<script>") && body.length > 10000) {
                        val dir = File(filesDir, "web")
                        dir.mkdirs()
                        val tmp = File(dir, "index.html.tmp")
                        tmp.writeText(body)
                        val f = webFile()
                        if (!tmp.renameTo(f)) { tmp.copyTo(f, overwrite = true); tmp.delete() }
                        getSharedPreferences("kvglive", MODE_PRIVATE)
                            .edit().putString("webUrl", url).apply()
                        ok = true
                    } else msg = "Geladene Datei sieht nicht wie die Seite aus."
                } else msg = "HTTP ${conn.responseCode}"
            } catch (e: Exception) { msg = e.message ?: e.toString() }
            // Feature-Handshake: braucht die geladene Seite App-Funktionen, die
            // diese APK noch nicht kennt, wird das beim Übernehmen gemeldet.
            var missing = emptyList<String>()
            if (ok) {
                try {
                    // Marker <!-- CSLK-BRIDGE: … --> aus der geladenen Seite lesen
                    val raw = Regex("<!--\\s*CSLK-BRIDGE:\\s*([^>]*?)\\s*-->")
                        .find(body)?.groupValues?.get(1) ?: ""
                    val needed = raw.split(',').map { it.trim() }.filter { it.isNotEmpty() }
                    missing = needed.filter { it !in BRIDGE_FEATURES }
                } catch (e: Exception) { }
            }
            runOnUiThread {
                if (ok) {
                    Toast.makeText(this, "Web-Version übernommen.", Toast.LENGTH_SHORT).show()
                    if (missing.isNotEmpty())
                        Toast.makeText(this,
                            "⚠️ App-Update empfohlen: Die Seite nutzt App-Funktionen, die diese APK noch nicht kennt (" +
                                missing.joinToString(", ") + "). Bitte cslk.apk aktualisieren.",
                            Toast.LENGTH_LONG).show()
                    web.loadUrl(currentTarget())
                } else {
                    Toast.makeText(this, "Übernahme fehlgeschlagen: $msg", Toast.LENGTH_LONG).show()
                }
            }
        }
    }

    /** Update-Check vom Über-Dialog der Seite: lädt die Seite von der URL und
     *  übernimmt sie dauerhaft, wenn ihre Versionsnummer von der laufenden
     *  abweicht; ist sie gleich, gibt es nur einen Toast. Fehlende App-
     *  Funktionen werden wie beim "Web-Version übernehmen" gemeldet. */
    fun checkWebUpdate(url: String, curVer: String) {
        Toast.makeText(this, "Suche neue Web-Version …", Toast.LENGTH_SHORT).show()
        thread {
            var ok = false
            var msg = ""
            var body = ""
            var missing = emptyList<String>()
            try {
                val conn = URL(url).openConnection() as HttpURLConnection
                conn.connectTimeout = 15000
                conn.readTimeout = 15000
                conn.setRequestProperty("Cache-Control", "no-cache")
                if (conn.responseCode in 200..299) {
                    body = conn.inputStream.bufferedReader().use { it.readText() }
                    val newVer = Regex("Version (\\d+\\.\\d+\\.\\d+)").find(body)?.groupValues?.get(1) ?: "?"
                    if (body.contains("<script>") && body.length > 10000) {
                        if (newVer == curVer) {
                            msg = "Die Seite ist bereits aktuell (Version $newVer)."
                        } else {
                            val dir = File(filesDir, "web")
                            dir.mkdirs()
                            val tmp = File(dir, "index.html.tmp")
                            tmp.writeText(body)
                            val f = webFile()
                            if (!tmp.renameTo(f)) { tmp.copyTo(f, overwrite = true); tmp.delete() }
                            getSharedPreferences("kvglive", MODE_PRIVATE)
                                .edit().putString("webUrl", url).apply()
                            ok = true
                            msg = "Neue Web-Version $newVer übernommen."
                        }
                    } else msg = "Geladene Datei sieht nicht wie die Seite aus."
                } else msg = "HTTP ${conn.responseCode}"
                if (ok) {
                    try {
                        val raw = Regex("<!--\\s*CSLK-BRIDGE:\\s*([^>]*?)\\s*-->")
                            .find(body)?.groupValues?.get(1) ?: ""
                        val needed = raw.split(',').map { it.trim() }.filter { it.isNotEmpty() }
                        missing = needed.filter { it !in BRIDGE_FEATURES }
                    } catch (e: Exception) { }
                }
            } catch (e: Exception) { msg = e.message ?: e.toString() }
            val okF = ok
            val msgF = msg
            val missF = missing
            runOnUiThread {
                if (okF) {
                    Toast.makeText(this, msgF + " – lade neu …", Toast.LENGTH_SHORT).show()
                    if (missF.isNotEmpty())
                        Toast.makeText(this,
                            "⚠️ App-Update empfohlen: Die Seite nutzt App-Funktionen, die diese APK noch nicht kennt (" +
                                missF.joinToString(", ") + "). Bitte cslk.apk aktualisieren.",
                            Toast.LENGTH_LONG).show()
                    web.loadUrl(currentTarget())
                } else {
                    Toast.makeText(this, msgF, Toast.LENGTH_LONG).show()
                }
            }
        }
    }

    private fun hasPerm(p: String) =
        checkSelfPermission(p) == PackageManager.PERMISSION_GRANTED

    private fun requestPerms() {
        val want = mutableListOf(
            android.Manifest.permission.ACCESS_FINE_LOCATION,
            android.Manifest.permission.ACCESS_COARSE_LOCATION)
        if (Build.VERSION.SDK_INT >= 33) want.add(android.Manifest.permission.POST_NOTIFICATIONS)
        val missing = want.filter { !hasPerm(it) }
        if (missing.isNotEmpty()) requestPermissions(missing.toTypedArray(), REQ_PERMS)
    }

    override fun onRequestPermissionsResult(
        requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        val ok = grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED
        // Standort im Vordergrund erteilt: einmalig nachträglich nach
        // ACCESS_BACKGROUND_LOCATION fragen (noetig fuer Geofence-Wecker im
        // Hintergrund; ab Android 11 nur separat und per "Bei allen Zeiten"
        // im Dialog bzw. in den App-Einstellungen waehlbar).
        if (ok && Build.VERSION.SDK_INT >= 29 && !askedBackgroundLoc &&
            permissions.isNotEmpty() && permissions[0].contains("LOCATION")) {
            askedBackgroundLoc = true
            requestPermissions(arrayOf(android.Manifest.permission.ACCESS_BACKGROUND_LOCATION),
                REQ_PERMS + 1)
        }
        val cb = geoCallback
        val origin = geoOrigin
        if (cb != null && origin != null) {
            geoCallback = null; geoOrigin = null
            cb.invoke(origin, ok, false)
        }
        if (!ok && permissions.isNotEmpty() &&
            permissions[0].contains("LOCATION")) {
            if (!permissions[0].equals(android.Manifest.permission.ACCESS_BACKGROUND_LOCATION))
                Toast.makeText(this,
                    "Ohne Standortfreigabe funktionieren GPS-Funktionen und Distanz-Wecker nicht.",
                    Toast.LENGTH_LONG).show()
            else
                Toast.makeText(this,
                    "Ohne „Bei allen Zeiten“ klingeln Geofence-Wecker nur bei offener App.",
                    Toast.LENGTH_LONG).show()
        }
    }

    override fun onCreateOptionsMenu(menu: Menu): Boolean {
        menu.add(Menu.NONE, 1, Menu.NONE, "Zustand importieren …")
        menu.add(Menu.NONE, 2, Menu.NONE, "Web-Version übernehmen …")
        menu.add(Menu.NONE, 3, Menu.NONE, "Gebündelte Version laden")
        menu.add(Menu.NONE, 4, Menu.NONE, "Seiten-URL ändern")
        return true
    }

    private fun pasteDialog(title: String, hint: String, action: (String) -> Unit) {
        val input = EditText(this)
        input.hint = hint
        input.setSingleLine(false)
        input.imeOptions = EditorInfo.IME_FLAG_NO_ENTER_ACTION
        input.minLines = 3
        AlertDialog.Builder(this)
            .setTitle(title)
            .setView(input)
            .setPositiveButton("OK") { _, _ -> action(input.text.toString()) }
            .setNegativeButton("Abbrechen", null).show()
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        when (item.itemId) {
            // Zustand (Favoriten, Adresse, Einstellungen) aus der Browser-Sitzung
            // übernehmen: Code aus "⇅ Zustand" auf der Seite einfügen
            1 -> pasteDialog(
                "Zustand importieren",
                "Zustand-Code einfügen (auf der Seite unter ⇅ Zustand exportieren)",
            ) { code ->
                if (code.isNotBlank()) {
                    // JSONObject.quote macht den String JS-String-sicher
                    web.evaluateJavascript(
                        "window.kvgImportState(${JSONObject.quote(code.trim())});") { res ->
                        if (res.contains("ok")) {
                            Toast.makeText(this, "Zustand übernommen.", Toast.LENGTH_SHORT).show()
                        } else {
                            Toast.makeText(this, "Code ungültig.", Toast.LENGTH_LONG).show()
                        }
                    }
                }
            }
            // aktuellen Seiten-Stand direkt in die App übertragen
            2 -> pasteDialog(
                "Web-Version übernehmen",
                "https://…/cslk.html",
            ) { u ->
                if (u.isNotBlank()) {
                    getSharedPreferences("kvglive", MODE_PRIVATE)
                        .edit().putString("url", "").apply()   // Override-URL zurücksetzen
                    adoptWebVersion(if (u.startsWith("http")) u.trim() else "https://" + u.trim())
                }
            }
            3 -> {
                webFile().delete()
                getSharedPreferences("kvglive", MODE_PRIVATE)
                    .edit().putString("url", "").putString("webUrl", "").apply()
                web.loadUrl(DEFAULT_URL)
            }
            4 -> {
                val input = EditText(this)
                input.hint = "https://… oder file:///android_asset/index.html"
                input.setText(getSharedPreferences("kvglive", MODE_PRIVATE)
                    .getString("url", ""))
                AlertDialog.Builder(this)
                    .setTitle("Seiten-URL")
                    .setView(input)
                    .setPositiveButton("Laden") { _, _ ->
                        var u = input.text.toString().trim()
                        if (u.isNotEmpty()) {
                            if (!u.startsWith("http") && !u.startsWith("file://")) u = "https://$u"
                            getSharedPreferences("kvglive", MODE_PRIVATE)
                                .edit().putString("url", u).apply()
                            web.loadUrl(u)
                        }
                    }
                    .setNegativeButton("Abbrechen", null).show()
            }
        }
        return true
    }

    // Zurück-Taste: WebView-History, sonst App verlassen
    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (web.canGoBack()) web.goBack() else super.onBackPressed()
    }
}

/**
 * JS-Brücke: window.KvgNative in der Seite.
 * vibrate(patternJson), setAlarm(key, epochMs, name, cfgJson), cancelAlarm(key),
 * ringingAlarms() -> JSON-Array der gerade nativ läutenden Wecker-Keys,
 * syncAlarms(listJson) -> eine Benachrichtigung je gestelltem Wecker in der
 *   Android-Benachrichtigungsleiste (Kanal "weckerliste", still, ohne Ton);
 *   Tap oeffnet die App und springt direkt in den Reiter des Weckers.
 *   listJson: [{key, title, text, tab}]
 * cfgJson: {"lead":int, "vib":bool, "tone":bool, "snd":"alarm|ring|notif", "vol":0..1}
 */
class NativeBridge(private val act: Activity) {

    private fun getVibrator(): android.os.Vibrator? {
        return try {
            val v = if (Build.VERSION.SDK_INT >= 31)
                (act.getSystemService(android.os.VibratorManager::class.java))?.defaultVibrator
            else @Suppress("DEPRECATION")
                act.getSystemService(VIBRATOR_SERVICE) as? android.os.Vibrator
            if (v == null || !v.hasVibrator()) null else v
        } catch (e: Exception) { null }
    }

    @JavascriptInterface
    fun vibrate(patternJson: String) {
        try {
            val arr = JSONArray(patternJson)
            // Web-Konvention (navigator.vibrate): Muster beginnt mit VIBRIER-Dauer.
            // Android createWaveform erwartet aber PAUSE zuerst — [3000] waere dort
            // 3000 ms Stillstand! Deshalb fuehrende 0 ergaenzen, wenn keine da ist.
            val raw = LongArray(arr.length()) { arr.optLong(it, 0L) }
            val pat = if (raw.isEmpty() || raw[0] != 0L) longArrayOf(0L) + raw else raw
            val v = getVibrator() ?: return
            if (pat.size <= 1) v.cancel()
            else if (Build.VERSION.SDK_INT >= 26)
                v.vibrate(android.os.VibrationEffect.createWaveform(pat, -1))
            else @Suppress("DEPRECATION") v.vibrate(pat, -1)
        } catch (e: Exception) { }
    }

    /**
     * Failsafe-Variante: einfacher Ein-Schlag mit Standard-Amplitude (createOneShot
     * bzw. vibrate(ms)). Die Seite ruft beide Varianten; auf Geraeten, auf denen
     * das Wellenmuster (z.B. nach WebView-Update) nicht greift, vibriert zumindest
     * dieser eine lange Schlag zuverlaessig.
     */
    @JavascriptInterface
    fun vibrateLong(msJson: String) {
        try {
            val ms = msJson.toLongOrNull() ?: return
            if (ms <= 0L) return
            val v = getVibrator() ?: return
            if (Build.VERSION.SDK_INT >= 26)
                v.vibrate(android.os.VibrationEffect.createOneShot(ms, android.os.VibrationEffect.DEFAULT_AMPLITUDE))
            else @Suppress("DEPRECATION") v.vibrate(ms)
        } catch (e: Exception) { }
    }

    @JavascriptInterface
    fun setAlarm(key: String, epochMs: String, name: String, cfgJson: String) {
        val am = act.getSystemService(ALARM_SERVICE) as android.app.AlarmManager
        val ctx = act.applicationContext
        val cfg = try { JSONObject(cfgJson) } catch (e: Exception) { JSONObject() }
        val intent = Intent(ctx, AlarmReceiver::class.java).apply {
            putExtra("key", key)
            putExtra("name", name)
            putExtra("vib", cfg.optBoolean("vib", true))
            putExtra("tone", cfg.optBoolean("tone", true))
            putExtra("snd", cfg.optString("snd", "alarm"))
            putExtra("vol", cfg.optDouble("vol", 1.0))
        }
        AlarmReceiver.Ringing.remove(key)   // neu gestellt: alte Läut-Kette weg
        // requestCode = Key-Hash: cancelAlarm funktioniert auch nach App-Neustart
        val pi = android.app.PendingIntent.getBroadcast(
            ctx, key.hashCode(), intent,
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or
                android.app.PendingIntent.FLAG_IMMUTABLE)
        val show = android.app.PendingIntent.getActivity(
            ctx, 0, Intent(ctx, MainActivity::class.java),
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or
                android.app.PendingIntent.FLAG_IMMUTABLE)
        val atMs = epochMs.toLongOrNull() ?: return
        // setAlarmClock: klingelt auch im Doze-Modus, ohne exakte-Alarm-Freigabe
        am.setAlarmClock(android.app.AlarmManager.AlarmClockInfo(atMs, show), pi)
    }

    @JavascriptInterface
    fun syncAlarms(listJson: String) {
        try {
            val act = this.act
            val nm = act.getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
            if (Build.VERSION.SDK_INT >= 26) {
                val ch = android.app.NotificationChannel(
                    "weckerliste", "Gestellte Wecker", android.app.NotificationManager.IMPORTANCE_DEFAULT)
                ch.description = "Uebersicht der gestellten Wecker"
                ch.setSound(null, null)
                ch.enableVibration(false)
                nm.createNotificationChannel(ch)
            }
            val arr = JSONArray(listJson)
            val seen = HashSet<Int>()
            for (i in 0 until arr.length()) {
                val a = arr.optJSONObject(i) ?: continue
                val key = a.optString("key")
                if (key.isEmpty()) continue
                val id = key.hashCode()
                seen.add(id)
                val tabSn = a.optString("tab")
                val open = android.app.PendingIntent.getActivity(
                    act, id,
                    Intent(act, MainActivity::class.java).putExtra("tab", tabSn),
                    android.app.PendingIntent.FLAG_UPDATE_CURRENT or
                    android.app.PendingIntent.FLAG_IMMUTABLE)
                val b = if (Build.VERSION.SDK_INT >= 26)
                    android.app.Notification.Builder(act, "weckerliste")
                else @Suppress("DEPRECATION") android.app.Notification.Builder(act)
                b.apply {
                    setSmallIcon(android.R.drawable.ic_lock_idle_alarm)
                    setContentTitle(a.optString("title"))
                    setContentText(a.optString("text"))
                    setStyle(android.app.Notification.BigTextStyle().bigText(a.optString("text")))
                    setContentIntent(open)
                    setAutoCancel(false)         // nur per Wecker-Loeschen/App-Ende weg
                    setOngoing(true)
                    setOnlyAlertOnce(true)      // Countdown-Updates bleiben still
                    if (Build.VERSION.SDK_INT < 26) {
                        @Suppress("DEPRECATION")
                        setPriority(android.app.Notification.PRIORITY_DEFAULT)
                    }
                }
                try { nm.notify(id, b.build()); alarmNotifs.add(id) } catch (e: Exception) { }
            }
            // geloeschte Wecker: Benachrichtigung entfernen
            for (id in alarmNotifs.toList()) if (id !in seen) {
                try { nm.cancel(id) } catch (e: Exception) { }
                alarmNotifs.remove(id)
            }
        } catch (e: Exception) { }
    }

    @JavascriptInterface
    fun ringingAlarms(): String {
        val arr = org.json.JSONArray()
        for (k in AlarmReceiver.Ringing) arr.put(k)
        return arr.toString()
    }

    // ---------- Freie Benachrichtigungen (Finde-Nach-Hause, Fahrtverlauf) ----------
    // Die Seite ruft notify(id, title, text, channel) fuer Hinweise, die auch in
    // der Android-Nachrichtenliste erscheinen sollen. Kanaele:
    //   "hinweise"      – Finde-Nach-Hause-Fahrten
    //   "fahrtverlauf"  – Fortschritt im aktiven Fahrtverlauf (an Station angekommen)
    @JavascriptInterface
    fun notify(id: String, title: String, text: String, channel: String) {
        try {
            val nm = act.getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
            val chId = if (channel == "fahrtverlauf") "fahrtverlauf" else "hinweise"
            if (Build.VERSION.SDK_INT >= 26) {
                val ch = android.app.NotificationChannel(chId,
                    if (chId == "fahrtverlauf") "Fahrtverlauf" else "Finde-Nach-Hause-Hinweise",
                    android.app.NotificationManager.IMPORTANCE_DEFAULT)
                if (chId == "hinweise") ch.description = "Moegliche Fahrten zu Favoriten-Zielen"
                nm.createNotificationChannel(ch)
            }
            val nid = id.hashCode()
            val open = android.app.PendingIntent.getActivity(
                act, nid, Intent(act, MainActivity::class.java),
                android.app.PendingIntent.FLAG_UPDATE_CURRENT or
                    android.app.PendingIntent.FLAG_IMMUTABLE)
            val b = if (Build.VERSION.SDK_INT >= 26)
                android.app.Notification.Builder(act, chId)
            else @Suppress("DEPRECATION") android.app.Notification.Builder(act)
            b.apply {
                setSmallIcon(android.R.drawable.ic_dialog_map)
                setContentTitle(title)
                setContentText(text)
                setStyle(android.app.Notification.BigTextStyle().bigText(text))
                setContentIntent(open)
                setAutoCancel(true)       // mit Antippen weg, oeffnet die App
                if (Build.VERSION.SDK_INT < 26) {
                    @Suppress("DEPRECATION")
                    setPriority(android.app.Notification.PRIORITY_DEFAULT)
                }
            }
            try { nm.notify(nid, b.build()); notifIds.add(nid) } catch (e: Exception) { }
        } catch (e: Exception) { }
    }

    @JavascriptInterface
    fun cancelNotify(id: String) {
        try {
            val nm = act.getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
            val nid = id.hashCode()
            nm.cancel(nid)
            notifIds.remove(nid)
        } catch (e: Exception) { }
    }

    /** Hat die App die Benachrichtigungs-Freigabe (Android 13+)? */
    @JavascriptInterface
    fun hasNotifPerm(): Boolean =
        Build.VERSION.SDK_INT < 33 ||
            act.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED

    /** Benachrichtigungs-Freigabe nachtraeglich anfragen (falls abgelehnt/verpasst). */
    @JavascriptInterface
    fun requestNotifPerm() {
        if (Build.VERSION.SDK_INT >= 33 &&
            act.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) !=
                PackageManager.PERMISSION_GRANTED) {
            act.runOnUiThread {
                act.requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 41)
            }
        }
    }

    // ---------- Geofence-Wecker (Distanz-Wecker im Hintergrund) ----------
    // Die Seite konfiguriert (listJson), das System überwacht die Zäune:
    // [{key, lat, lon, radius, name, vib, tone, snd, vol}] je Distanz-Wecker.
    @JavascriptInterface
    fun syncGeofences(listJson: String) {
        val ctx = act.applicationContext
        val client = com.google.android.gms.location.LocationServices.getGeofencingClient(ctx)
        val pi = geofencePi(ctx)
        try { client.removeGeofences(pi) } catch (e: Exception) { }
        GeofenceStore.clearFences(ctx)
        val arr = org.json.JSONArray(listJson)
        val fences = ArrayList<com.google.android.gms.location.Geofence>()
        for (i in 0 until arr.length()) {
            val f = arr.optJSONObject(i) ?: continue
            val key = f.optString("key")
            if (key.isEmpty()) continue
            val lat = f.optDouble("lat", Double.NaN)
            val lon = f.optDouble("lon", Double.NaN)
            if (!lat.isFinite() || !lon.isFinite()) continue
            val id = key.hashCode()
            fences.add(com.google.android.gms.location.Geofence.Builder()
                .setRequestId(id.toString())
                .setCircularRegion(lat, lon,
                    Math.max(100.0, f.optDouble("radius", 500.0)).toFloat())
                .setExpirationDuration(com.google.android.gms.location.Geofence.NEVER_EXPIRE)
                .setTransitionTypes(com.google.android.gms.location.Geofence.GEOFENCE_TRANSITION_ENTER)
                .setNotificationResponsiveness(0)
                .build())
            GeofenceStore.put(ctx, id, f)
        }
        if (fences.isEmpty()) return
        try {
            // INITIAL_TRIGGER_ENTER: steht man beim Stellen schon im Radius, feuert
            // der Zaun sofort – dieselbe Semantik wie die Distanz-Prüfung der Seite.
            client.addGeofences(com.google.android.gms.location.GeofencingRequest.Builder()
                .setInitialTrigger(com.google.android.gms.location.GeofencingRequest.INITIAL_TRIGGER_ENTER)
                .addGeofences(fences)
                .build(), pi)
        } catch (e: Exception) { }
    }

    /** Ausgelöste Geofence-Ereignisse ([{key, name, ts}]) für die Seite, danach geleert. */
    @JavascriptInterface
    fun pendingGeofenceEvents(): String {
        val arr = org.json.JSONArray()
        for (e in GeofenceStore.drainEvents(act.applicationContext)) arr.put(e)
        return arr.toString()
    }

    private fun geofencePi(ctx: android.content.Context): android.app.PendingIntent =
        android.app.PendingIntent.getBroadcast(
            ctx, 0, android.content.Intent(ctx, GeofenceReceiver::class.java),
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or
                android.app.PendingIntent.FLAG_IMMUTABLE)

    /**
     * Öffnet das App-Optionsmenü (Zustand importieren, Web-Version übernehmen,
     * gebündelte Version laden, Seiten-URL ändern). Der ⋮-Knopf der Seite ruft
     * diese Brücke — das Hardware-/Overflow-Menü ist auf modernen Geräten ohne
     * Menü-Taste und ohne Actionbar sonst nicht zu erreichen.
     */
    @JavascriptInterface
    fun openMenu() {
        act.runOnUiThread {
            try { act.openOptionsMenu() } catch (e: Exception) { }
        }
    }

    /** Update-Knopf im Über-Dialog der Seite: löst den Update-Check aus
     *  (Seite laden, Versionsnummern vergleichen, bei Neuheit übernehmen). */
    @JavascriptInterface
    fun checkWebUpdate(url: String, curVer: String) {
        (act as? MainActivity)?.checkWebUpdate(url, curVer)
    }

    /** Versionsname + versionCode der installierten App, z. B. "1.1.4 (28)". */
    @JavascriptInterface
    fun appVersion(): String {
        return try {
            val pi = act.packageManager.getPackageInfo(act.packageName, 0)
            val code = if (android.os.Build.VERSION.SDK_INT >= 28) pi.longVersionCode
                       else @Suppress("DEPRECATION") pi.versionCode.toLong()
            (pi.versionName ?: "?") + " (" + code + ")"
        } catch (e: Exception) { "?" }
    }

    @JavascriptInterface
    fun cancelAlarm(key: String) {
        AlarmReceiver.Ringing.remove(key)   // Läut-Kette stoppen (falls aktiv)
        val am = act.getSystemService(ALARM_SERVICE) as android.app.AlarmManager
        val intent = Intent(act.applicationContext, AlarmReceiver::class.java)
        val pi = android.app.PendingIntent.getBroadcast(
            act, key.hashCode(), intent,
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or
                android.app.PendingIntent.FLAG_IMMUTABLE)
        am.cancel(pi)
    }

    companion object {
        private const val VIBRATOR_SERVICE = android.content.Context.VIBRATOR_SERVICE
        private const val ALARM_SERVICE = android.content.Context.ALARM_SERVICE
        private const val NOTIFICATION_SERVICE = android.content.Context.NOTIFICATION_SERVICE
        /** IDs der Wecker-Benachrichtigungen (zum Aufraeumen bei App-Ende). */
        val alarmNotifs: MutableSet<Int> =
            java.util.Collections.synchronizedSet(HashSet<Int>())
        /** IDs der freien Benachrichtigungen (Finde-Nach-Hause, Fahrtverlauf). */
        val notifIds: MutableSet<Int> =
            java.util.Collections.synchronizedSet(HashSet<Int>())
    }
}
