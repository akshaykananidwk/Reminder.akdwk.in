package com.akdwk.krishnareminder.ui.screens

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.akdwk.krishnareminder.ui.AppViewModel
import com.akdwk.krishnareminder.util.Formatters
import java.util.Calendar

/**
 * Full add form. The natural-language box at the top is the fast path; the
 * fields below are the precise one.
 */
@Composable
fun AddReminderScreen(
    viewModel: AppViewModel,
    prefill: String = "",
    onDone: () -> Unit
) {
    val context = LocalContext.current

    var natural by remember { mutableStateOf(prefill) }
    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var person by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("task") }
    var priority by remember { mutableStateOf("normal") }
    var repeat by remember { mutableStateOf("none") }
    var callReminder by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val calendar = remember {
        Calendar.getInstance().apply {
            add(Calendar.HOUR_OF_DAY, 1)
            set(Calendar.MINUTE, 0)
            set(Calendar.SECOND, 0)
        }
    }

    var dueMillis by remember { mutableStateOf(calendar.timeInMillis) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Card(modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(14.dp)) {
                Text("✨ Natural language", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(8.dp))

                OutlinedTextField(
                    value = natural,
                    onValueChange = { natural = it },
                    placeholder = { Text("કાલે સવારે 10 વાગ્યે બેંક જવાનું") },
                    modifier = Modifier.fillMaxWidth(),
                    maxLines = 3
                )

                Spacer(Modifier.height(8.dp))

                Button(
                    onClick = {
                        busy = true
                        error = null

                        viewModel.quickAdd(natural) { ok, message ->
                            busy = false

                            if (ok) onDone() else error = message
                        }
                    },
                    enabled = !busy && natural.isNotBlank()
                ) {
                    Text(if (busy) "…" else "Create")
                }
            }
        }

        Text("or fill it in", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

        OutlinedTextField(
            value = title,
            onValueChange = { title = it },
            label = { Text("Title") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true
        )

        OutlinedTextField(
            value = description,
            onValueChange = { description = it },
            label = { Text("Note") },
            modifier = Modifier.fillMaxWidth(),
            maxLines = 4
        )

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(
                onClick = {
                    val now = Calendar.getInstance().apply { timeInMillis = dueMillis }

                    DatePickerDialog(
                        context,
                        { _, year, month, day ->
                            now.set(year, month, day)
                            dueMillis = now.timeInMillis
                        },
                        now.get(Calendar.YEAR),
                        now.get(Calendar.MONTH),
                        now.get(Calendar.DAY_OF_MONTH)
                    ).show()
                },
                modifier = Modifier.weight(1f)
            ) {
                Text(Formatters.date(dueMillis))
            }

            OutlinedButton(
                onClick = {
                    val now = Calendar.getInstance().apply { timeInMillis = dueMillis }

                    TimePickerDialog(
                        context,
                        { _, hour, minute ->
                            now.set(Calendar.HOUR_OF_DAY, hour)
                            now.set(Calendar.MINUTE, minute)
                            dueMillis = now.timeInMillis
                        },
                        now.get(Calendar.HOUR_OF_DAY),
                        now.get(Calendar.MINUTE),
                        false
                    ).show()
                },
                modifier = Modifier.weight(1f)
            ) {
                Text(Formatters.time(dueMillis))
            }
        }

        ChoiceRow("Type", listOf("task", "payment", "call", "meeting", "medicine", "bill"), type) { type = it }
        ChoiceRow("Priority", listOf("low", "normal", "high", "urgent"), priority) { priority = it }
        ChoiceRow("Repeat", listOf("none", "daily", "weekly", "monthly", "yearly"), repeat) { repeat = it }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(
                value = amount,
                onValueChange = { amount = it.filter { c -> c.isDigit() || c == '.' } },
                label = { Text("Amount") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                modifier = Modifier.weight(1f),
                singleLine = true
            )

            OutlinedTextField(
                value = person,
                onValueChange = { person = it },
                label = { Text("Person") },
                modifier = Modifier.weight(1f),
                singleLine = true
            )
        }

        Row(verticalAlignment = Alignment.CenterVertically) {
            Switch(checked = callReminder, onCheckedChange = { callReminder = it })
            Spacer(Modifier.height(0.dp))
            Text("  📞 Call me at this time", style = MaterialTheme.typography.bodyMedium)
        }

        error?.let {
            Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
        }

        Button(
            onClick = {
                if (title.isBlank()) {
                    error = "Please enter a title"
                    return@Button
                }

                busy = true
                error = null

                viewModel.createReminder(
                    mapOf(
                        "title" to title,
                        "description" to description,
                        "due_at" to Formatters.toIso(dueMillis),
                        "type" to type,
                        "priority" to priority,
                        "call_reminder" to callReminder,
                        "amount" to amount.toDoubleOrNull(),
                        "person_name" to person.ifBlank { null },
                        "recurrence" to mapOf("freq" to repeat, "interval" to 1)
                    )
                ) { ok, message ->
                    busy = false

                    if (ok) onDone() else error = message
                }
            },
            enabled = !busy,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp)
        ) {
            Text(if (busy) "…" else "Save")
        }

        TextButton(onClick = onDone, modifier = Modifier.fillMaxWidth()) { Text("Cancel") }

        Spacer(Modifier.height(60.dp))
    }
}

@Composable
private fun ChoiceRow(label: String, options: List<String>, selected: String, onSelect: (String) -> Unit) {
    Column {
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScrollCompat(),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            options.forEach { option ->
                if (option == selected) {
                    Button(onClick = { onSelect(option) }) { Text(option) }
                } else {
                    OutlinedButton(onClick = { onSelect(option) }) { Text(option) }
                }
            }
        }
    }
}

@Composable
private fun Modifier.horizontalScrollCompat(): Modifier =
    this.then(androidx.compose.foundation.horizontalScroll(rememberScrollState()))
