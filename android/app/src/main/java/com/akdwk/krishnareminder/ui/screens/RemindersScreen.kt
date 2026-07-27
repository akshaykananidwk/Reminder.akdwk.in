package com.akdwk.krishnareminder.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.ui.AppViewModel
import com.akdwk.krishnareminder.ui.components.FilterChipRow
import com.akdwk.krishnareminder.ui.components.OccurrenceRow

@Composable
fun RemindersScreen(viewModel: AppViewModel) {
    var filter by remember { mutableStateOf("upcoming") }

    val upcoming by viewModel.upcoming.collectAsState()
    val overdue by viewModel.overdue.collectAsState()
    val completed by viewModel.completed.collectAsState()
    val today by viewModel.today.collectAsState()

    val rows = when (filter) {
        "today" -> today
        "overdue" -> overdue
        "completed" -> completed
        else -> upcoming
    }

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        item { Spacer(Modifier.height(8.dp)) }

        item {
            FilterChipRow(
                options = listOf(
                    "upcoming" to stringResource(R.string.upcoming),
                    "today" to stringResource(R.string.today),
                    "overdue" to stringResource(R.string.overdue),
                    "completed" to stringResource(R.string.completed)
                ),
                selected = filter,
                onSelect = { filter = it }
            )
        }

        if (rows.isEmpty()) {
            item { EmptyState("🔔", stringResource(R.string.nothing_today)) }
        } else {
            items(rows, key = { it.id }) { occurrence ->
                OccurrenceRow(
                    occurrence = occurrence,
                    onDone = { viewModel.markDone(occurrence.id) },
                    onSnooze = { viewModel.snooze(occurrence.id, 10) }
                )
            }
        }

        item { Spacer(Modifier.height(90.dp)) }
    }
}
