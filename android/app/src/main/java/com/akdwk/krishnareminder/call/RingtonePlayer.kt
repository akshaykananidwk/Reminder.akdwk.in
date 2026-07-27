package com.akdwk.krishnareminder.call

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

/**
 * Plays the ringtone and vibration pattern for an incoming reminder call.
 * Urgent reminders use the alarm stream so they are audible in silent mode.
 */
class RingtonePlayer(private val context: Context) {

    private var player: MediaPlayer? = null
    private var vibrator: Vibrator? = null

    fun start(ringtone: String, ignoreDnd: Boolean) {
        stop()

        try {
            val uri = RingtoneManager.getActualDefaultRingtoneUri(context, RingtoneManager.TYPE_RINGTONE)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)

            player = MediaPlayer().apply {
                setDataSource(context, uri)
                setAudioAttributes(
                    AudioAttributes.Builder()
                        // USAGE_ALARM keeps ringing through Do-Not-Disturb for urgent items.
                        .setUsage(if (ignoreDnd) AudioAttributes.USAGE_ALARM else AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build()
                )
                isLooping = true
                prepare()
                start()
            }

            if (ignoreDnd) {
                raiseAlarmVolume()
            }
        } catch (e: Exception) {
            // A silent call is still a visible call — never crash here.
        }

        startVibration()
    }

    /** TTS speaks over silence, so the ringtone is faded out first. */
    fun softStop() {
        try {
            player?.setVolume(0f, 0f)
        } catch (e: Exception) {
            // Ignore.
        }

        stopVibration()
    }

    fun stop() {
        try {
            player?.let {
                if (it.isPlaying) it.stop()
                it.release()
            }
        } catch (e: Exception) {
            // Ignore.
        }

        player = null
        stopVibration()
    }

    private fun startVibration() {
        try {
            vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                val manager = context.getSystemService(VibratorManager::class.java)
                manager?.defaultVibrator
            } else {
                @Suppress("DEPRECATION")
                context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
            }

            val pattern = longArrayOf(0, 800, 600, 800, 600)

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                vibrator?.vibrate(VibrationEffect.createWaveform(pattern, 0))
            } else {
                @Suppress("DEPRECATION")
                vibrator?.vibrate(pattern, 0)
            }
        } catch (e: Exception) {
            // Some devices have no vibrator.
        }
    }

    private fun stopVibration() {
        try {
            vibrator?.cancel()
        } catch (e: Exception) {
            // Ignore.
        }

        vibrator = null
    }

    private fun raiseAlarmVolume() {
        try {
            val audio = context.getSystemService(Context.AUDIO_SERVICE) as? AudioManager ?: return
            val max = audio.getStreamMaxVolume(AudioManager.STREAM_ALARM)
            val current = audio.getStreamVolume(AudioManager.STREAM_ALARM)

            if (current < max / 2) {
                audio.setStreamVolume(AudioManager.STREAM_ALARM, max / 2, 0)
            }
        } catch (e: Exception) {
            // Volume policy can be locked down; ignore.
        }
    }
}
