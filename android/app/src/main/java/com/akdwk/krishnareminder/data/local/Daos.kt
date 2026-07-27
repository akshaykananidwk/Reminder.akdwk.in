package com.akdwk.krishnareminder.data.local

import androidx.room.Dao
import androidx.room.Delete
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface ReminderDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<ReminderEntity>)

    @Query("SELECT * FROM reminders WHERE deleted = 0 ORDER BY startAt ASC")
    fun observeAll(): Flow<List<ReminderEntity>>

    @Query("SELECT * FROM reminders WHERE id = :id")
    suspend fun byId(id: Int): ReminderEntity?

    @Query("DELETE FROM reminders WHERE id = :id")
    suspend fun deleteById(id: Int)

    @Query("DELETE FROM reminders")
    suspend fun clear()
}

@Dao
interface OccurrenceDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<OccurrenceEntity>)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(item: OccurrenceEntity)

    @Query("SELECT * FROM occurrences WHERE dueAtMillis BETWEEN :from AND :to ORDER BY dueAtMillis ASC")
    fun observeBetween(from: Long, to: Long): Flow<List<OccurrenceEntity>>

    @Query("SELECT * FROM occurrences WHERE status IN ('pending','notified','snoozed') AND dueAtMillis >= :from ORDER BY dueAtMillis ASC LIMIT :limit")
    fun observeUpcoming(from: Long, limit: Int = 100): Flow<List<OccurrenceEntity>>

    @Query("SELECT * FROM occurrences WHERE status IN ('pending','notified','snoozed','missed') AND dueAtMillis < :now ORDER BY dueAtMillis DESC LIMIT :limit")
    fun observeOverdue(now: Long, limit: Int = 100): Flow<List<OccurrenceEntity>>

    @Query("SELECT * FROM occurrences WHERE status = 'done' ORDER BY dueAtMillis DESC LIMIT :limit")
    fun observeCompleted(limit: Int = 100): Flow<List<OccurrenceEntity>>

    /** Everything that still needs a local alarm armed. */
    @Query("SELECT * FROM occurrences WHERE status IN ('pending','snoozed') AND dueAtMillis > :now ORDER BY dueAtMillis ASC LIMIT 200")
    suspend fun pendingForAlarms(now: Long): List<OccurrenceEntity>

    @Query("SELECT * FROM occurrences WHERE id = :id")
    suspend fun byId(id: Int): OccurrenceEntity?

    @Query("UPDATE occurrences SET status = :status WHERE id = :id")
    suspend fun setStatus(id: Int, status: String)

    @Query("UPDATE occurrences SET alarmArmed = :armed WHERE id = :id")
    suspend fun setAlarmArmed(id: Int, armed: Boolean)

    @Query("SELECT COUNT(*) FROM occurrences WHERE status = 'done' AND dueAtMillis BETWEEN :from AND :to")
    suspend fun countDone(from: Long, to: Long): Int

    @Query("SELECT COUNT(*) FROM occurrences WHERE status <> 'cancelled' AND dueAtMillis BETWEEN :from AND :to")
    suspend fun countTotal(from: Long, to: Long): Int

    @Query("DELETE FROM occurrences WHERE dueAtMillis < :before AND status IN ('done','cancelled')")
    suspend fun purgeOld(before: Long)

    @Query("DELETE FROM occurrences")
    suspend fun clear()
}

@Dao
interface PendingActionDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun add(action: PendingActionEntity)

    @Query("SELECT * FROM pending_actions ORDER BY createdAt ASC LIMIT 200")
    suspend fun all(): List<PendingActionEntity>

    @Query("SELECT COUNT(*) FROM pending_actions")
    fun observeCount(): Flow<Int>

    @Delete
    suspend fun remove(action: PendingActionEntity)

    @Query("DELETE FROM pending_actions WHERE clientId = :clientId")
    suspend fun removeById(clientId: String)

    @Query("DELETE FROM pending_actions")
    suspend fun clear()
}

@Dao
interface NoteDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<NoteEntity>)

    @Query("SELECT * FROM notes ORDER BY createdAt DESC")
    fun observeAll(): Flow<List<NoteEntity>>

    @Query("DELETE FROM notes")
    suspend fun clear()
}

@Dao
interface PaymentDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<PaymentEntity>)

    @Query("SELECT * FROM payments ORDER BY dueDate ASC")
    fun observeAll(): Flow<List<PaymentEntity>>

    @Query("DELETE FROM payments")
    suspend fun clear()
}
