package de.timodamm.kvglive

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import org.json.JSONArray
import org.json.JSONObject

/**
 * Action-Buttons der Wecker-Benachrichtigung (Stopp, Aus/An, Loeschen).
 *
 * Beim Antippen eines Buttons: (1) die Aktion dauerhaft notieren - laeuft die
 * App gerade nicht, reicht MainActivity sie beim naechsten Seiten-Load an die
 * Seite nach (window.kvgAlarmAction); (2) einen App-internen Broadcast senden,
 * den die laufende MainActivity sofort an die Seite weitergibt.
 */
class AlarmActionReceiver : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) {
        val akey = intent.getStringExtra("akey") ?: return
        val mode = intent.getStringExtra("mode") ?: return
        AlarmActionStore.record(ctx, akey, mode)
        val i = Intent(MainActivity.ACTION_ALARM_ACTION)
        i.setPackage(ctx.packageName)
        i.putExtra("akey", akey)
        i.putExtra("mode", mode)
        try { ctx.sendBroadcast(i) } catch (e: Exception) { }
    }
}

/** Nachhol-Liste der Benachrichtigungs-Aktionen (SharedPreferences, max. 20). */
object AlarmActionStore {
    private fun prefs(ctx: Context) =
        ctx.getSharedPreferences("kvglive_alarmactions", Context.MODE_PRIVATE)

    fun record(ctx: Context, akey: String, mode: String) {
        val p = prefs(ctx)
        val arr = JSONArray(p.getString("pending", "[]") ?: "[]")
        arr.put(JSONObject().put("akey", akey).put("mode", mode))
        while (arr.length() > 20) arr.remove(0)
        p.edit().putString("pending", arr.toString()).apply()
    }

    /** Aktion aus der Liste nehmen (sie wurde gerade ausgefuehrt). */
    fun remove(ctx: Context, akey: String, mode: String) {
        val p = prefs(ctx)
        val arr = JSONArray(p.getString("pending", "[]") ?: "[]")
        val out = JSONArray()
        for (i in 0 until arr.length()) {
            val o = arr.optJSONObject(i) ?: continue
            if (o.optString("akey") == akey && o.optString("mode") == mode) continue
            out.put(o)
        }
        p.edit().putString("pending", out.toString()).apply()
    }

    /** Alle offenen Aktionen abholen und Liste leeren (beim Seiten-Load). */
    fun drain(ctx: Context): JSONArray {
        val p = prefs(ctx)
        val arr = JSONArray(p.getString("pending", "[]") ?: "[]")
        if (arr.length() > 0) p.edit().putString("pending", "[]").apply()
        return arr
    }
}
