package com.akdwk.krishnareminder.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.akdwk.krishnareminder.data.api.ApiClient
import com.akdwk.krishnareminder.data.local.NoteEntity
import com.akdwk.krishnareminder.data.local.OccurrenceEntity
import com.akdwk.krishnareminder.data.local.PaymentEntity
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import com.akdwk.krishnareminder.data.repo.ReminderRepository
import com.akdwk.krishnareminder.sync.SyncWorker
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

/**
 * One view model for the whole app: the data set is small, entirely local, and
 * every screen wants the same three lists.
 */
class AppViewModel(app: Application) : AndroidViewModel(app) {

    private val repo = ReminderRepository.get(app)
    val prefs: UserPrefs = UserPrefs.get(app)

    val today: StateFlow<List<OccurrenceEntity>> =
        repo.observeToday().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val upcoming: StateFlow<List<OccurrenceEntity>> =
        repo.observeUpcoming().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val overdue: StateFlow<List<OccurrenceEntity>> =
        repo.observeOverdue().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val completed: StateFlow<List<OccurrenceEntity>> =
        repo.observeCompleted().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val notes: StateFlow<List<NoteEntity>> =
        repo.observeNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val payments: StateFlow<List<PaymentEntity>> =
        repo.observePayments().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val pendingSyncCount: StateFlow<Int> =
        repo.observePendingActionCount().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), 0)

    private val _syncing = MutableStateFlow(false)
    val syncing: StateFlow<Boolean> = _syncing.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private val _loggedIn = MutableStateFlow(prefs.isLoggedIn)
    val loggedIn: StateFlow<Boolean> = _loggedIn.asStateFlow()

    fun refresh(force: Boolean = false) {
        if (_syncing.value) return

        viewModelScope.launch {
            _syncing.value = true

            val result = repo.sync(force)

            // Always report the outcome, success included. A sync that returns
            // nothing looks identical to a sync that failed — both leave an
            // empty list — so the difference has to be said out loud.
            _message.value = if (result.isFailure) {
                "⚠️ Sync failed: " + (result.exceptionOrNull()?.message ?: "unknown error")
            } else {
                val count = result.getOrNull() ?: 0

                if (count > 0) {
                    null
                } else {
                    "Synced as " + prefs.phone + " — the server returned no reminders for this account."
                }
            }

            repo.refreshPayments()
            repo.refreshNotes()

            _syncing.value = false
        }
    }

    fun markDone(occurrenceId: Int) = viewModelScope.launch {
        repo.markDone(occurrenceId)
        _message.value = "✅"
    }

    fun snooze(occurrenceId: Int, minutes: Int) = viewModelScope.launch {
        repo.snooze(occurrenceId, minutes)
        _message.value = "⏰ +${minutes}m"
    }

    fun reschedule(occurrenceId: Int, millis: Long) = viewModelScope.launch {
        repo.reschedule(occurrenceId, millis)
    }

    fun quickAdd(text: String, onResult: (Boolean, String) -> Unit) = viewModelScope.launch {
        val result = repo.quickAdd(text)

        result.fold(
            onSuccess = { onResult(true, it) },
            onFailure = { onResult(false, it.message ?: "Could not save") }
        )
    }

    fun createReminder(fields: Map<String, Any?>, onResult: (Boolean, String) -> Unit) = viewModelScope.launch {
        val result = repo.createReminder(fields)

        result.fold(
            onSuccess = { onResult(true, "Saved") },
            onFailure = { onResult(false, it.message ?: "Could not save") }
        )
    }

    fun payPayment(paymentId: Int, amount: Double, onResult: (Boolean) -> Unit) = viewModelScope.launch {
        onResult(repo.payPayment(paymentId, amount, "cash", null).isSuccess)
    }

    fun sendTestCall(onResult: (Boolean, String) -> Unit) = viewModelScope.launch {
        try {
            val response = ApiClient.service(getApplication()).testCall()

            onResult(response.isSuccessful, response.body()?.message ?: "Test call sent")
        } catch (e: Exception) {
            onResult(false, e.message ?: "Failed")
        }
    }

    fun setLoggedIn(value: Boolean) {
        _loggedIn.value = value

        if (value) {
            SyncWorker.runOnce(getApplication(), force = true)
            refresh(force = true)
        }
    }

    fun logout() = viewModelScope.launch {
        repo.logout()
        _loggedIn.value = false
    }

    fun consumeMessage() {
        _message.value = null
    }

    fun clearMessage() {
        _message.value = null
    }
}
