package com.akdwk.krishnareminder.data.api

import com.google.gson.annotations.SerializedName

/** Every API response uses the same envelope. */
data class ApiEnvelope<T>(
    @SerializedName("success") val success: Boolean = false,
    @SerializedName("data") val data: T? = null,
    @SerializedName("message") val message: String = "",
    @SerializedName("code") val code: String = ""
)

data class TokensDto(
    @SerializedName("access_token") val accessToken: String = "",
    @SerializedName("refresh_token") val refreshToken: String = "",
    @SerializedName("expires_at") val expiresAt: String = ""
)

data class UserDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("name") val name: String = "",
    @SerializedName("phone") val phone: String = "",
    @SerializedName("email") val email: String? = null,
    @SerializedName("language") val language: String = "gu",
    @SerializedName("timezone") val timezone: String = "Asia/Kolkata",
    @SerializedName("streak") val streak: Int = 0,
    @SerializedName("best_streak") val bestStreak: Int = 0,
    @SerializedName("reminders_paused") val remindersPaused: Boolean = false
)

data class LoginResponse(
    @SerializedName("tokens") val tokens: TokensDto = TokensDto(),
    @SerializedName("user") val user: UserDto = UserDto(),
    @SerializedName("device_id") val deviceId: Int? = null
)

data class RefreshResponse(
    @SerializedName("tokens") val tokens: TokensDto = TokensDto()
)

data class OtpResponse(
    @SerializedName("sent") val sent: Boolean = false,
    @SerializedName("retry_after") val retryAfter: Int = 60
)

data class RecurrenceDto(
    @SerializedName("freq") val freq: String = "none",
    @SerializedName("interval") val interval: Int = 1,
    @SerializedName("by_day") val byDay: List<String> = emptyList(),
    @SerializedName("by_month_day") val byMonthDay: Int? = null,
    @SerializedName("count") val count: Int? = null
)

data class OccurrenceDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("reminder_id") val reminderId: Int = 0,
    @SerializedName("due_at") val dueAt: String = "",
    @SerializedName("status") val status: String = "pending",
    @SerializedName("attempt_count") val attemptCount: Int = 0,
    @SerializedName("snooze_count") val snoozeCount: Int = 0,
    @SerializedName("done_at") val doneAt: String? = null,
    @SerializedName("done_via") val doneVia: String? = null,
    @SerializedName("title") val title: String? = null,
    @SerializedName("short_code") val shortCode: String? = null,
    @SerializedName("type") val type: String? = null,
    @SerializedName("priority") val priority: String? = null,
    @SerializedName("amount") val amount: Double? = null,
    @SerializedName("currency") val currency: String? = "INR",
    @SerializedName("updated_at") val updatedAt: String = ""
)

data class ReminderDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("short_code") val shortCode: String = "",
    @SerializedName("title") val title: String = "",
    @SerializedName("description") val description: String = "",
    @SerializedName("type") val type: String = "task",
    @SerializedName("priority") val priority: String = "normal",
    @SerializedName("start_at") val startAt: String = "",
    @SerializedName("all_day") val allDay: Boolean = false,
    @SerializedName("recurrence") val recurrence: RecurrenceDto = RecurrenceDto(),
    @SerializedName("call_reminder") val callReminder: Boolean = true,
    @SerializedName("snooze_default_min") val snoozeDefaultMin: Int = 5,
    @SerializedName("amount") val amount: Double? = null,
    @SerializedName("currency") val currency: String = "INR",
    @SerializedName("person_name") val personName: String? = null,
    @SerializedName("location") val location: String? = null,
    @SerializedName("source") val source: String = "app",
    @SerializedName("status") val status: String = "active",
    @SerializedName("updated_at") val updatedAt: String = "",
    @SerializedName("deleted") val deleted: Boolean = false,
    @SerializedName("next_occurrence") val nextOccurrence: OccurrenceDto? = null
)

data class RemindersResponse(
    @SerializedName("reminders") val reminders: List<ReminderDto> = emptyList(),
    @SerializedName("server_time") val serverTime: String = ""
)

data class OccurrencesResponse(
    @SerializedName("occurrences") val occurrences: List<OccurrenceDto> = emptyList(),
    @SerializedName("server_time") val serverTime: String = ""
)

data class SyncPullResponse(
    @SerializedName("reminders") val reminders: List<ReminderDto> = emptyList(),
    @SerializedName("occurrences") val occurrences: List<OccurrenceDto> = emptyList(),
    @SerializedName("server_time") val serverTime: String = ""
)

data class SyncPushResult(
    @SerializedName("client_id") val clientId: String = "",
    @SerializedName("status") val status: String = ""
)

data class SyncPushResponse(
    @SerializedName("results") val results: List<SyncPushResult> = emptyList(),
    @SerializedName("server_time") val serverTime: String = ""
)

data class PaymentTotalsDto(
    @SerializedName("receivable") val receivable: Double = 0.0,
    @SerializedName("payable") val payable: Double = 0.0,
    @SerializedName("overdue") val overdue: Double = 0.0,
    @SerializedName("this_month") val thisMonth: Double = 0.0,
    @SerializedName("paid_today") val paidToday: Double = 0.0
)

data class DashboardStats(
    @SerializedName("today_total") val todayTotal: Int = 0,
    @SerializedName("done_today") val doneToday: Int = 0,
    @SerializedName("pending") val pending: Int = 0,
    @SerializedName("overdue") val overdue: Int = 0,
    @SerializedName("streak") val streak: Int = 0,
    @SerializedName("progress") val progress: Int = 0,
    @SerializedName("payments") val payments: PaymentTotalsDto = PaymentTotalsDto(),
    @SerializedName("today") val today: List<OccurrenceDto> = emptyList(),
    @SerializedName("next") val next: OccurrenceDto? = null
)

data class PaymentDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("party_name") val partyName: String = "",
    @SerializedName("direction") val direction: String = "payable",
    @SerializedName("amount") val amount: Double = 0.0,
    @SerializedName("paid_amount") val paidAmount: Double = 0.0,
    @SerializedName("currency") val currency: String = "INR",
    @SerializedName("due_date") val dueDate: String = "",
    @SerializedName("status") val status: String = "unpaid",
    @SerializedName("short_code") val shortCode: String? = null
)

data class PaymentsResponse(
    @SerializedName("payments") val payments: List<PaymentDto> = emptyList(),
    @SerializedName("totals") val totals: PaymentTotalsDto = PaymentTotalsDto()
)

data class NoteDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("title") val title: String? = null,
    @SerializedName("body") val body: String = "",
    @SerializedName("source") val source: String = "app",
    @SerializedName("created_at") val createdAt: String = ""
)

data class AppVersionDto(
    @SerializedName("latest_version") val latestVersion: String = "",
    @SerializedName("min_version") val minVersion: String = "",
    @SerializedName("download_url") val downloadUrl: String = "",
    @SerializedName("release_notes") val releaseNotes: String = "",
    @SerializedName("force_update") val forceUpdate: Boolean = false
)

data class ParseResponse(
    @SerializedName("source") val source: String = "",
    @SerializedName("reply") val reply: String = "",
    @SerializedName("result") val result: Map<String, Any?>? = null
)

data class MeResponse(
    @SerializedName("user") val user: UserDto = UserDto(),
    @SerializedName("settings") val settings: Map<String, Any?>? = null,
    @SerializedName("plan") val plan: Map<String, Any?>? = null
)
