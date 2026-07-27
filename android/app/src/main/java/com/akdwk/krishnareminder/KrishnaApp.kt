package com.akdwk.krishnareminder

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.media.AudioAttributes
import android.os.Build
import com.akdwk.krishnareminder.sync.SyncWorker

/**
 * Application entry point.
 *
 * Creates the notification channels up front (the call channel must exist
 * before the first full-screen intent) and starts the periodic reconcile job.
 */
class KrishnaApp : Application() {

    override fun onCreate() {
        super.onCreate()
        createNotificationChannels()
        SyncWorker.schedulePeriodic(this)
    }

    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = getSystemService(NotificationManager::class.java) ?: return

        // The reminder "call": maximum importance, bypasses the notification
        // shade with a full-screen intent, and carries its own ringtone.
        val call = NotificationChannel(
            CHANNEL_CALL,
            getString(R.string.channel_call_name),
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = getString(R.string.channel_call_desc)
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 700, 500, 700, 500, 700)
            setBypassDnd(true)
            lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
            setShowBadge(true)
            // Audio is played by CallService so it can loop and respect the
            // user's chosen ringtone; the channel itself stays silent.
            setSound(null, null)
        }

        val general = NotificationChannel(
            CHANNEL_GENERAL,
            getString(R.string.channel_general_name),
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply { description = getString(R.string.channel_general_desc) }

        val advance = NotificationChannel(
            CHANNEL_ADVANCE,
            getString(R.string.channel_advance_name),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = getString(R.string.channel_advance_desc)
            setSound(
                android.media.RingtoneManager.getDefaultUri(android.media.RingtoneManager.TYPE_NOTIFICATION),
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
        }

        val missed = NotificationChannel(
            CHANNEL_MISSED,
            getString(R.string.channel_missed_name),
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply { description = getString(R.string.channel_missed_desc) }

        manager.createNotificationChannels(listOf(call, general, advance, missed))
    }

    companion object {
        const val CHANNEL_CALL = "krishna_call"
        const val CHANNEL_GENERAL = "krishna_general"
        const val CHANNEL_ADVANCE = "krishna_advance"
        const val CHANNEL_MISSED = "krishna_missed"
    }
}
