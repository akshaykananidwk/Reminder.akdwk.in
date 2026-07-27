package com.akdwk.krishnareminder.call

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.app.Person
import com.akdwk.krishnareminder.KrishnaApp
import com.akdwk.krishnareminder.R

/**
 * Foreground service that carries one reminder "call".
 *
 * It posts a CallStyle full-screen-intent notification (so the call appears even
 * if the Activity cannot be launched from the background), holds a wake lock,
 * plays the ringtone and stops itself after the configured ring duration.
 */
class CallService : Service() {

    private lateinit var ringtone: RingtonePlayer
    private var wakeLock: PowerManager.WakeLock? = null
    private val handler = Handler(Looper.getMainLooper())
    private var timeout: Runnable? = null
    private var payload: CallPayload? = null

    override fun onCreate() {
        super.onCreate()
        ringtone = RingtonePlayer(this)
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopEverything()
                return START_NOT_STICKY
            }

            ACTION_SILENCE -> {
                // The Activity took over audio (TTS is speaking).
                ringtone.softStop()
                return START_STICKY
            }
        }

        val call = CallPayload.fromBundle(intent?.extras)

        if (call == null) {
            stopSelf()
            return START_NOT_STICKY
        }

        payload = call

        startForeground(notificationId(call), buildNotification(call), foregroundType())
        acquireWakeLock(call.ringSeconds)
        ringtone.start(call.ringtone, call.ignoreDnd || call.priority == "urgent")
        launchCallScreen(call)
        scheduleTimeout(call)

        return START_STICKY
    }

    /* ------------------------------------------------------------- Screen */

    private fun launchCallScreen(call: CallPayload) {
        val intent = call.applyTo(Intent(this, CallActivity::class.java)).apply {
            addFlags(
                Intent.FLAG_ACTIVITY_NEW_TASK or
                    Intent.FLAG_ACTIVITY_CLEAR_TOP or
                    Intent.FLAG_ACTIVITY_NO_USER_ACTION or
                    Intent.FLAG_ACTIVITY_EXCLUDE_FROM_RECENTS
            )
        }

        try {
            startActivity(intent)
        } catch (e: Exception) {
            // Background-start restrictions: the full-screen intent on the
            // notification is the fallback and is already posted.
        }
    }

    /* ------------------------------------------------------- Notification */

    private fun buildNotification(call: CallPayload): Notification {
        val fullScreen = PendingIntent.getActivity(
            this,
            call.occurrenceId,
            call.applyTo(Intent(this, CallActivity::class.java)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(this, KrishnaApp.CHANNEL_CALL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(getString(R.string.incoming_reminder))
            .setContentText(call.title)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setOngoing(true)
            .setAutoCancel(false)
            .setFullScreenIntent(fullScreen, true)
            .setContentIntent(fullScreen)
            .addAction(0, getString(R.string.action_done), CallActionReceiver.pendingIntent(this, CallActionReceiver.ACTION_DONE, call))
            .addAction(0, getString(R.string.action_snooze), CallActionReceiver.pendingIntent(this, CallActionReceiver.ACTION_SNOOZE, call))

        // CallStyle gives the real "incoming call" treatment on Android 12+.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val caller = Person.Builder()
                .setName(getString(R.string.incoming_reminder))
                .setImportant(true)
                .build()

            builder.setStyle(
                NotificationCompat.CallStyle.forIncomingCall(
                    caller,
                    CallActionReceiver.pendingIntent(this, CallActionReceiver.ACTION_DISMISS, call),
                    fullScreen
                )
            )
        }

        return builder.build()
    }

    private fun foregroundType(): Int =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE or ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK
        } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK
        } else {
            0
        }

    /* ------------------------------------------------------------ Timeout */

    private fun scheduleTimeout(call: CallPayload) {
        timeout?.let { handler.removeCallbacks(it) }

        val runnable = Runnable {
            // Unanswered: leave a persistent "missed" notification behind.
            postMissedNotification(call)
            stopEverything()
        }

        timeout = runnable
        handler.postDelayed(runnable, (call.ringSeconds.coerceIn(10, 120) * 1000L))
    }

    private fun postMissedNotification(call: CallPayload) {
        if (!NotificationManagerCompat.from(this).areNotificationsEnabled()) return

        val open = PendingIntent.getActivity(
            this,
            call.occurrenceId + 500_000,
            Intent(this, com.akdwk.krishnareminder.ui.MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, KrishnaApp.CHANNEL_MISSED)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(getString(R.string.missed_reminder))
            .setContentText(call.title)
            .setStyle(NotificationCompat.BigTextStyle().bigText(call.title))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setOngoing(true)
            .setContentIntent(open)
            .addAction(0, getString(R.string.action_done), CallActionReceiver.pendingIntent(this, CallActionReceiver.ACTION_DONE, call))
            .addAction(0, getString(R.string.action_snooze), CallActionReceiver.pendingIntent(this, CallActionReceiver.ACTION_SNOOZE, call))
            .build()

        try {
            NotificationManagerCompat.from(this).notify(missedNotificationId(call), notification)
        } catch (e: SecurityException) {
            // Notifications revoked mid-call.
        }
    }

    /* --------------------------------------------------------- Wake lock */

    private fun acquireWakeLock(seconds: Int) {
        try {
            val power = getSystemService(Context.POWER_SERVICE) as PowerManager

            wakeLock = power.newWakeLock(
                PowerManager.PARTIAL_WAKE_LOCK,
                "krishna:call"
            ).apply { acquire((seconds + 30) * 1000L) }
        } catch (e: Exception) {
            // Ignore — the foreground service alone usually suffices.
        }
    }

    private fun stopEverything() {
        timeout?.let { handler.removeCallbacks(it) }
        timeout = null

        ringtone.stop()

        try {
            wakeLock?.takeIf { it.isHeld }?.release()
        } catch (e: Exception) {
            // Ignore.
        }

        wakeLock = null

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        stopEverything()
        super.onDestroy()
    }

    companion object {
        const val ACTION_START = "com.akdwk.krishnareminder.CALL_START"
        const val ACTION_STOP = "com.akdwk.krishnareminder.CALL_STOP"
        const val ACTION_SILENCE = "com.akdwk.krishnareminder.CALL_SILENCE"

        fun notificationId(call: CallPayload): Int = 100_000 + (call.occurrenceId % 100_000)

        fun missedNotificationId(call: CallPayload): Int = 300_000 + (call.occurrenceId % 100_000)

        fun start(context: Context, call: CallPayload) {
            val intent = call.applyTo(Intent(context, CallService::class.java)).setAction(ACTION_START)

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun stop(context: Context) {
            context.startService(Intent(context, CallService::class.java).setAction(ACTION_STOP))
        }

        fun silence(context: Context) {
            context.startService(Intent(context, CallService::class.java).setAction(ACTION_SILENCE))
        }

        fun clearMissed(context: Context, occurrenceId: Int) {
            NotificationManagerCompat.from(context).cancel(300_000 + (occurrenceId % 100_000))
        }
    }
}
