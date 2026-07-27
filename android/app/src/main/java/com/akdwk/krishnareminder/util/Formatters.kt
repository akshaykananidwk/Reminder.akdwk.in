package com.akdwk.krishnareminder.util

import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/** Parsing and display helpers shared by the UI, alarms and the call screen. */
object Formatters {

    private val utcFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    private val isoFormat = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US)

    /** Server datetimes are UTC "yyyy-MM-dd HH:mm:ss" or ISO-8601. */
    fun parseServerTime(value: String?): Long {
        if (value.isNullOrBlank()) return 0L

        return try {
            if (value.contains('T')) {
                isoFormat.parse(value)?.time ?: 0L
            } else {
                utcFormat.parse(value)?.time ?: 0L
            }
        } catch (e: Exception) {
            try {
                utcFormat.parse(value.replace('T', ' ').substringBefore('+'))?.time ?: 0L
            } catch (e2: Exception) {
                0L
            }
        }
    }

    fun toServerUtc(millis: Long): String = utcFormat.format(Date(millis))

    fun toIso(millis: Long): String = isoFormat.format(Date(millis))

    fun time(millis: Long): String =
        SimpleDateFormat("h:mm a", Locale.getDefault()).format(Date(millis))

    fun dateTime(millis: Long): String =
        SimpleDateFormat("d MMM, h:mm a", Locale.getDefault()).format(Date(millis))

    fun date(millis: Long): String =
        SimpleDateFormat("d MMM yyyy", Locale.getDefault()).format(Date(millis))

    fun dayKey(millis: Long): String =
        SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date(millis))

    fun startOfToday(): Long = Calendar.getInstance().apply {
        set(Calendar.HOUR_OF_DAY, 0)
        set(Calendar.MINUTE, 0)
        set(Calendar.SECOND, 0)
        set(Calendar.MILLISECOND, 0)
    }.timeInMillis

    fun endOfToday(): Long = startOfToday() + 86_400_000L - 1

    /** "in 2 h 15 m" / "3 h ago" for the countdown and list rows. */
    fun relative(millis: Long, now: Long = System.currentTimeMillis()): String {
        val diff = millis - now
        val future = diff >= 0
        var seconds = kotlin.math.abs(diff) / 1000

        val days = seconds / 86400
        seconds %= 86400
        val hours = seconds / 3600
        seconds %= 3600
        val minutes = seconds / 60

        val text = when {
            days > 0 -> "${days}d ${hours}h"
            hours > 0 -> "${hours}h ${minutes}m"
            minutes > 0 -> "${minutes}m"
            else -> "now"
        }

        return if (text == "now") text else if (future) "in $text" else "$text ago"
    }

    fun countdown(millis: Long, now: Long = System.currentTimeMillis()): String {
        var seconds = ((millis - now) / 1000).coerceAtLeast(0)

        val days = seconds / 86400
        seconds %= 86400
        val hours = seconds / 3600
        seconds %= 3600
        val minutes = seconds / 60
        val remaining = seconds % 60

        return buildString {
            if (days > 0) append("${days}d ")
            append(String.format(Locale.US, "%02d:%02d:%02d", hours, minutes, remaining))
        }
    }

    fun money(amount: Double?, currency: String = "INR"): String {
        if (amount == null) return ""

        val symbol = when (currency.uppercase(Locale.US)) {
            "INR" -> "₹"
            "USD" -> "$"
            else -> "$currency "
        }

        return if (amount % 1.0 == 0.0) {
            symbol + String.format(Locale.US, "%,.0f", amount)
        } else {
            symbol + String.format(Locale.US, "%,.2f", amount)
        }
    }
}
