package com.akdwk.krishnareminder.call

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationManagerCompat
import com.akdwk.krishnareminder.data.repo.ReminderRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * Handles Done / Snooze / Dismiss tapped on the notification (including the
 * missed-reminder notification), so the user never has to open the app.
 */
class CallActionReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val call = CallPayload.fromBundle(intent.extras) ?: return
        val action = intent.action ?: return

        val pending = goAsync()
        val appContext = context.applicationContext

        // Silence the ringing immediately, whatever the action was.
        CallService.stop(appContext)
        NotificationManagerCompat.from(appContext).cancel(CallService.notificationId(call))

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val repo = ReminderRepository.get(appContext)

                when (action) {
                    ACTION_DONE -> {
                        repo.markDone(call.occurrenceId)
                        CallService.clearMissed(appContext, call.occurrenceId)
                    }

                    ACTION_SNOOZE -> {
                        repo.snooze(call.occurrenceId, call.snoozeMinutes)
                        CallService.clearMissed(appContext, call.occurrenceId)
                    }

                    ACTION_DISMISS -> {
                        // Dismiss only stops the ring; the reminder stays open
                        // and will show up as overdue.
                    }
                }
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        const val ACTION_DONE = "com.akdwk.krishnareminder.action.DONE"
        const val ACTION_SNOOZE = "com.akdwk.krishnareminder.action.SNOOZE"
        const val ACTION_DISMISS = "com.akdwk.krishnareminder.action.DISMISS"

        fun pendingIntent(context: Context, action: String, call: CallPayload): PendingIntent {
            val intent = call.applyTo(Intent(context, CallActionReceiver::class.java))
                .setAction(action)
                .setData(android.net.Uri.parse("krishna://action/$action/${call.occurrenceId}"))

            return PendingIntent.getBroadcast(
                context,
                (action.hashCode() and 0xFFFF) + call.occurrenceId,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }
    }
}
