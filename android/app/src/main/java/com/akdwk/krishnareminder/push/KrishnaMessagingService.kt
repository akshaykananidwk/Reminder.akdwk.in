package com.akdwk.krishnareminder.push

import android.app.PendingIntent
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.akdwk.krishnareminder.KrishnaApp
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.call.CallPayload
import com.akdwk.krishnareminder.call.CallService
import com.akdwk.krishnareminder.data.api.ApiClient
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import com.akdwk.krishnareminder.data.repo.ReminderRepository
import com.akdwk.krishnareminder.sync.SyncWorker
import com.akdwk.krishnareminder.ui.MainActivity
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * All reminder pushes are high-priority *data* messages so the app always gets
 * control and can start the ringing service, even in the background.
 */
class KrishnaMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        super.onNewToken(token)

        val prefs = UserPrefs.get(this)
        prefs.fcmToken = token

        if (!prefs.isLoggedIn) return

        CoroutineScope(Dispatchers.IO).launch {
            try {
                ApiClient.service(applicationContext).registerDevice(
                    mapOf(
                        "device_uid" to prefs.deviceUid,
                        "fcm_token" to token,
                        "model" to android.os.Build.MODEL,
                        "manufacturer" to android.os.Build.MANUFACTURER,
                        "os_version" to android.os.Build.VERSION.RELEASE,
                        "app_version" to com.akdwk.krishnareminder.BuildConfig.VERSION_NAME
                    )
                )
            } catch (e: Exception) {
                // The next heartbeat will carry the token.
            }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data

        when (data["type"]) {
            "call" -> handleCall(data)
            "advance" -> handleAdvance(data)
            "occurrence_resolved" -> handleResolved(data)
            "broadcast" -> handleBroadcast(data)
            else -> SyncWorker.runOnce(applicationContext)
        }
    }

    /* --------------------------------------------------------------- Call */

    private fun handleCall(data: Map<String, String>) {
        val payload = CallPayload.fromMap(data) ?: return

        // Overlay the user's own preferences on the server payload.
        val prefs = UserPrefs.get(this)

        CallService.start(
            this,
            payload.copy(
                ttsEnabled = prefs.ttsEnabled && payload.ttsEnabled,
                ttsSpeed = if (prefs.ttsSpeed > 0f) prefs.ttsSpeed else payload.ttsSpeed,
                ringtone = prefs.ringtone.ifBlank { payload.ringtone }
            )
        )
    }

    /* ----------------------------------------------------- Advance alert */

    private fun handleAdvance(data: Map<String, String>) {
        val title = data["title"].orEmpty()

        if (title.isBlank() || !NotificationManagerCompat.from(this).areNotificationsEnabled()) return

        val minutes = data["minutes_before"]?.toIntOrNull() ?: 0

        val open = PendingIntent.getActivity(
            this,
            data["occurrence_id"]?.toIntOrNull() ?: 0,
            Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, KrishnaApp.CHANNEL_ADVANCE)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(if (minutes >= 60) "In ${minutes / 60} h" else "In $minutes min")
            .setContentText(title)
            .setStyle(NotificationCompat.BigTextStyle().bigText(title))
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setAutoCancel(true)
            .setContentIntent(open)
            .build()

        try {
            NotificationManagerCompat.from(this)
                .notify(200_000 + ((data["occurrence_id"]?.toIntOrNull() ?: 0) % 100_000), notification)
        } catch (e: SecurityException) {
            // Notifications revoked.
        }
    }

    /* -------------------------------------------------- Multi-device sync */

    /**
     * Another device answered first: stop ringing here and mirror the state.
     */
    private fun handleResolved(data: Map<String, String>) {
        val occurrenceId = data["occurrence_id"]?.toIntOrNull() ?: return

        CallService.stop(this)
        CallService.clearMissed(this, occurrenceId)
        NotificationManagerCompat.from(this).cancel(100_000 + (occurrenceId % 100_000))

        CoroutineScope(Dispatchers.IO).launch {
            ReminderRepository.get(applicationContext).sync(force = false)
        }
    }

    private fun handleBroadcast(data: Map<String, String>) {
        val title = data["title"].orEmpty()

        if (title.isBlank() || !NotificationManagerCompat.from(this).areNotificationsEnabled()) return

        val notification = NotificationCompat.Builder(this, KrishnaApp.CHANNEL_GENERAL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(data["body"].orEmpty())
            .setStyle(NotificationCompat.BigTextStyle().bigText(data["body"].orEmpty()))
            .setAutoCancel(true)
            .setContentIntent(
                PendingIntent.getActivity(
                    this,
                    0,
                    Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
                )
            )
            .build()

        try {
            NotificationManagerCompat.from(this).notify(400_000 + (System.currentTimeMillis() % 1000).toInt(), notification)
        } catch (e: SecurityException) {
            // Ignore.
        }
    }
}
