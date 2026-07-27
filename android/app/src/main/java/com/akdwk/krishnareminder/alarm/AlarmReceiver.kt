package com.akdwk.krishnareminder.alarm

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.akdwk.krishnareminder.call.CallPayload
import com.akdwk.krishnareminder.call.CallService
import com.akdwk.krishnareminder.data.local.AppDatabase
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * Fires when a locally scheduled occurrence comes due. Reads the cached row and
 * starts the call — no network involved.
 */
class AlarmReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != ACTION_FIRE) return

        val occurrenceId = intent.getIntExtra(EXTRA_OCCURRENCE_ID, 0)

        if (occurrenceId <= 0) return

        val pending = goAsync()
        val appContext = context.applicationContext

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val db = AppDatabase.get(appContext)
                val occurrence = db.occurrences().byId(occurrenceId)

                if (occurrence == null) {
                    Log.w(TAG, "Alarm for unknown occurrence $occurrenceId")
                    return@launch
                }

                // Already handled (perhaps on another device) — stay quiet.
                if (occurrence.status !in listOf("pending", "snoozed", "notified")) {
                    return@launch
                }

                val prefs = UserPrefs.get(appContext)
                val reminder = db.reminders().byId(occurrence.reminderId)

                val payload = CallPayload(
                    occurrenceId = occurrence.id,
                    reminderId = occurrence.reminderId,
                    shortCode = occurrence.shortCode,
                    title = occurrence.title.ifBlank { reminder?.title.orEmpty() },
                    description = reminder?.description.orEmpty(),
                    type = occurrence.type,
                    priority = occurrence.priority,
                    dueAt = occurrence.dueAtIso,
                    amount = occurrence.amount?.toString().orEmpty(),
                    currency = occurrence.currency,
                    person = reminder?.personName.orEmpty(),
                    language = prefs.language,
                    // Speech is built on-device so an offline alarm still talks.
                    speech = LocalSpeech.build(appContext, occurrence.title, occurrence.amount, reminder?.personName, occurrence.type, occurrence.dueAtMillis, prefs.language, prefs.userName),
                    ringtone = prefs.ringtone,
                    ringSeconds = 45,
                    snoozeMinutes = reminder?.snoozeDefaultMin ?: 5,
                    ttsEnabled = prefs.ttsEnabled,
                    ttsSpeed = prefs.ttsSpeed,
                    attempt = 1,
                    ignoreDnd = occurrence.priority == "urgent"
                )

                db.occurrences().setStatus(occurrenceId, "notified")

                CallService.start(appContext, payload)
            } catch (e: Exception) {
                Log.e(TAG, "Alarm handling failed", e)
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        private const val TAG = "AlarmReceiver"
        const val ACTION_FIRE = "com.akdwk.krishnareminder.ALARM_FIRE"
        const val EXTRA_OCCURRENCE_ID = "occurrence_id"
    }
}
