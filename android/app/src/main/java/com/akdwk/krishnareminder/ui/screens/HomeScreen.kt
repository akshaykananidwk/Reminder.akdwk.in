package com.akdwk.krishnareminder.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Divider
import androidx.compose.material3.ElevatedButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.data.local.OccurrenceEntity
import com.akdwk.krishnareminder.ui.AppViewModel
import com.akdwk.krishnareminder.ui.components.OccurrenceRow
import com.akdwk.krishnareminder.ui.components.StatCard
import com.akdwk.krishnareminder.util.Formatters
import com.akdwk.krishnareminder.util.PermissionUtils
import kotlinx.coroutines.delay

@Composable
fun HomeScreen(
    viewModel: AppViewModel,
    initialQuickAdd: String = "",
    onOpenAdd: () -> Unit,
    onOpenReminders: () -> Unit,
    onOpenPermissions: () -> Unit
) {
    val context = LocalContext.current

    val today by viewModel.today.collectAsState()
    val overdue by viewModel.overdue.collectAsState()
    val upcoming by viewModel.upcoming.collectAsState()
    val pendingSync by viewModel.pendingSyncCount.collectAsState()
    val syncing by viewModel.syncing.collectAsState()
    val syncMessage by viewModel.message.collectAsState()

    var quickText by remember { mutableStateOf(initialQuickAdd) }
    var adding by remember { mutableStateOf(false) }
    var now by remember { mutableStateOf(System.currentTimeMillis()) }

    val permissionsOk = remember { PermissionUtils.allCriticalGranted(context) }

    LaunchedEffect(Unit) {
        while (true) {
            delay(1000)
            now = System.currentTimeMillis()
        }
    }

    val doneToday = today.count { it.status == "done" }
    val next = upcoming.firstOrNull { it.dueAtMillis > now }

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item { Spacer(Modifier.height(8.dp)) }

        if (!permissionsOk) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Row(
                        modifier = Modifier.padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            stringResource(R.string.permission_banner),
                            style = MaterialTheme.typography.bodyMedium,
                            modifier = Modifier.weight(1f)
                        )

                        TextButton(onClick = onOpenPermissions) { Text(stringResource(R.string.grant)) }
                    }
                }
            }
        }

        if (pendingSync > 0) {
            item {
                Text(
                    "⏳ $pendingSync " + stringResource(R.string.offline_banner),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }

        // What the last sync actually did. Without this a failed sync looks
        // exactly like an empty account: the list is blank and nothing says why.
        syncMessage?.let { text ->
            item {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceVariant
                    )
                ) {
                    Row(
                        modifier = Modifier.padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            text,
                            modifier = Modifier.weight(1f),
                            style = MaterialTheme.typography.bodySmall
                        )
                        TextButton(onClick = { viewModel.clearMessage() }) { Text("✕") }
                    }
                }
            }
        }

        item {
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    OutlinedTextField(
                        value = quickText,
                        onValueChange = { quickText = it },
                        label = { Text(stringResource(R.string.natural_hint)) },
                        singleLine = false,
                        maxLines = 3,
                        enabled = !adding,
                        modifier = Modifier.fillMaxWidth()
                    )

                    Spacer(Modifier.height(8.dp))

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        ElevatedButton(
                            onClick = {
                                if (quickText.isBlank()) return@ElevatedButton

                                adding = true

                                viewModel.quickAdd(quickText) { ok, _ ->
                                    adding = false
                                    if (ok) quickText = ""
                                }
                            },
                            enabled = !adding && quickText.isNotBlank()
                        ) {
                            Text(if (adding) "…" else "✨ Add")
                        }

                        TextButton(onClick = onOpenAdd) { Text("Full form") }
                    }
                }
            }
        }

        item {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatCard("${today.size}", stringResource(R.string.today), MaterialTheme.colorScheme.primary, Modifier.weight(1f))
                StatCard("$doneToday", stringResource(R.string.completed), MaterialTheme.colorScheme.tertiary, Modifier.weight(1f))
                StatCard("${overdue.size}", stringResource(R.string.overdue), MaterialTheme.colorScheme.error, Modifier.weight(1f))
            }
        }

        item {
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    val progress = if (today.isEmpty()) 0f else doneToday.toFloat() / today.size

                    Text("${(progress * 100).toInt()}% today", style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(8.dp))

                    LinearProgressIndicator(
                        progress = { progress },
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(8.dp)
                    )

                    if (next != null) {
                        Spacer(Modifier.height(14.dp))
                        Divider()
                        Spacer(Modifier.height(12.dp))

                        Text(
                            "Next in " + Formatters.countdown(next.dueAtMillis, now),
                            style = MaterialTheme.typography.headlineMedium,
                            fontWeight = FontWeight.ExtraBold
                        )

                        Text(
                            next.title,
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            }
        }

        if (overdue.isNotEmpty()) {
            item {
                SectionHeader("❗ " + stringResource(R.string.overdue), onOpenReminders)
            }

            items(overdue.take(5), key = { it.id }) { occurrence ->
                OccurrenceRow(
                    occurrence = occurrence,
                    onDone = { viewModel.markDone(occurrence.id) },
                    onSnooze = { viewModel.snooze(occurrence.id, 10) }
                )
            }
        }

        item { SectionHeader(stringResource(R.string.today), onOpenReminders) }

        if (today.isEmpty()) {
            item {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(28.dp),
                        horizontalAlignment = Alignment.CenterHorizontally
                    ) {
                        Text("🌸", fontSize = 40.sp)
                        Spacer(Modifier.height(8.dp))
                        Text(stringResource(R.string.nothing_today), style = MaterialTheme.typography.bodyMedium)
                    }
                }
            }
        } else {
            items(today, key = { it.id }) { occurrence ->
                OccurrenceRow(
                    occurrence = occurrence,
                    onDone = { viewModel.markDone(occurrence.id) },
                    onSnooze = { viewModel.snooze(occurrence.id, 10) }
                )
            }
        }

        item {
            TextButton(
                onClick = { viewModel.refresh(force = true) },
                enabled = !syncing,
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(if (syncing) "…" else "↻ Sync")
            }
        }

        item { Spacer(Modifier.height(80.dp)) }
    }
}

@Composable
private fun SectionHeader(title: String, onMore: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(title, style = MaterialTheme.typography.titleMedium)
        TextButton(onClick = onMore) { Text("→") }
    }
}

/** Small helper so screens can share the "no items" look. */
@Composable
fun EmptyState(emoji: String, message: String) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(36.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text(emoji, fontSize = 44.sp)
        Spacer(Modifier.height(10.dp))
        Text(message, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** Convenience used by RemindersScreen for typed lists. */
fun List<OccurrenceEntity>.byStatus(vararg statuses: String): List<OccurrenceEntity> =
    filter { statuses.contains(it.status) }
