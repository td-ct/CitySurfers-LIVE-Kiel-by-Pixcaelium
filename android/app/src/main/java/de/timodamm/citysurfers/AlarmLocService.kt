package de.timodamm.citysurfers

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.location.Location
import android.os.Build
import android.os.IBinder
import android.os.Looper

/**
 * v1.6.57 (Timo-Wunsch 27.11.2026): Alarm-Ortungsdienst.
 *
 * Solange Distanz-Wecker (Eulen-Alarme) gestellt sind, läuft dieser
 * Foreground-Service (Typ location) mit AKTIVEM GPS - auch bei
 * ausgeschaltetem Display und ohne die separate "Bei allen Zeiten"-
 * Freigabe des Standorts (die System-Geofencing-API braucht sie, dieser
 * Dienst nicht; er braucht nur die normale Standort-Freigabe).
 *
 * Zwei Aufgaben je Position:
 * 1. Selbst prüfen: Liegt der Standort im Radius eines Weckers (aus dem
 *    GeofenceStore, dieselben Zaune wie die Geofencing-API), startet er
 *    dieselbe Läut-Kette wie GeofenceReceiver (Broadcast an AlarmReceiver)
 *    und meldet das Ereignis der Seite (GeofenceStore.recordEvent). Das
 *    einmal-feuern-Flag (consumeFired) teilt er sich mit der API - kein
 *    Doppel-Läuten.
 * 2. Position in die Seite schieben (window.kvgNativePos), solange die
 *    MainActivity mit ihrer WebView lebt: Alle GPS-Algorithmen der Seite
 *    (Distanz-Wecker, Tauben-Suche) rechnen im Hintergrund mit frischen
 *    Daten.
 *
 * Start/Stop macht NativeBridge.syncGeofences passend zur Wecker-Liste.
 */
class AlarmLocService : Service() {

    private var client: com.google.android.gms.location.FusedLocationProviderClient? = null
    private var callback: com.google.android.gms.location.LocationCallback? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopLoc()
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
            return START_NOT_STICKY
        }
        startFg()
        startLoc()
        return START_STICKY
    }

    /** Stille Dauerbenachrichtigung (Pflicht fuer Foreground-Services): Der
     *  Nutzer sieht, dass die App wegen aktiver Alarme mit GPS ueberwacht. */
    private fun startFg() {
        val nm = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= 26) {
            val ch = NotificationChannel(CHANNEL, "Alarm-Ortungsdienst",
                NotificationManager.IMPORTANCE_MIN)
            ch.description = "Läuft, solange Distanz-Wecker gestellt sind."
            nm.createNotificationChannel(ch)
        }
        val b: Notification.Builder = if (Build.VERSION.SDK_INT >= 26)
            Notification.Builder(this, CHANNEL) else Notification.Builder(this)
        b.setContentTitle("🦉 Eulen-Alarm aktiv")
            .setContentText("GPS-Überwachung der Abstands-Wecker läuft (auch bei ausgeschaltetem Display).")
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setOngoing(true)
            .setPriority(Notification.PRIORITY_MIN)
        startForeground(NOTIF_ID, b.build())
    }

    private fun startLoc() {
        if (client != null) return   // laeuft bereits
        try {
            client = com.google.android.gms.location.LocationServices
                .getFusedLocationProviderClient(this)
        } catch (e: Exception) {
            stopSelf()
            return
        }
        val req = com.google.android.gms.location.LocationRequest.Builder(
            com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY,
            8000L)   // alle 8 s eine Position (Annäherung rechtzeitig erkennen)
            .setMinUpdateDistanceMeters(10f)
            .build()
        val cb = object : com.google.android.gms.location.LocationCallback() {
            override fun onLocationResult(res: com.google.android.gms.location.LocationResult) {
                for (l in res.locations) onPos(l)
            }
        }
        callback = cb
        try {
            client?.requestLocationUpdates(req, cb, Looper.getMainLooper())
        } catch (e: SecurityException) {
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
        } catch (e: Exception) {
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
        }
    }

    private fun stopLoc() {
        try { client?.removeLocationUpdates(callback) } catch (e: Exception) { }
        client = null
        callback = null
    }

    override fun onDestroy() {
        stopLoc()
        super.onDestroy()
    }

    private fun onPos(l: Location) {
        // 2) Position in die laufende Seite schieben (falls die App offen ist)
        MainActivity.instance?.let { act ->
            val acc = if (l.hasAccuracy()) l.accuracy.toDouble().toString() else "null"
            act.pushJs("window.kvgNativePos(" + l.latitude.toString() + "," +
                l.longitude.toString() + "," + acc + ")")
        }
        // 1) Annäherung selbst prüfen (Radius-Check gegen alle registrierten Zaune)
        for ((id, f) in GeofenceStore.all(applicationContext)) {
            val lat = f.optDouble("lat", Double.NaN)
            val lon = f.optDouble("lon", Double.NaN)
            val radius = Math.max(100.0, f.optDouble("radius", 500.0))
            if (!lat.isFinite() || !lon.isFinite()) continue
            if (distM(l.latitude, l.longitude, lat, lon) <= radius &&
                GeofenceStore.consumeFired(applicationContext, id)) {
                val key = f.optString("key")
                if (key.isEmpty()) continue
                val name = f.optString("name", "Haltestelle")
                val a = Intent(applicationContext, AlarmReceiver::class.java).apply {
                    putExtra("key", key)
                    putExtra("name", name)
                    putExtra("vib", f.optBoolean("vib", true))
                    putExtra("tone", f.optBoolean("tone", true))
                    putExtra("snd", f.optString("snd", "alarm"))
                    putExtra("vol", f.optDouble("vol", 1.0))
                    putExtra("cycle", 0)
                }
                try { sendBroadcast(a) } catch (e: Exception) { }
                GeofenceStore.recordEvent(applicationContext, key, name)
            }
        }
    }

    /** Luftlinien-Distanz in Metern (Kugel-Naeherung, reicht fuer Zaun-Check). */
    private fun distM(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Double {
        val r = 6371000.0
        val dLat = Math.toRadians(lat2 - lat1)
        val dLon = Math.toRadians(lon2 - lon1)
        val a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(Math.toRadians(lat1)) * Math.cos(Math.toRadians(lat2)) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2)
        return 2 * r * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
    }

    companion object {
        private const val CHANNEL = "alarmloc"
        private const val NOTIF_ID = 4711
        const val ACTION_STOP = "de.timodamm.citysurfers.ALARMLOC_STOP"
    }
}
