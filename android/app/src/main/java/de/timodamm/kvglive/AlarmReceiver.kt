package de.timodamm.kvglive

import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import android.os.PowerManager
import android.os.Vibrator

class AlarmReceiver : android.content.BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val name = intent.getStringExtra("name") ?: "Haltestelle"

        // Vibrations-/Wach-Sicherung direkt im Receiver (auch bei gesperrtem Bildschirm)
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        val wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "kvglive:alarm")
        wl.acquire(20000L)
        try {
            val v = context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
            val pattern = longArrayOf(0, 400, 200, 400, 200, 400, 200, 800)
            v?.vibrate(pattern, -1)
        } catch (e: Exception) { }

        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)

        val open = PendingIntent.getActivity(
            context, 0, Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)

        val builder = if (Build.VERSION.SDK_INT >= 26) {
            val ch = NotificationChannel(
                "wecker", "Wecker", NotificationManager.IMPORTANCE_HIGH)
            ch.description = "Wecker vor Bus-/Zug-Abfahrt"
            ch.enableVibration(true)
            ch.vibrationPattern = longArrayOf(0, 400, 200, 400, 200, 800)
            ch.setSound(sound, AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM).build())
            nm.createNotificationChannel(ch)
            Notification.Builder(context, "wecker")
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
                setSound(sound)
                setVibrate(longArrayOf(0, 400, 200, 400, 200, 800))
                @Suppress("DEPRECATION")
                setPriority(Notification.PRIORITY_MAX)
            }
        }

        try { nm.notify(1, builder.build()) } catch (e: Exception) { }
        if (wl.isHeld) wl.release()
    }
}
