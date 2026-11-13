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
        // gehostete Seite (eigener Server); dort liegt auch cslk.apk zum Download
        const val DEFAULT_WEB_URL =
            "https://pixcaelium.timo-damm.de/apps/citysurfers/cslk.html"
        private const val REQ_PERMS = 41
    }

    private lateinit var web: WebView
    private var geoCallback: GeolocationPermissions.Callback? = null
    private var geoOrigin: String? = null
    private var pendingTab: String? = null   // Reiter-Sprung aus Wecker-Benachrichtigung

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
    }

    override fun onDestroy() {
        // App beendet: Wecker-Benachrichtigungen mit verschwinden lassen
        try {
            val nm = getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
            for (id in NativeBridge.alarmNotifs.toList()) nm.cancel(id)
            NativeBridge.alarmNotifs.clear()
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
            try {
                val conn = URL(url).openConnection() as HttpURLConnection
                conn.connectTimeout = 15000
                conn.readTimeout = 15000
                conn.setRequestProperty("Cache-Control", "no-cache")
                if (conn.responseCode in 200..299) {
                    val body = conn.inputStream.bufferedReader().use { it.readText() }
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
            runOnUiThread {
                if (ok) {
                    Toast.makeText(this, "Web-Version übernommen.", Toast.LENGTH_SHORT).show()
                    web.loadUrl(currentTarget())
                } else {
                    Toast.makeText(this, "Übernahme fehlgeschlagen: $msg", Toast.LENGTH_LONG).show()
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
        val cb = geoCallback
        val origin = geoOrigin
        if (cb != null && origin != null) {
            geoCallback = null; geoOrigin = null
            cb.invoke(origin, ok, false)
        }
        if (!ok && permissions.isNotEmpty() &&
            permissions[0].contains("LOCATION")) {
            Toast.makeText(this,
                "Ohne Standortfreigabe funktionieren GPS-Funktionen und Distanz-Wecker nicht.",
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

    @JavascriptInterface
    fun vibrate(patternJson: String) {
        try {
            val arr = JSONArray(patternJson)
            val pat = LongArray(arr.length()) { arr.optLong(it, 0L) }
            val v = act.getSystemService(VIBRATOR_SERVICE) as? android.os.Vibrator ?: return
            if (pat.isEmpty()) v.cancel()
            else if (Build.VERSION.SDK_INT >= 26)
                v.vibrate(android.os.VibrationEffect.createWaveform(pat, -1))
            else @Suppress("DEPRECATION") v.vibrate(pat, -1)
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
                    "weckerliste", "Gestellte Wecker", NotificationManager.IMPORTANCE_DEFAULT)
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
                    setContentIntent(open)
                    setAutoCancel(false)
                    setOngoing(true)            // nur per Wecker-Loeschen/App-Ende weg
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
    }
}
