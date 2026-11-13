package de.timodamm.kvglive

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.os.Vibrator

/**
 * Wecker-Auslösung (AlarmManager). Läutet im Rhythmus 3 s Ton/Vibration,
 * 5 s Pause und wieder von vorne – so lange, bis der Wecker in der App am
 * ⏰-Symbol gelöscht wird (cancelAlarm entfernt den PendingIntent und damit
 * die gesamte Wiederholungs-Kette).
 */
class AlarmReceiver : BroadcastReceiver() {

    companion object {
        /** Keys der Wecker, deren Läut-Kette gerade aktiv ist (für ringingAlarms()). */
        val Ringing: MutableSet<String> =
            java.util.Collections.synchronizedSet(HashSet<String>())
    }

    override fun onReceive(context: Context, intent: Intent) {
        val name = intent.getStringExtra("name") ?: "Haltestelle"
        // Einstellungen aus dem Wecker-Dialog der Seite (v1.0.8)
        val vib = intent.getBooleanExtra("vib", true)
        val tone = intent.getBooleanExtra("tone", true)
        val snd = intent.getStringExtra("snd") ?: "alarm"
        val vol = Math.min(1.0, Math.max(0.05, intent.getDoubleExtra("vol", 1.0)))
        val cycle = intent.getIntExtra("cycle", 0)
        val key = intent.getStringExtra("key") ?: ""
        if (key.isNotEmpty()) Ringing.add(key)   // läutet gerade (bis cancelAlarm)

        // Vibrations-/Wach-Sicherung direkt im Receiver (auch bei gesperrtem Bildschirm)
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        val wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "kvglive:alarm")
        wl.acquire(20000L)

        // Tonart -> Geräteklang (Wecker/Klingelton/Nachricht)
        val type = when (snd) {
            "ring" -> RingtoneManager.TYPE_RINGTONE
            "notif" -> RingtoneManager.TYPE_NOTIFICATION
            else -> RingtoneManager.TYPE_ALARM
        }
        val uri = RingtoneManager.getDefaultUri(type)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)

        // 3 s klingeln/vibrieren, danach 5 s Pause, dann wieder von vorne:
        // der nächste Läut-Zyklus wird als neuer AlarmClock-Alarm (+8 s) gesetzt.
        if (tone && uri != null) {
            try {
                val mp = MediaPlayer()
                mp.setDataSource(context, uri)
                mp.setAudioAttributes(AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build())
                mp.setVolume(vol.toFloat(), vol.toFloat())
                mp.isLooping = true          // läuft, bis nach 3 s gestoppt wird
                mp.prepare()
                mp.start()
                Handler(Looper.getMainLooper()).postDelayed({
                    try { if (mp.isPlaying) mp.stop(); mp.release() } catch (e: Exception) { }
                }, 3000L)
            } catch (e: Exception) { }
        }
        if (vib) {
            try {
                val v = context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
                if (v != null) {
                    if (Build.VERSION.SDK_INT >= 26)
                        v.vibrate(android.os.VibrationEffect.createWaveform(longArrayOf(0, 3000), -1))
                    else @Suppress("DEPRECATION") v.vibrate(3000L)
                }
            } catch (e: Exception) { }
        }

        // Nächsten Läut-Zyklus einplanen (gleicher PendingIntent: cancelAlarm
        // stoppt die komplette Kette). Notification nur beim ersten Mal.
        val am = context.getSystemService(Context.ALARM_SERVICE) as android.app.AlarmManager
        val next = Intent(context, AlarmReceiver::class.java).apply {
            putExtra("key", key)
            putExtra("name", name)
            putExtra("vib", vib)
            putExtra("tone", tone)
            putExtra("snd", snd)
            putExtra("vol", vol)
            putExtra("cycle", cycle + 1)
        }
        val pi = PendingIntent.getBroadcast(
            context, key.hashCode(), next,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val show = PendingIntent.getActivity(
            context, 0, Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        am.setAlarmClock(android.app.AlarmManager.AlarmClockInfo(
            System.currentTimeMillis() + 8000L, show), pi)

        if (cycle == 0) {
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
                setContentText("Bus/Zug fährt gleich ab! Zum Beenden in der App ⏰ antippen.")
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
        }
        if (wl.isHeld) wl.release()
    }
}
