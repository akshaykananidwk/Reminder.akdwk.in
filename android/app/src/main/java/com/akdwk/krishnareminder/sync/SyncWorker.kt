package com.akdwk.krishnareminder.sync

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.akdwk.krishnareminder.alarm.AlarmScheduler
import com.akdwk.krishnareminder.data.api.ApiClient
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import com.akdwk.krishnareminder.data.repo.ReminderRepository
import com.akdwk.krishnareminder.BuildConfig
import java.util.concurrent.TimeUnit

/**
 * Periodic reconcile: push queued offline actions, pull the server delta,
 * re-arm local alarms and send a device heartbeat (which also refreshes the
 * FCM token server-side).
 */
class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val prefs = UserPrefs.get(applicationContext)

        if (!prefs.isLoggedIn) return Result.success()

        return try {
            val repo = ReminderRepository.get(applicationContext)

            repo.pushPendingActions()

            val synced = repo.sync(force = inputData.getBoolean(KEY_FORCE, false))

            AlarmScheduler.rearmAll(applicationContext)

            try {
                ApiClient.service(applicationContext).heartbeat(
                    mapOf(
                        "device_uid" to prefs.deviceUid,
                        "fcm_token" to (prefs.fcmToken ?: ""),
                        "app_version" to BuildConfig.VERSION_NAME
                    )
                )
            } catch (e: Exception) {
                // Heartbeat is best-effort.
            }

            if (synced.isSuccess) Result.success() else Result.retry()
        } catch (e: Exception) {
            Result.retry()
        }
    }

    companion object {
        private const val UNIQUE_PERIODIC = "krishna-sync-periodic"
        private const val UNIQUE_ONCE = "krishna-sync-once"
        const val KEY_FORCE = "force"

        fun schedulePeriodic(context: Context) {
            val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .setBackoffCriteria(androidx.work.BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                UNIQUE_PERIODIC,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }

        fun runOnce(context: Context, force: Boolean = false) {
            val request = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .setInputData(androidx.work.Data.Builder().putBoolean(KEY_FORCE, force).build())
                .build()

            WorkManager.getInstance(context).enqueueUniqueWork(
                UNIQUE_ONCE,
                androidx.work.ExistingWorkPolicy.REPLACE,
                request
            )
        }
    }
}
