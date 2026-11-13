package de.timodamm.kvglive

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.PowerManager
import android.os.Vibrator

class AlarmReceiver : android.content.BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val name = intent.getStringExtra("name") ?: "Haltestelle"
        // Einstellungen aus dem Wecker-Dialog der Seite (v1.0.8)
        val vib = intent.getBooleanExtra("vib", true)
        val tone = intent.getBooleanExtra("tone", true)
        val snd = intent.getStringExtra("snd") ?: "alarm"
        val vol = Math.min(1.0, Math.max(0.05, intent.getDoubleExtra("vol", 1.0)))

        // Vibrations-/Wach-Sicherung direkt im Receiver (auch bei gesperrtem Bildschirm)
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        val wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "kvglive:alarm")
        wl.acquire(20000L)
        try {
            // Tonart -> Geräteklang (Wecker/Klingelton/Nachricht)
            val type = when (snd) {
                "ring" -> RingtoneManager.TYPE_RINGTONE
                "notif" -> RingtoneManager.TYPE_NOTIFICATION
                else -> RingtoneManager.TYPE_ALARM
            }
            val uri = RingtoneManager.getDefaultUri(type)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            // Klang selbst abspielen (mit gewählter Lautstärke), NICHT über den
            // Notification-Channel: dessen Ton/Lautstärke ist nach Anlage
            // unveränderlich und würde die Einstellungen ignorieren.
            if (tone && uri != null) {
                try {
                    val mp = MediaPlayer()
                    mp.setDataSource(context, uri)
                    mp.setAudioAttributes(AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_ALARM)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build())
                    mp.setVolume(vol.toFloat(), vol.toFloat())
                    mp.setOnCompletionListener { it.release() }
                    mp.prepare()
                    mp.start()
                } catch (e: Exception) { }
            }
            if (vib) {
                try {
                    val v = context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
                    val pattern = longArrayOf(0, 400, 200, 400, 200, 400, 200, 800)
                    if (v != null) {
                        if (Build.VERSION.SDK_INT >= 26)
                            v.vibrate(android.os.VibrationEffect.createWaveform(pattern, -1))
                        else @Suppress("DEPRECATION") v.vibrate(pattern, -1)
                    }
                } catch (e: Exception) { }
            }
        } catch (e: Exception) { }

        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        // Stiller Kanal: Klang und Vibration spielt der Receiver selbst (s.o.)
        val chId = "wecker-silent"
        if (Build.VERSION.SDK_INT >= 26) {
            val ch = NotificationChannel(chId, "Wecker", NotificationManager.IMPORTANCE_HIGH)
            ch.description = "Wecker vor Bus-/Zug-Abfahrt"
            ch.setSound(null, null)
            ch.enableVibration(false)
            nm.createNotificationChannel(ch)
        }

        val open = PendingIntent.getActivity(
            context, 0, Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)

        val builder = if (Build.VERSION.SDK_INT >= 26) {
            Notification.Builder(context, chId)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(context)
        }

        builder.apply {
            setSmallIcon(android.R.drawable.ic_lock_idle_alarm)
            setContentTitle("⏰ $name")
            setContentText("Bus/Zug fährt gleich ab!")
            setContentIntent(open)
            setAutoCancel(true)
            setCategory(Notification.CATEGORY_ALARM)
            setVisibility(Notification.VISIBILITY_PUBLIC)
            if (Build.VERSION.SDK_INT < 26) {
                setVibrate(longArrayOf(0))
                @Suppress("DEPRECATION")
                setPriority(Notification.PRIORITY_MAX)
            }
        }

        try { nm.notify(1, builder.build()) } catch (e: Exception) { }
        if (wl.isHeld) wl.release()
    }
}
