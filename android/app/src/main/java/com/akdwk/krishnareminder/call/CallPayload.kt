package com.akdwk.krishnareminder.call

import android.content.Intent
import android.os.Bundle

/**
 * The data an incoming reminder call carries, whether it arrived by FCM or was
 * fired by a local alarm with no network at all.
 */
data class CallPayload(
    val occurrenceId: Int,
    val reminderId: Int,
    val shortCode: String,
    val title: String,
    val description: String = "",
    val type: String = "task",
    val priority: String = "normal",
    val dueAt: String = "",
    val amount: String = "",
    val currency: String = "INR",
    val person: String = "",
    val language: String = "gu",
    val speech: String = "",
    val ringtone: String = "flute",
    val ringSeconds: Int = 45,
    val snoozeMinutes: Int = 5,
    val ttsEnabled: Boolean = true,
    val ttsSpeed: Float = 1.0f,
    val attempt: Int = 1,
    val ignoreDnd: Boolean = false
) {

    fun toBundle(): Bundle = Bundle().apply {
        putInt(K_OCCURRENCE, occurrenceId)
        putInt(K_REMINDER, reminderId)
        putString(K_CODE, shortCode)
        putString(K_TITLE, title)
        putString(K_DESC, description)
        putString(K_TYPE, type)
        putString(K_PRIORITY, priority)
        putString(K_DUE, dueAt)
        putString(K_AMOUNT, amount)
        putString(K_CURRENCY, currency)
        putString(K_PERSON, person)
        putString(K_LANG, language)
        putString(K_SPEECH, speech)
        putString(K_RINGTONE, ringtone)
        putInt(K_RING_SECONDS, ringSeconds)
        putInt(K_SNOOZE, snoozeMinutes)
        putBoolean(K_TTS, ttsEnabled)
        putFloat(K_TTS_SPEED, ttsSpeed)
        putInt(K_ATTEMPT, attempt)
        putBoolean(K_IGNORE_DND, ignoreDnd)
    }

    fun applyTo(intent: Intent): Intent = intent.putExtras(toBundle())

    companion object {
        const val K_OCCURRENCE = "occurrence_id"
        const val K_REMINDER = "reminder_id"
        const val K_CODE = "short_code"
        const val K_TITLE = "title"
        const val K_DESC = "description"
        const val K_TYPE = "reminder_type"
        const val K_PRIORITY = "priority"
        const val K_DUE = "due_at"
        const val K_AMOUNT = "amount"
        const val K_CURRENCY = "currency"
        const val K_PERSON = "person"
        const val K_LANG = "language"
        const val K_SPEECH = "speech"
        const val K_RINGTONE = "ringtone"
        const val K_RING_SECONDS = "ring_seconds"
        const val K_SNOOZE = "snooze_minutes"
        const val K_TTS = "tts_enabled"
        const val K_TTS_SPEED = "tts_speed"
        const val K_ATTEMPT = "attempt"
        const val K_IGNORE_DND = "ignore_dnd"

        fun fromBundle(bundle: Bundle?): CallPayload? {
            if (bundle == null) return null

            val occurrenceId = bundle.getInt(K_OCCURRENCE, 0)
            val title = bundle.getString(K_TITLE).orEmpty()

            if (title.isBlank()) return null

            return CallPayload(
                occurrenceId = occurrenceId,
                reminderId = bundle.getInt(K_REMINDER, 0),
                shortCode = bundle.getString(K_CODE).orEmpty(),
                title = title,
                description = bundle.getString(K_DESC).orEmpty(),
                type = bundle.getString(K_TYPE) ?: "task",
                priority = bundle.getString(K_PRIORITY) ?: "normal",
                dueAt = bundle.getString(K_DUE).orEmpty(),
                amount = bundle.getString(K_AMOUNT).orEmpty(),
                currency = bundle.getString(K_CURRENCY) ?: "INR",
                person = bundle.getString(K_PERSON).orEmpty(),
                language = bundle.getString(K_LANG) ?: "gu",
                speech = bundle.getString(K_SPEECH).orEmpty(),
                ringtone = bundle.getString(K_RINGTONE) ?: "flute",
                ringSeconds = bundle.getInt(K_RING_SECONDS, 45),
                snoozeMinutes = bundle.getInt(K_SNOOZE, 5),
                ttsEnabled = bundle.getBoolean(K_TTS, true),
                ttsSpeed = bundle.getFloat(K_TTS_SPEED, 1.0f),
                attempt = bundle.getInt(K_ATTEMPT, 1),
                ignoreDnd = bundle.getBoolean(K_IGNORE_DND, false)
            )
        }

        /** FCM delivers everything as strings. */
        fun fromMap(data: Map<String, String>): CallPayload? {
            val title = data["title"].orEmpty()
            if (title.isBlank()) return null

            return CallPayload(
                occurrenceId = data["occurrence_id"]?.toIntOrNull() ?: 0,
                reminderId = data["reminder_id"]?.toIntOrNull() ?: 0,
                shortCode = data["short_code"].orEmpty(),
                title = title,
                description = data["description"].orEmpty(),
                type = data["reminder_type"] ?: "task",
                priority = data["priority"] ?: "normal",
                dueAt = data["due_at"].orEmpty(),
                amount = data["amount"].orEmpty(),
                currency = data["currency"] ?: "INR",
                person = data["person"].orEmpty(),
                language = data["language"] ?: "gu",
                speech = data["speech"].orEmpty(),
                ringtone = data["ringtone"] ?: "flute",
                ringSeconds = data["ring_seconds"]?.toIntOrNull() ?: 45,
                snoozeMinutes = data["snooze_minutes"]?.toIntOrNull() ?: 5,
                ttsEnabled = (data["tts_enabled"] ?: "1") == "1",
                ttsSpeed = data["tts_speed"]?.toFloatOrNull() ?: 1.0f,
                attempt = data["attempt"]?.toIntOrNull() ?: 1,
                ignoreDnd = (data["ignore_dnd"] ?: "0") == "1"
            )
        }
    }
}
