package com.akdwk.krishnareminder.alarm

import android.content.Context
import java.util.Calendar

/**
 * Builds the spoken sentence on-device.
 *
 * The server sends the same sentence with the push payload, but a locally fired
 * alarm has no network — so the phone must be able to compose it itself.
 */
object LocalSpeech {

    fun build(
        context: Context,
        title: String,
        amount: Double?,
        person: String?,
        type: String,
        dueAtMillis: Long,
        language: String,
        userName: String
    ): String {
        val firstName = userName.trim().split(" ").firstOrNull().orEmpty()
        val calendar = Calendar.getInstance().apply { timeInMillis = dueAtMillis }
        val hour24 = calendar.get(Calendar.HOUR_OF_DAY)
        val minute = calendar.get(Calendar.MINUTE)
        val hour12 = if (hour24 % 12 == 0) 12 else hour24 % 12

        val timeText = if (minute == 0) "$hour12" else "$hour12:${minute.toString().padStart(2, '0')}"

        return when (language) {
            "gu" -> buildString {
                append(if (firstName.isBlank()) "નમસ્તે. " else "નમસ્તે $firstName. ")
                append("${partOfDay(hour24, "gu")} $timeText વાગી ગયા છે. ")

                if (type == "payment" && amount != null) {
                    append("તમારે ${person ?: title} ને ${amount.toInt()} રૂપિયા આપવાના છે. ")
                } else {
                    append("તમારે $title છે. ")
                }

                append("કામ થઈ જાય એટલે થઈ ગયું દબાવો.")
            }

            "hi" -> buildString {
                append(if (firstName.isBlank()) "नमस्ते। " else "नमस्ते $firstName। ")
                append("${partOfDay(hour24, "hi")} $timeText बज चुके हैं। ")

                if (type == "payment" && amount != null) {
                    append("आपको ${person ?: title} को ${amount.toInt()} रुपये देने हैं। ")
                } else {
                    append("आपको $title है। ")
                }

                append("काम हो जाए तो हो गया दबाएँ।")
            }

            else -> buildString {
                append(if (firstName.isBlank()) "Hello. " else "Hello $firstName. ")
                append("It is $timeText ${if (hour24 < 12) "in the morning" else if (hour24 < 17) "in the afternoon" else "in the evening"}. ")

                if (type == "payment" && amount != null) {
                    append("You have a payment of ${amount.toInt()} rupees for ${person ?: title}. ")
                } else {
                    append("You have to $title. ")
                }

                append("Press Done when you finish it.")
            }
        }
    }

    private fun partOfDay(hour: Int, language: String): String = when (language) {
        "gu" -> when {
            hour < 12 -> "સવારના"
            hour < 16 -> "બપોરના"
            hour < 20 -> "સાંજના"
            else -> "રાતના"
        }

        "hi" -> when {
            hour < 12 -> "सुबह के"
            hour < 16 -> "दोपहर के"
            hour < 20 -> "शाम के"
            else -> "रात के"
        }

        else -> ""
    }
}
