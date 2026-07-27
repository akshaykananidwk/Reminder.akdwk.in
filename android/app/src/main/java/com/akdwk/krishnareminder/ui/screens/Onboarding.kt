package com.akdwk.krishnareminder.ui.screens

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.util.PermissionUtils

/**
 * First run: language, three explainer slides, then the permission wizard.
 */
@Composable
fun OnboardingScreen(
    onFinish: () -> Unit,
    onLanguageSelected: (String) -> Unit
) {
    var step by remember { mutableStateOf(0) }
    var language by remember { mutableStateOf("gu") }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.SpaceBetween
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .weight(1f)
                .verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Box(
                modifier = Modifier
                    .size(96.dp)
                    .background(MaterialTheme.colorScheme.secondary, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Text("🕉️", fontSize = 44.sp)
            }

            Spacer(Modifier.height(24.dp))

            when (step) {
                0 -> {
                    Text("Krishna Reminder", style = MaterialTheme.typography.headlineMedium)
                    Spacer(Modifier.height(6.dp))
                    Text(
                        "ભાષા પસંદ કરો · भाषा चुनें · Choose language",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )

                    Spacer(Modifier.height(20.dp))

                    listOf("gu" to "ગુજરાતી", "hi" to "हिन्दी", "en" to "English").forEach { (code, label) ->
                        OutlinedButton(
                            onClick = {
                                language = code
                                onLanguageSelected(code)
                            },
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(vertical = 4.dp)
                        ) {
                            Text(
                                if (language == code) "✓  $label" else label,
                                fontSize = 17.sp,
                                fontWeight = if (language == code) FontWeight.Bold else FontWeight.Normal
                            )
                        }
                    }
                }

                else -> {
                    val titles = listOf(R.string.onboarding_1_title, R.string.onboarding_2_title, R.string.onboarding_3_title)
                    val bodies = listOf(R.string.onboarding_1_body, R.string.onboarding_2_body, R.string.onboarding_3_body)
                    val index = (step - 1).coerceIn(0, 2)

                    Text(
                        stringResource(titles[index]),
                        style = MaterialTheme.typography.headlineMedium,
                        textAlign = TextAlign.Center
                    )

                    Spacer(Modifier.height(10.dp))

                    Text(
                        stringResource(bodies[index]),
                        style = MaterialTheme.typography.bodyLarge,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center
                    )

                    if (index == 0) {
                        Spacer(Modifier.height(20.dp))

                        Card(modifier = Modifier.fillMaxWidth()) {
                            Text(
                                "કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે",
                                modifier = Modifier.padding(16.dp),
                                style = MaterialTheme.typography.bodyMedium
                            )
                        }
                    }
                }
            }
        }

        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            TextButton(onClick = onFinish) { Text(stringResource(R.string.skip)) }

            Button(onClick = { if (step >= 3) onFinish() else step += 1 }) {
                Text(if (step >= 3) stringResource(R.string.continue_label) else "→", fontSize = 16.sp)
            }
        }
    }
}

/**
 * Permission wizard. Each item explains *why* before asking, and links to the
 * OEM autostart screen where that matters.
 */
@Composable
fun PermissionsScreen(onContinue: () -> Unit) {
    val context = LocalContext.current
    var refreshKey by remember { mutableStateOf(0) }

    val notificationsOk = remember(refreshKey) { PermissionUtils.hasNotifications(context) }
    val alarmsOk = remember(refreshKey) { PermissionUtils.hasExactAlarms(context) }
    val batteryOk = remember(refreshKey) { PermissionUtils.isIgnoringBatteryOptimizations(context) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(24.dp)
    ) {
        Text(stringResource(R.string.permissions_title), style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(6.dp))
        Text(
            stringResource(R.string.permissions_body),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )

        Spacer(Modifier.height(20.dp))

        PermissionRow(
            title = stringResource(R.string.perm_notifications),
            why = stringResource(R.string.perm_notifications_why),
            granted = notificationsOk
        ) {
            context.startActivity(PermissionUtils.notificationSettingsIntent(context).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            refreshKey++
        }

        PermissionRow(
            title = stringResource(R.string.perm_alarms),
            why = stringResource(R.string.perm_alarms_why),
            granted = alarmsOk
        ) {
            PermissionUtils.exactAlarmSettingsIntent(context)?.let {
                context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }
            refreshKey++
        }

        PermissionRow(
            title = stringResource(R.string.perm_battery),
            why = stringResource(R.string.perm_battery_why),
            granted = batteryOk
        ) {
            context.startActivity(PermissionUtils.batteryOptimizationIntent(context).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            refreshKey++
        }

        if (PermissionUtils.needsAutostartGuidance()) {
            PermissionRow(
                title = stringResource(R.string.perm_autostart),
                why = stringResource(R.string.perm_autostart_why),
                granted = false
            ) {
                PermissionUtils.autostartIntent(context)?.let {
                    context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                }
                refreshKey++
            }
        }

        Spacer(Modifier.height(24.dp))

        Button(onClick = onContinue, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.continue_label), fontSize = 16.sp)
        }
    }
}

@Composable
private fun PermissionRow(
    title: String,
    why: String,
    granted: Boolean,
    onGrant: () -> Unit
) {
    Card(modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(2.dp))
                Text(why, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }

            Spacer(Modifier.size(12.dp))

            if (granted) {
                Text("✅", fontSize = 22.sp)
            } else {
                Button(onClick = onGrant) { Text(stringResource(R.string.grant)) }
            }
        }
    }
}
