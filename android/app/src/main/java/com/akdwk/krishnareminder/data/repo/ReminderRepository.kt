package com.akdwk.krishnareminder.data.repo

import android.content.Context
import com.akdwk.krishnareminder.alarm.AlarmScheduler
import com.akdwk.krishnareminder.data.api.ApiClient
import com.akdwk.krishnareminder.data.api.OccurrenceDto
import com.akdwk.krishnareminder.data.api.ReminderDto
import com.akdwk.krishnareminder.data.local.AppDatabase
import com.akdwk.krishnareminder.data.local.NoteEntity
import com.akdwk.krishnareminder.data.local.OccurrenceEntity
import com.akdwk.krishnareminder.data.local.PaymentEntity
import com.akdwk.krishnareminder.data.local.PendingActionEntity
import com.akdwk.krishnareminder.data.local.ReminderEntity
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import com.akdwk.krishnareminder.util.Formatters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.withContext
import java.util.UUID

/**
 * Single place where the network, the Room cache and the local alarm scheduler
 * meet.
 *
 * The rule that makes the product reliable: **an action is applied locally
 * first**, then pushed. If the push fails it is queued in `pending_actions` and
 * replayed by SyncWorker with a client id, so an offline "Done" is never lost
 * and never applied twice.
 */
class ReminderRepository private constructor(private val context: Context) {

    private val db = AppDatabase.get(context)
    private val prefs = UserPrefs.get(context)
    private val api get() = ApiClient.service(context)

    /* ---------------------------------------------------------- Observers */

    fun observeReminders(): Flow<List<ReminderEntity>> = db.reminders().observeAll()

    fun observeToday(): Flow<List<OccurrenceEntity>> =
        db.occurrences().observeBetween(Formatters.startOfToday(), Formatters.endOfToday())

    fun observeUpcoming(): Flow<List<OccurrenceEntity>> =
        db.occurrences().observeUpcoming(System.currentTimeMillis() - 3_600_000L)

    fun observeOverdue(): Flow<List<OccurrenceEntity>> =
        db.occurrences().observeOverdue(System.currentTimeMillis())

    fun observeCompleted(): Flow<List<OccurrenceEntity>> = db.occurrences().observeCompleted()

    fun observeNotes(): Flow<List<NoteEntity>> = db.notes().observeAll()

    fun observePayments(): Flow<List<PaymentEntity>> = db.payments().observeAll()

    fun observePendingActionCount(): Flow<Int> = db.pendingActions().observeCount()

    suspend fun occurrence(id: Int): OccurrenceEntity? = db.occurrences().byId(id)

    suspend fun reminder(id: Int): ReminderEntity? = db.reminders().byId(id)

    /* -------------------------------------------------------------- Sync */

