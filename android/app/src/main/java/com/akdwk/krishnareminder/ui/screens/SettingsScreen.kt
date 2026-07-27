package com.akdwk.krishnareminder.ui.screens

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Divider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Slider
import androidx.compose.material3.Switch
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.akdwk.krishnareminder.BuildConfig
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.util.PermissionUtils
import com.akdwk.krishnareminder.ui.AppViewModel

@Composable
fun SettingsScreen(
    viewModel: AppViewModel,
    onAbout: () -> Unit,
    onPermissions: () -> Unit,
    onLoggedOut: () -> Unit
) {
    val context = LocalContext.current
    val prefs = viewModel.prefs

    var tts by remember { mutableStateOf(prefs.ttsEnabled) }
    var speed by remember { mutableStateOf(prefs.ttsSpeed) }
    var theme by remember { mutableStateOf(prefs.theme) }
    var language by remember { mutableStateOf(prefs.language) }
    var ringtone by remember { mutableStateOf(prefs.ringtone) }
    var testResult by remember { mutableStateOf<String?>(null) }

    val pendingSync by viewModel.pendingSyncCount.collectAsState()

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Card(modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp)) {
                Text(prefs.userName.ifBlank { "Krishna Reminder" }, style = MaterialTheme.typography.titleLarge)
                Text(prefs.phone, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(prefs.baseUrl, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

                if (pendingSync > 0) {
                    Spacer(Modifier.height(6.dp))
                    Text("⏳ $pendingSync action(s) waiting to sync", style = MaterialTheme.typography.labelSmall)
                }
            }
        }

        SettingsSection("🗣️ Voice") {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Speak the reminder aloud", modifier = Modifier.weight(1f))

                Switch(
                    checked = tts,
                    onCheckedChange = {
                        tts = it
                        prefs.ttsEnabled = it
                    }
                )
            }

            Text("Speed: ${"%.2f".format(speed)}", style = MaterialTheme.typography.labelSmall)

            Slider(
                value = speed,
                onValueChange = { speed = it },
                onValueChangeFinished = { prefs.ttsSpeed = speed },
                valueRange = 0.6f..1.4f
            )

            Text("Ringtone", style = MaterialTheme.typography.labelSmall)

            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("flute", "bell", "classic", "soft").forEach { option ->
                    if (option == ringtone) {
                        Button(onClick = { ringtone = option; prefs.ringtone = option }) { Text(option) }
                    } else {
                        OutlinedButton(onClick = { ringtone = option; prefs.ringtone = option }) { Text(option) }
                    }
                }
            }
        }

        SettingsSection("🌐 Language & theme") {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("gu" to "ગુજરાતી", "hi" to "हिन्दी", "en" to "English").forEach { (code, label) ->
                    if (code == language) {
                        Button(onClick = { language = code; prefs.language = code }) { Text(label) }
                    } else {
                        OutlinedButton(onClick = { language = code; prefs.language = code }) { Text(label) }
                    }
                }
            }

            Spacer(Modifier.height(8.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("auto", "light", "dark").forEach { option ->
                    if (option == theme) {
                        Button(onClick = { theme = option; prefs.theme = option }) { Text(option) }
                    } else {
                        OutlinedButton(onClick = { theme = option; prefs.theme = option }) { Text(option) }
                    }
                }
            }

            Text(
                "Theme applies the next time the app starts.",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }

        SettingsSection("🔔 Reliability") {
            val allGranted = PermissionUtils.allCriticalGranted(context)

            Text(
                if (allGranted) "✅ All critical permissions granted" else "⚠️ Some permissions are missing",
                style = MaterialTheme.typography.bodyMedium
            )

            OutlinedButton(onClick = onPermissions, modifier = Modifier.fillMaxWidth()) {
                Text("Open permission wizard")
            }

            Button(
                onClick = {
                    testResult = "Sending…"
                    viewModel.sendTestCall { ok, message -> testResult = if (ok) "✅ $message" else "❌ $message" }
                },
                modifier = Modifier.fillMaxWidth()
            ) {
                Text("📞 Send me a test call")
            }

            testResult?.let { Text(it, style = MaterialTheme.typography.labelSmall) }
        }

        SettingsSection("ℹ️ App") {
            Text("Version ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})", style = MaterialTheme.typography.bodyMedium)

            OutlinedButton(
                onClick = {
                    context.startActivity(
                        Intent(Intent.ACTION_VIEW, Uri.parse("https://github.com/${BuildConfig.GITHUB_REPO}/releases/latest"))
                            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    )
                },
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(stringResource(R.string.check_update))
            }

            TextButton(onClick = onAbout, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.about))
            }
        }

        Divider()

        OutlinedButton(
            onClick = {
                viewModel.logout()
                onLoggedOut()
            },
            modifier = Modifier.fillMaxWidth()
        ) {
            Text(stringResource(R.string.logout), color = MaterialTheme.colorScheme.error)
        }

        Spacer(Modifier.height(90.dp))
    }
}

@Composable
private fun SettingsSection(title: String, content: @Composable () -> Unit) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            content()
        }
    }
}
