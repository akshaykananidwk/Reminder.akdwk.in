package com.akdwk.krishnareminder.data.local

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * Offline cache. The server is the source of truth, but everything needed to
 * ring the phone lives here so a reminder never depends on the network.
 */
@Entity(tableName = "reminders")
data class ReminderEntity(
    @PrimaryKey val id: Int,
    val shortCode: String,
    val title: String,
    val description: String,
    val type: String,
    val priority: String,
    val startAt: String,
    val allDay: Boolean,
    val callReminder: Boolean,
    val snoozeDefaultMin: Int,
    val amount: Double?,
    val currency: String,
    val personName: String?,
    val location: String?,
    val recurrenceFreq: String,
    val status: String,
    val updatedAt: String,
    val deleted: Boolean = false
)

@Entity(
    tableName = "occurrences",
    indices = [Index("dueAtMillis"), Index("reminderId"), Index("status")]
)
data class OccurrenceEntity(
    @PrimaryKey val id: Int,
    val reminderId: Int,
    /** Epoch millis, UTC — what AlarmManager is scheduled against. */
    val dueAtMillis: Long,
    val dueAtIso: String,
    val status: String,
    val title: String,
    val shortCode: String,
    val type: String,
    val priority: String,
    val amount: Double?,
    val currency: String,
    val snoozeCount: Int,
    val attemptCount: Int,
    val updatedAt: String,
    /** True once a local exact alarm has been armed for this occurrence. */
    val alarmArmed: Boolean = false
)

/**
 * Actions taken while offline. Replayed through /sync/push with a client id so
 * a replay can never double-apply.
 */
@Entity(tableName = "pending_actions")
data class PendingActionEntity(
    @PrimaryKey val clientId: String,
    val occurrenceId: Int,
    val type: String,
    val minutes: Int? = null,
    val dueAt: String? = null,
    val note: String? = null,
    val createdAt: Long = System.currentTimeMillis(),
    val attempts: Int = 0
)

@Entity(tableName = "notes")
data class NoteEntity(
    @PrimaryKey val id: Int,
    val title: String?,
    val body: String,
    val source: String,
    val createdAt: String
)

@Entity(tableName = "payments")
data class PaymentEntity(
    @PrimaryKey val id: Int,
    val partyName: String,
    val direction: String,
    val amount: Double,
    val paidAmount: Double,
    val currency: String,
    val dueDate: String,
    val status: String,
    val shortCode: String?
)
