package de.timodamm.kvglive

import android.Manifest
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

class MainActivity : Activity() {

    companion object {
        const val DEFAULT_URL = "file:///android_asset/index.html"
        private const val REQ_PERMS = 41
    }

    private lateinit var web: WebView
    private var geoCallback: GeolocationPermissions.Callback? = null
    private var geoOrigin: String? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        web = WebView(this)
        setContentView(web)

        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            geolocationEnabled = true
            mediaPlaybackRequiresUserGesture = false
            cacheMode = WebSettings.LOAD_DEFAULT
        }
        web.webViewClient = object : WebViewClient() {
            // externe Links (z. B. nah.sh) im Browser öffnen, App-Inhalt im WebView behalten
            override fun shouldOverrideUrlLoading(view: WebView, req: WebResourceRequest): Boolean {
                val u = req.url
                return if (u.scheme == "http" || u.scheme == "https") {
                    try { startActivity(Intent(Intent.ACTION_VIEW, u)); true } catch (e: Exception) { false }
                } else false
            }
        }
        web.webChromeClient = object : WebChromeClient() {
            override fun onGeolocationPermissionsShowPrompt(
                origin: String, callback: GeolocationPermissions.Callback) {
                if (hasPerm(Manifest.permission.ACCESS_FINE_LOCATION) ||
                    hasPerm(Manifest.permission.ACCESS_COARSE_LOCATION)) {
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
        web.loadUrl(prefsUrl())
    }

    private fun prefsUrl(): String =
        getSharedPreferences("kvglive", MODE_PRIVATE).getString("url", DEFAULT_URL) ?: DEFAULT_URL

    private fun hasPerm(p: String) =
        checkSelfPermission(p) == PackageManager.PERMISSION_GRANTED

    private fun requestPerms() {
        val want = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION)
        if (Build.VERSION.SDK_INT >= 33) want.add(Manifest.permission.POST_NOTIFICATIONS)
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
        menu.add(Menu.NONE, 1, Menu.NONE, "Seiten-URL ändern")
        menu.add(Menu.NONE, 2, Menu.NONE, "Gebündelte Version laden")
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        when (item.itemId) {
            1 -> {
                val input = EditText(this)
                input.hint = "https://… oder file:///android_asset/index.html"
                input.setText(prefsUrl())
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
            2 -> {
                getSharedPreferences("kvglive", MODE_PRIVATE)
                    .edit().putString("url", DEFAULT_URL).apply()
                web.loadUrl(DEFAULT_URL)
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
 * vibrate(patternJson), setAlarm(key, epochMs, name, leadMin), cancelAlarm(key)
 */
class NativeBridge(private val act: Activity) {

    @JavascriptInterface
    fun vibrate(patternJson: String) {
        try {
            val arr = JSONArray(patternJson)
            val pat = LongArray(arr.length()) { arr.optLong(it, 0L) }
            val v = act.getSystemService(VIBRATOR_SERVICE) as? android.os.Vibrator ?: return
            if (pat.isEmpty()) v.cancel() else v.vibrate(pat, -1)
        } catch (e: Exception) { }
    }

    @JavascriptInterface
    fun setAlarm(key: String, epochMs: String, name: String, leadMin: String) {
        val am = act.getSystemService(ALARM_SERVICE) as android.app.AlarmManager
        val ctx = act.applicationContext
        val intent = Intent(ctx, AlarmReceiver::class.java).apply {
            putExtra("key", key)
            putExtra("name", name)
        }
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
    fun cancelAlarm(key: String) {
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
    }
}
