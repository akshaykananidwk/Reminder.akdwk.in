package com.akdwk.krishnareminder.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.akdwk.krishnareminder.BuildConfig
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.data.api.ApiClient
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import com.akdwk.krishnareminder.sync.SyncWorker
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * WhatsApp-number login. The OTP arrives on WhatsApp; once verified the session
 * is designed to last indefinitely (long-lived refresh token, silent renew).
 */
@Composable
fun LoginScreen(onLoggedIn: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val prefs = remember { UserPrefs.get(context) }

    var phone by remember { mutableStateOf(prefs.phone) }
    var code by remember { mutableStateOf("") }
    var serverUrl by remember { mutableStateOf(prefs.baseUrl) }
    var showServer by remember { mutableStateOf(false) }
    var otpSent by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var cooldown by remember { mutableStateOf(0) }

    LaunchedEffect(cooldown) {
        if (cooldown > 0) {
            delay(1000)
            cooldown -= 1
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(28.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Text("🕉️", fontSize = 52.sp)
        Spacer(Modifier.height(10.dp))
        Text("Krishna Reminder", style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(4.dp))
        Text(
            stringResource(R.string.login_subtitle),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center
        )

        Spacer(Modifier.height(26.dp))

        OutlinedTextField(
            value = phone,
            onValueChange = { phone = it.filter(Char::isDigit).take(15) },
            label = { Text(stringResource(R.string.login_subtitle)) },
            placeholder = { Text("9978123146") },
            singleLine = true,
            enabled = !busy,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
            modifier = Modifier.fillMaxWidth()
        )

        if (otpSent) {
            Spacer(Modifier.height(14.dp))

            OutlinedTextField(
                value = code,
                onValueChange = { code = it.filter(Char::isDigit).take(6) },
                label = { Text(stringResource(R.string.enter_otp)) },
                singleLine = true,
                enabled = !busy,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                modifier = Modifier.fillMaxWidth()
            )
        }

        error?.let {
            Spacer(Modifier.height(10.dp))
            Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
        }

        Spacer(Modifier.height(20.dp))

        Button(
            onClick = {
                error = null
                busy = true

                scope.launch {
                    try {
                        val api = ApiClient.service(context)

                        if (!otpSent) {
                            val response = api.requestOtp(mapOf("phone" to phone))

                            if (response.isSuccessful) {
                                otpSent = true
                                cooldown = response.body()?.data?.retryAfter ?: 60
                            } else {
                                error = response.body()?.message ?: "Could not send the code"
                            }
                        } else {
                            val response = api.verifyOtp(
                                mapOf(
                                    "phone" to phone,
                                    "code" to code,
                                    "device_uid" to prefs.deviceUid,
                                    "fcm_token" to (prefs.fcmToken ?: ""),
                                    "model" to android.os.Build.MODEL,
                                    "manufacturer" to android.os.Build.MANUFACTURER,
                                    "os_version" to android.os.Build.VERSION.RELEASE,
                                    "app_version" to BuildConfig.VERSION_NAME
                                )
                            )

                            val data = response.body()?.data

                            if (response.isSuccessful && data != null && data.tokens.accessToken.isNotBlank()) {
                                prefs.accessToken = data.tokens.accessToken
                                prefs.refreshToken = data.tokens.refreshToken
                                prefs.userId = data.user.id
                                prefs.userName = data.user.name
                                prefs.phone = data.user.phone
                                prefs.language = data.user.language
                                prefs.timezone = data.user.timezone

                                SyncWorker.runOnce(context, force = true)
                                onLoggedIn()
                            } else {
                                error = response.body()?.message ?: "That code is not correct"
                            }
                        }
                    } catch (e: Exception) {
                        error = e.message ?: "Network error"
                    } finally {
                        busy = false
                    }
                }
            },
            enabled = !busy && (if (otpSent) code.length == 6 else phone.length >= 10),
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp)
        ) {
            if (busy) {
                CircularProgressIndicator(modifier = Modifier.height(20.dp), strokeWidth = 2.dp)
            } else {
                Text(
                    stringResource(if (otpSent) R.string.verify else R.string.send_otp),
                    fontSize = 16.sp
                )
            }
        }

        if (otpSent) {
            TextButton(
                onClick = {
                    otpSent = false
                    code = ""
                },
                enabled = cooldown == 0
            ) {
                Text(if (cooldown > 0) "${stringResource(R.string.resend)} (${cooldown}s)" else stringResource(R.string.resend))
            }
        }

        Spacer(Modifier.height(16.dp))

        TextButton(onClick = { showServer = !showServer }) {
            Text("Server: ${prefs.baseUrl}", style = MaterialTheme.typography.labelSmall)
        }

        if (showServer) {
            OutlinedTextField(
                value = serverUrl,
                onValueChange = { serverUrl = it },
                label = { Text("Server URL") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )

            TextButton(onClick = {
                prefs.baseUrl = serverUrl
                ApiClient.reset()
                showServer = false
            }) {
                Text(stringResource(R.string.save))
            }
        }
    }
}
