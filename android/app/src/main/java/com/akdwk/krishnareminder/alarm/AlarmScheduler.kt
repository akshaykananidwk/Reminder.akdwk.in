package com.akdwk.krishnareminder.alarm

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import com.akdwk.krishnareminder.data.local.AppDatabase
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Local exact alarms.
 *
 * Every synced occurrence gets one, so the phone rings at the right minute even
 * with no internet, no push and no server. FCM is the fast path; this is the
 * guarantee.
 */
object AlarmScheduler {

    private const val TAG = "AlarmScheduler"

    /** How far ahead we arm alarms — WorkManager re-arms every 15 minutes. */
    private const val HORIZON_MILLIS = 7L * 86_400_000L

    suspend fun rearmAll(context: Context) = withContext(Dispatchers.IO) {
        val db = AppDatabase.get(context)
        val now = System.currentTimeMillis()
        val pending = db.occurrences().pendingForAlarms(now)

        var armed = 0

        for (occurrence in pending) {
            if (occurrence.dueAtMillis > now + HORIZON_MILLIS) continue

            if (schedule(context, occurrence.id, occurrence.dueAtMillis)) {
                db.occurrences().setAlarmArmed(occurrence.id, true)
                armed++
            }
        }

        Log.i(TAG, "Armed $armed alarm(s)")
    }

    /**
     * @return true when the alarm was actually scheduled
     */
    fun schedule(context: Context, occurrenceId: Int, triggerAtMillis: Long): Boolean {
        if (triggerAtMillis <= System.currentTimeMillis()) return false

        val manager = context.getSystemService(AlarmManager::class.java) ?: return false
        val intent = pendingIntent(context, occurrenceId, AlarmReceiver.ACTION_FIRE)

        return try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && !manager.canScheduleExactAlarms()) {
                // Without the exact-alarm permission Android still delivers this,
                // just with some slack. Better late than never.
                manager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMillis, intent)
                return true
            }

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                // setExactAndAllowWhileIdle is the only API that survives Doze.
                manager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMillis, intent)
            } else {
                manager.setExact(AlarmManager.RTC_WAKEUP, triggerAtMillis, intent)
            }

            true
        } catch (e: SecurityException) {
            Log.w(TAG, "Exact alarm denied: ${e.message}")
            false
        } catch (e: Exception) {
            Log.w(TAG, "Could not schedule alarm: ${e.message}")
            false
        }
    }

    /** Local-only snooze so the phone rings again even if the server is unreachable. */
    fun scheduleLocalSnooze(context: Context, occurrenceId: Int, minutes: Int) {
        schedule(context, occurrenceId, System.currentTimeMillis() + minutes * 60_000L)
    }

    fun cancel(context: Context, occurrenceId: Int) {
        val manager = context.getSystemService(AlarmManager::class.java) ?: return

        try {
            manager.cancel(pendingIntent(context, occurrenceId, AlarmReceiver.ACTION_FIRE))
        } catch (e: Exception) {
            Log.w(TAG, "Cancel failed: ${e.message}")
        }
    }

    suspend fun cancelAll(context: Context) = withContext(Dispatchers.IO) {
        val db = AppDatabase.get(context)

        db.occurrences().pendingForAlarms(0L).forEach { cancel(context, it.id) }
    }

    private fun pendingIntent(context: Context, occurrenceId: Int, action: String): PendingIntent {
        val intent = Intent(context, AlarmReceiver::class.java)
            .setAction(action)
            .putExtra(AlarmReceiver.EXTRA_OCCURRENCE_ID, occurrenceId)
            // A distinct data URI keeps PendingIntents from colliding.
            .setData(android.net.Uri.parse("krishna://occurrence/$occurrenceId"))

        return PendingIntent.getBroadcast(
            context,
            occurrenceId,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    /** Used by the settings screen to explain why reminders might be late. */
    fun canScheduleExact(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true

        return context.getSystemService(AlarmManager::class.java)?.canScheduleExactAlarms() == true
    }

    fun userLanguage(context: Context): String = UserPrefs.get(context).language
}
