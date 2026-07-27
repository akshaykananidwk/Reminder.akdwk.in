package com.akdwk.krishnareminder.call

import android.content.Context
import android.media.AudioAttributes
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import java.util.Locale

/**
 * Speaks the reminder in the user's language.
 *
 * Language fallback chain gu-IN → hi-IN → en-IN → device default. When no voice
 * is available at all, `onUnavailable` fires so the call screen can fall back to
 * a beep and large text (and, if the server provides one, an MP3).
 */
class TtsManager(
    private val context: Context,
    private val onUnavailable: () -> Unit = {},
    private val onDone: () -> Unit = {}
) {

    private var tts: TextToSpeech? = null
    private var ready = false
    private var pendingText: String? = null
    private var pendingSpeed: Float = 1.0f
    private var pendingLanguage: String = "gu"
    private var repeatsLeft = 0

    fun speak(text: String, language: String, speed: Float, repeats: Int = 2) {
        if (text.isBlank()) {
            onDone()
            return
        }

        pendingText = text
        pendingSpeed = speed.coerceIn(0.5f, 1.5f)
        pendingLanguage = language
        repeatsLeft = repeats

        if (ready) {
            speakNow()
            return
        }

        tts = TextToSpeech(context) { status ->
            if (status != TextToSpeech.SUCCESS) {
                onUnavailable()
                return@TextToSpeech
            }

            val engine = tts ?: return@TextToSpeech

            engine.setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build()
            )

            val resolved = resolveLocale(engine, pendingLanguage)

            if (resolved == null) {
                onUnavailable()
                return@TextToSpeech
            }

            engine.language = resolved
            engine.setSpeechRate(pendingSpeed)

            engine.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
                override fun onStart(utteranceId: String?) = Unit

                override fun onDone(utteranceId: String?) {
                    if (repeatsLeft > 0) {
                        // A short pause between the two readings.
                        android.os.Handler(android.os.Looper.getMainLooper())
                            .postDelayed({ speakNow() }, 900)
                    } else {
                        onDone()
                    }
                }

                @Deprecated("Deprecated in Java")
                override fun onError(utteranceId: String?) {
                    onUnavailable()
                }
            })

            ready = true
            speakNow()
        }
    }

    private fun speakNow() {
        val engine = tts ?: return
        val text = pendingText ?: return

        repeatsLeft -= 1

        engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, "krishna-reminder-${System.currentTimeMillis()}")
    }

    /** gu-IN → hi-IN → en-IN → default; returns null when nothing works. */
    private fun resolveLocale(engine: TextToSpeech, language: String): Locale? {
        val chain = when (language) {
            "gu" -> listOf(Locale("gu", "IN"), Locale("hi", "IN"), Locale("en", "IN"), Locale.ENGLISH)
            "hi" -> listOf(Locale("hi", "IN"), Locale("en", "IN"), Locale.ENGLISH)
            else -> listOf(Locale("en", "IN"), Locale.ENGLISH)
        }

        for (locale in chain) {
            val result = engine.isLanguageAvailable(locale)

            if (result == TextToSpeech.LANG_AVAILABLE ||
                result == TextToSpeech.LANG_COUNTRY_AVAILABLE ||
                result == TextToSpeech.LANG_COUNTRY_VAR_AVAILABLE
            ) {
                return locale
            }
        }

        return null
    }

    fun stop() {
        repeatsLeft = 0

        try {
            tts?.stop()
        } catch (e: Exception) {
            // Engine already gone.
        }
    }

    fun release() {
        stop()

        try {
            tts?.shutdown()
        } catch (e: Exception) {
            // Ignore.
        }

        tts = null
        ready = false
    }
}
