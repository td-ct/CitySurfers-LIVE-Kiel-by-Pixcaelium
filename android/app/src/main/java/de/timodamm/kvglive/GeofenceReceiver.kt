package de.timodamm.kvglive

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import org.json.JSONArray
import org.json.JSONObject

/**
 * Speicher für die von der Seite registrierten Geofences (Wecker) und die
 * Ereignisse, die im Hintergrund ausgelöst wurden. Die Seite bleibt das
 * Konzept: Sie konfiguriert (syncGeofences) und wertet aus
 * (pendingGeofenceEvents / window.kvgGeofenceEvents).
 */
object GeofenceStore {
    private fun prefs(ctx: Context) =
        ctx.getSharedPreferences("kvglive_geofence", Context.MODE_PRIVATE)

    /** Alle Zäune + Feuflags weg (bei jeder Neuregistrierung); Ereignisse bleiben.
     *  Schlüssel: "f<id>" Zaun, "fired<id>" einmal-feuern, "events" Liste. */
    fun clearFences(ctx: Context) {
        val p = prefs(ctx)
        val e = p.edit()
        for (k in p.all.keys) if (k.startsWith("f")) e.remove(k)
        e.apply()
    }

    fun put(ctx: Context, id: Int, f: JSONObject) {
        prefs(ctx).edit().putString("f$id", f.toString()).apply()
    }

    fun get(ctx: Context, id: Int): JSONObject? = try {
        JSONObject(prefs(ctx).getString("f$id", "") ?: "")
    } catch (e: Exception) { null }

    /** Jeder Zaun löst höchstens einmal aus (bis die Seite neu synchronisiert). */
    fun consumeFired(ctx: Context, id: Int): Boolean {
        val p = prefs(ctx)
        if (p.getBoolean("fired$id", false)) return false
        p.edit().putBoolean("fired$id", true).apply()
        return true
    }

    fun recordEvent(ctx: Context, key: String, name: String) {
        val p = prefs(ctx)
        val arr = JSONArray(p.getString("events", "[]") ?: "[]")
        arr.put(JSONObject().put("key", key).put("name", name)
            .put("ts", System.currentTimeMillis()))
        while (arr.length() > 50) arr.remove(0)
        p.edit().putString("events", arr.toString()).apply()
    }

    /** Ausgelöste Ereignisse abholen und leeren (für die Seite beim Zurückkehren). */
    fun drainEvents(ctx: Context): List<JSONObject> {
        val p = prefs(ctx)
        val s = p.getString("events", "[]") ?: "[]"
        p.edit().remove("events").apply()
        val out = ArrayList<JSONObject>()
        try {
            val arr = JSONArray(s)
            for (i in 0 until arr.length()) out.add(arr.getJSONObject(i))
        } catch (e: Exception) { }
        return out
    }
}

/**
 * Geofence-Wecker: Betritt das GPS den Radius um die Wecker-Haltestelle
 * (Distanz-Wecker), startet genau dieselbe Läut-Kette wie der Zeit-Wecker
 * (Broadcast an AlarmReceiver: 3 s Ton/Vibration, 5 s Pause, Wiederholung),
 * auch bei ausgeschaltetem Display / im Hintergrund. Ein Zaun feuert nur
 * einmal; die Seite löscht den Wecker beim Auswerten und synchronisiert neu.
 */
class GeofenceReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val ev = com.google.android.gms.location.GeofencingEvent.fromIntent(intent) ?: return
        if (ev.hasError()) return
        if (ev.geofenceTransition !=
            com.google.android.gms.location.Geofence.GEOFENCE_TRANSITION_ENTER) return
        for (gf in ev.triggeringGeofences ?: emptyList()) {
            val id = gf.requestId.toIntOrNull() ?: continue
            val f = GeofenceStore.get(context, id) ?: continue
            val key = f.optString("key")
            if (key.isEmpty()) continue
            val name = f.optString("name", "Haltestelle")
            if (GeofenceStore.consumeFired(context, id)) {
                val a = Intent(context, AlarmReceiver::class.java).apply {
                    putExtra("key", key)
                    putExtra("name", name)
                    putExtra("vib", f.optBoolean("vib", true))
                    putExtra("tone", f.optBoolean("tone", true))
                    putExtra("snd", f.optString("snd", "alarm"))
                    putExtra("vol", f.optDouble("vol", 1.0))
                    putExtra("cycle", 0)
                }
                try { context.sendBroadcast(a) } catch (e: Exception) { }
            }
            GeofenceStore.recordEvent(context, key, name)
        }
    }
}
