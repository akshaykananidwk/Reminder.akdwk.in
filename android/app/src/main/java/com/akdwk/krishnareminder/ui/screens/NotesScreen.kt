package com.akdwk.krishnareminder.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.akdwk.krishnareminder.data.local.NoteEntity
import com.akdwk.krishnareminder.ui.AppViewModel

/**
 * Notes — things the user asked us to remember as text rather than ring about.
 *
 * A message like "keep a note: three bullet cameras, three dome cameras and an
 * NVR" is not a reminder; it has no time and does not want an alarm. The server
 * already stored these, and the app simply had no screen to show them.
 */
@Composable
fun NotesScreen(viewModel: AppViewModel) {
    val notes by viewModel.notes.collectAsState()

    var draft by remember { mutableStateOf("") }
    var pendingDelete by remember { mutableStateOf<NoteEntity?>(null) }

    pendingDelete?.let { note ->
        AlertDialog(
            onDismissRequest = { pendingDelete = null },
            title = { Text("Delete this note?") },
            text = { Text(note.body.take(140)) },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.deleteNote(note.id)
                    pendingDelete = null
                }) { Text("Delete") }
            },
            dismissButton = {
                TextButton(onClick = { pendingDelete = null }) { Text("Cancel") }
            }
        )
    }

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(vertical = 16.dp)
    ) {
        item {
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    OutlinedTextField(
                        value = draft,
                        onValueChange = { draft = it },
                        label = { Text("New note") },
                        modifier = Modifier.fillMaxWidth()
                    )

                    Button(
                        onClick = {
                            viewModel.addNote(draft.trim())
                            draft = ""
                        },
                        enabled = draft.isNotBlank(),
                        modifier = Modifier.padding(top = 10.dp)
                    ) { Text("Save note") }
                }
            }
        }

        if (notes.isEmpty()) {
            item { EmptyState("📝", "No notes yet. Send \"note: …\" on WhatsApp or Telegram, or add one here.") }
        }

        items(notes, key = { it.id }) { note ->
            Card(modifier = Modifier.fillMaxWidth()) {
                Row(
                    modifier = Modifier.padding(14.dp),
                    verticalAlignment = Alignment.Top
                ) {
                    Column(Modifier.weight(1f)) {
                        if (!note.title.isNullOrBlank() && note.title != note.body) {
                            Text(note.title!!, style = MaterialTheme.typography.titleSmall)
                        }

                        Text(note.body, style = MaterialTheme.typography.bodyMedium)

                        Text(
                            note.createdAt,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.padding(top = 4.dp)
                        )
                    }

                    TextButton(onClick = { pendingDelete = note }) { Text("✕") }
                }
            }
        }
    }
}
