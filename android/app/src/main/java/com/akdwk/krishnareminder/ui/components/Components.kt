package com.akdwk.krishnareminder.ui.components

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.akdwk.krishnareminder.data.local.OccurrenceEntity
import com.akdwk.krishnareminder.util.Formatters

@Composable
fun StatCard(value: String, label: String, accent: Color, modifier: Modifier = Modifier) {
    Card(modifier = modifier) {
        Column(Modifier.padding(14.dp)) {
            Text(value, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold, color = accent)
            Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

/**
 * One reminder occurrence in a list, with inline Done and Snooze.
 */
@Composable
fun OccurrenceRow(
    occurrence: OccurrenceEntity,
    onDone: () -> Unit,
    onSnooze: () -> Unit,
    onClick: (() -> Unit)? = null
) {
    val isOpen = occurrence.status in listOf("pending", "notified", "snoozed", "missed")
    val isOverdue = isOpen && occurrence.dueAtMillis < System.currentTimeMillis()

    val icon = when (occurrence.type) {
        "payment" -> "💰"
        "call" -> "📞"
        "meeting" -> "👥"
        "medicine" -> "💊"
        "birthday" -> "🎂"
        "bill" -> "🧾"
        else -> "🔔"
    }

    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = if (isOverdue) MaterialTheme.colorScheme.errorContainer.copy(alpha = 0.35f)
            else MaterialTheme.colorScheme.surface
        )
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(modifier = Modifier.width(62.dp)) {
                Text(
                    Formatters.time(occurrence.dueAtMillis),
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.primary
                )

                Text(
                    Formatters.relative(occurrence.dueAtMillis),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

            Spacer(Modifier.width(10.dp))

            Column(modifier = Modifier.weight(1f)) {
                Text(
                    "$icon ${occurrence.title}",
                    style = MaterialTheme.typography.titleMedium,
                    textDecoration = if (occurrence.status == "done") TextDecoration.LineThrough else null,
                    color = if (occurrence.status == "done") MaterialTheme.colorScheme.onSurfaceVariant
                    else MaterialTheme.colorScheme.onSurface
                )

                Row(
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        occurrence.status,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )

                    if (occurrence.amount != null) {
                        Text(
                            Formatters.money(occurrence.amount, occurrence.currency),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.tertiary
                        )
                    }

                    if (occurrence.snoozeCount > 0) {
                        Text("⏰×${occurrence.snoozeCount}", style = MaterialTheme.typography.labelSmall)
                    }

                    if (occurrence.shortCode.isNotBlank()) {
                        Text(occurrence.shortCode, style = MaterialTheme.typography.labelSmall)
                    }
                }
            }

            if (isOpen) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    FilledTonalButton(onClick = onDone, contentPadding = androidx.compose.foundation.layout.PaddingValues(10.dp)) {
                        Text("✓")
                    }

                    TextButton(onClick = onSnooze, contentPadding = androidx.compose.foundation.layout.PaddingValues(8.dp)) {
                        Text("⏰")
                    }
                }
            }
        }
    }
}

@Composable
fun FilterChipRow(
    options: List<Pair<String, String>>,
    selected: String,
    onSelect: (String) -> Unit
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        options.forEach { (key, label) ->
            AssistChip(
                onClick = { onSelect(key) },
                label = { Text(label) },
                colors = if (selected == key) {
                    androidx.compose.material3.AssistChipDefaults.assistChipColors(
                        containerColor = MaterialTheme.colorScheme.primary,
                        labelColor = MaterialTheme.colorScheme.onPrimary
                    )
                } else {
                    androidx.compose.material3.AssistChipDefaults.assistChipColors()
                }
            )
        }
    }
}

@Composable
fun SectionSpacer() = Spacer(Modifier.height(12.dp))