    /**
     * Pull the delta since the last successful sync, cache it, and re-arm every
     * local alarm. Safe to call as often as we like.
     */
    suspend fun sync(force: Boolean = false): Result<Int> = withContext(Dispatchers.IO) {
        if (!prefs.isLoggedIn) return@withContext Result.success(0)

        try {
            // Push anything queued while offline first, so the pull reflects it.
            pushPendingActions()

            val since = if (force) null else prefs.lastSyncAt
            val response = api.syncPull(since)
            val body = response.body()

            if (!response.isSuccessful || body?.data == null) {
                return@withContext Result.failure(IllegalStateException(body?.message ?: "Sync failed (${response.code()})"))
            }

            val data = body.data

            if (data.reminders.isNotEmpty()) {
                val (deleted, live) = data.reminders.partition { it.deleted || it.status == "cancelled" }

                db.reminders().upsertAll(live.map { it.toEntity() })
                deleted.forEach { db.reminders().deleteById(it.id) }
            }

            if (data.occurrences.isNotEmpty()) {
                db.occurrences().upsertAll(data.occurrences.map { it.toEntity() })
            }

            prefs.lastSyncAt = data.serverTime.ifBlank { Formatters.toServerUtc(System.currentTimeMillis()) }

            // Purge history older than 60 days so the cache stays small.
            db.occurrences().purgeOld(System.currentTimeMillis() - 60L * 86_400_000L)

            AlarmScheduler.rearmAll(context)

            Result.success(data.occurrences.size)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    /** Replay offline actions; each carries a client id the server deduplicates. */
    suspend fun pushPendingActions(): Int = withContext(Dispatchers.IO) {
        val pending = db.pendingActions().all()

        if (pending.isEmpty() || !prefs.isLoggedIn) return@withContext 0

        val actions = pending.map { action ->
            buildMap<String, Any?> {
                put("client_id", action.clientId)
                put("type", action.type)
                put("occurrence_id", action.occurrenceId)
                action.minutes?.let { put("minutes", it) }
                action.dueAt?.let { put("due_at", it) }
                action.note?.let { put("note", it) }
            }
        }

        try {
            val response = api.syncPush(mapOf("actions" to actions))
            val results = response.body()?.data?.results.orEmpty()

            if (!response.isSuccessful) return@withContext 0

            // "duplicate" also means handled — drop it either way.
            val handled = results.filter { it.status == "ok" || it.status == "duplicate" }.map { it.clientId }.toSet()

            pending.filter { handled.contains(it.clientId) || handled.isEmpty() && results.isEmpty() }
                .forEach { db.pendingActions().remove(it) }

            handled.size
        } catch (e: Exception) {
            0
        }
    }

    /* ----------------------------------------------------------- Actions */

    suspend fun markDone(occurrenceId: Int, note: String? = null): Boolean =
        applyAction(occurrenceId, "done", note = note) {
            db.occurrences().setStatus(occurrenceId, "done")
            AlarmScheduler.cancel(context, occurrenceId)
        }

    suspend fun snooze(occurrenceId: Int, minutes: Int): Boolean =
        applyAction(occurrenceId, "snooze", minutes = minutes) {
            db.occurrences().setStatus(occurrenceId, "snoozed")
            AlarmScheduler.cancel(context, occurrenceId)

            // Ring again locally even if the server never hears about it.
            AlarmScheduler.scheduleLocalSnooze(context, occurrenceId, minutes)
        }

    suspend fun reschedule(occurrenceId: Int, newTimeMillis: Long): Boolean =
        applyAction(occurrenceId, "reschedule", dueAt = Formatters.toIso(newTimeMillis)) {
            val existing = db.occurrences().byId(occurrenceId)

            if (existing != null) {
                db.occurrences().upsert(
                    existing.copy(
                        dueAtMillis = newTimeMillis,
                        dueAtIso = Formatters.toServerUtc(newTimeMillis),
                        status = "pending",
                        alarmArmed = false
                    )
                )
            }

            AlarmScheduler.cancel(context, occurrenceId)
            AlarmScheduler.rearmAll(context)
        }

    suspend fun cancel(occurrenceId: Int): Boolean =
        applyAction(occurrenceId, "cancel") {
            db.occurrences().setStatus(occurrenceId, "cancelled")
            AlarmScheduler.cancel(context, occurrenceId)
        }

    /**
     * Apply locally, then try the network. A failure queues the action rather
     * than surfacing an error — the user's tap is never lost.
     */
    private suspend fun applyAction(
        occurrenceId: Int,
        type: String,
        minutes: Int? = null,
        dueAt: String? = null,
        note: String? = null,
        localEffect: suspend () -> Unit
    ): Boolean = withContext(Dispatchers.IO) {
        localEffect()

        val clientId = UUID.randomUUID().toString()

        val body = buildMap<String, Any?> {
            put("device_uid", prefs.deviceUid)
            minutes?.let { put("minutes", it) }
            dueAt?.let { put("due_at", it) }
            note?.let { put("note", it) }
        }

        try {
            val response = api.occurrenceAction(occurrenceId, type, body)

            if (response.isSuccessful) {
                return@withContext true
            }

            // 409 (e.g. snooze limit) is a real answer, not a network problem.
            if (response.code() in 400..499 && response.code() != 401) {
                return@withContext false
            }
        } catch (e: Exception) {
            // Fall through to queueing.
        }

        db.pendingActions().add(
            PendingActionEntity(
                clientId = clientId,
                occurrenceId = occurrenceId,
                type = type,
                minutes = minutes,
                dueAt = dueAt,
                note = note
            )
        )

        true
    }

    /* ---------------------------------------------------------- Creating */

    /** Natural-language add: the server parses it, we re-sync. */
    suspend fun quickAdd(text: String): Result<String> = withContext(Dispatchers.IO) {
        try {
            val response = api.parse(mapOf("text" to text, "create" to true))
            val body = response.body()

            if (!response.isSuccessful || body?.success != true) {
                return@withContext Result.failure(IllegalStateException(body?.message ?: "Could not create the reminder"))
            }

            sync(force = false)

            Result.success(body.data?.reply?.ifBlank { body.message } ?: body.message)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun createReminder(fields: Map<String, Any?>): Result<Int> = withContext(Dispatchers.IO) {
        try {
            val response = api.createReminder(fields, UUID.randomUUID().toString())
            val body = response.body()

            if (!response.isSuccessful || body?.data == null) {
                return@withContext Result.failure(IllegalStateException(body?.message ?: "Could not save"))
            }

            sync(force = false)

            Result.success(body.data.id)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun refreshPayments(): Result<Unit> = withContext(Dispatchers.IO) {
        try {
            val response = api.payments()
            val data = response.body()?.data ?: return@withContext Result.failure(IllegalStateException("No data"))

            db.payments().upsertAll(
                data.payments.map {
                    PaymentEntity(
                        id = it.id,
                        partyName = it.partyName,
                        direction = it.direction,
                        amount = it.amount,
                        paidAmount = it.paidAmount,
                        currency = it.currency,
                        dueDate = it.dueDate,
                        status = it.status,
                        shortCode = it.shortCode
                    )
                }
            )

            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun addNote(body: String): Result<Unit> = withContext(Dispatchers.IO) {
        try {
            val response = api.createNote(mapOf("body" to body))

            if (!response.isSuccessful) {
                return@withContext Result.failure(
                    IllegalStateException(response.body()?.message ?: "Save failed (${response.code()})")
                )
            }

            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    /** Delete a note on the server, then locally so the list updates at once. */
    suspend fun deleteNote(id: Int): Result<Unit> = withContext(Dispatchers.IO) {
        try {
            val response = api.deleteNote(id)

            if (!response.isSuccessful) {
                return@withContext Result.failure(
                    IllegalStateException(response.body()?.message ?: "Delete failed (${response.code()})")
                )
            }

            db.notes().deleteById(id)
            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    /** Delete a reminder and drop its cached occurrences and alarms. */
    suspend fun deleteReminder(id: Int): Result<Unit> = withContext(Dispatchers.IO) {
        try {
            val response = api.deleteReminder(id)

            if (!response.isSuccessful) {
                return@withContext Result.failure(
                    IllegalStateException(response.body()?.message ?: "Delete failed (${response.code()})")
                )
            }

            db.reminders().deleteById(id)
            db.occurrences().deleteByReminder(id)

            // Otherwise a deleted reminder would still ring from its local alarm.
            AlarmScheduler.rearmAll(context)

            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun refreshNotes(): Result<Unit> = withContext(Dispatchers.IO) {
        try {
            val data = api.notes().body()?.data ?: return@withContext Result.failure(IllegalStateException("No data"))

            db.notes().upsertAll(
                data.map { NoteEntity(it.id, it.title, it.body, it.source, it.createdAt) }
            )

            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun payPayment(paymentId: Int, amount: Double, method: String, note: String?): Result<Unit> =
        withContext(Dispatchers.IO) {
            try {
                val response = api.payPayment(
                    paymentId,
                    mapOf("amount" to amount, "method" to method, "note" to note)
                )

                if (!response.isSuccessful) {
                    return@withContext Result.failure(IllegalStateException(response.body()?.message ?: "Failed"))
                }

                refreshPayments()
                Result.success(Unit)
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    /* ---------------------------------------------------------- Counters */

    suspend fun todayProgress(): Pair<Int, Int> = withContext(Dispatchers.IO) {
        val from = Formatters.startOfToday()
        val to = Formatters.endOfToday()

        db.occurrences().countDone(from, to) to db.occurrences().countTotal(from, to)
    }

    suspend fun logout() = withContext(Dispatchers.IO) {
        try {
            api.logout()
        } catch (e: Exception) {
            // Local logout must succeed regardless.
        }

        AlarmScheduler.cancelAll(context)
        db.reminders().clear()
        db.occurrences().clear()
        db.pendingActions().clear()
        db.notes().clear()
        db.payments().clear()
        prefs.clearSession()
    }

    /* --------------------------------------------------------- Mapping */

    private fun ReminderDto.toEntity() = ReminderEntity(
        id = id,
        shortCode = shortCode,
        title = title,
        description = description,
        type = type,
        priority = priority,
        startAt = startAt,
        allDay = allDay,
        callReminder = callReminder,
        snoozeDefaultMin = snoozeDefaultMin,
        amount = amount,
        currency = currency,
        personName = personName,
        location = location,
        recurrenceFreq = recurrence.freq,
        status = status,
        updatedAt = updatedAt,
        deleted = deleted
    )

    private fun OccurrenceDto.toEntity() = OccurrenceEntity(
        id = id,
        reminderId = reminderId,
        dueAtMillis = Formatters.parseServerTime(dueAt),
        dueAtIso = dueAt,
        status = status,
        title = title.orEmpty(),
        shortCode = shortCode.orEmpty(),
        type = type ?: "task",
        priority = priority ?: "normal",
        amount = amount,
        currency = currency ?: "INR",
        snoozeCount = snoozeCount,
        attemptCount = attemptCount,
        updatedAt = updatedAt,
        alarmArmed = false
    )

    companion object {
        @Volatile
        private var instance: ReminderRepository? = null

        fun get(context: Context): ReminderRepository = instance ?: synchronized(this) {
            instance ?: ReminderRepository(context.applicationContext).also { instance = it }
        }
    }
}
