package com.akdwk.krishnareminder.call

import android.app.KeyguardManager
import android.content.Context
import android.os.Build
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.lifecycle.lifecycleScope
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.data.repo.ReminderRepository
import com.akdwk.krishnareminder.util.Formatters
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The incoming-call screen.
 *
 * It shows over the lock screen, turns the display on, speaks the reminder in
 * the user's language, and offers Done / Snooze / Reschedule / Dismiss.
 * Every action is applied through the repository, which works offline.
 */
class CallActivity : ComponentActivity() {

    private var tts: TtsManager? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        showOverLockScreen()

        val payload = CallPayload.fromBundle(intent.extras)

        if (payload == null) {
            finish()
            return
        }

        setContent {
            CallScreen(
                payload = payload,
                onDone = { finishWith { ReminderRepository.get(applicationContext).markDone(payload.occurrenceId) } },
                onSnooze = { minutes ->
                    finishWith { ReminderRepository.get(applicationContext).snooze(payload.occurrenceId, minutes) }
                },
                onReschedule = { millis ->
                    finishWith { ReminderRepository.get(applicationContext).reschedule(payload.occurrenceId, millis) }
                },
                onDismiss = { finishWith { true } },
                onSpeechReady = { text, language, speed -> speak(text, language, speed) }
            )
        }
    }

    /* ------------------------------------------------------- Window flags */

    private fun showOverLockScreen() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)

            val keyguard = getSystemService(Context.KEYGUARD_SERVICE) as? KeyguardManager
            keyguard?.requestDismissKeyguard(this, null)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
                    WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD
            )
        }

        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    /* --------------------------------------------------------------- TTS */

    private fun speak(text: String, language: String, speed: Float) {
        if (text.isBlank()) return

        // Fade the ringtone so the speech is intelligible.
        CallService.silence(this)

        tts?.release()

        tts = TtsManager(
            context = this,
            onUnavailable = {
                // No voice for this language: the big text on screen plus the
                // ringtone are the fallback, so just let it keep ringing.
            },
            onDone = { }
        ).also { it.speak(text, language, speed, repeats = 2) }
    }

    private fun finishWith(action: suspend () -> Boolean) {
        CallService.stop(this)
        tts?.stop()

        lifecycleScope.launch {
            try {
                action()
            } finally {
                finishAndRemoveTask()
            }
        }
    }

    override fun onDestroy() {
        tts?.release()
        tts = null
        super.onDestroy()
    }
}

/* ------------------------------------------------------------------- UI */

@Composable
private fun CallScreen(
    payload: CallPayload,
    onDone: () -> Unit,
    onSnooze: (Int) -> Unit,
    onReschedule: (Long) -> Unit,
    onDismiss: () -> Unit,
    onSpeechReady: (String, String, Float) -> Unit
) {
    var showSnoozeOptions by remember { mutableStateOf(false) }
    var elapsed by remember { mutableStateOf(0) }

    // Speak once, shortly after the screen appears.
    LaunchedEffect(payload.occurrenceId) {
        if (payload.ttsEnabled && payload.speech.isNotBlank()) {
            delay(1200)
            onSpeechReady(payload.speech, payload.language, payload.ttsSpeed)
        }
    }

    LaunchedEffect(Unit) {
        while (true) {
            delay(1000)
            elapsed += 1
        }
    }

    val transition = rememberInfiniteTransition(label = "pulse")
    val pulse by transition.animateFloat(
        initialValue = 1f,
        targetValue = 1.12f,
        animationSpec = infiniteRepeatable(tween(900), RepeatMode.Reverse),
        label = "pulse"
    )

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    listOf(Color(0xFF1B3A6B), Color(0xFF23477E), Color(0xFF122A4F))
                )
            )
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.SpaceBetween
        ) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                modifier = Modifier.padding(top = 48.dp)
            ) {
                Text(
                    text = stringOrDefault(payload.type),
                    color = Color(0xFFF2B33D),
                    fontSize = 14.sp,
                    fontWeight = FontWeight.SemiBold
                )

                Spacer(Modifier.height(10.dp))

                Box(
                    modifier = Modifier
                        .size(112.dp)
                        .scale(pulse)
                        .background(Color(0xFFF2B33D), CircleShape),
                    contentAlignment = Alignment.Center
                ) {
                    Text("🕉️", fontSize = 52.sp)
                }

                Spacer(Modifier.height(18.dp))

                Text(
                    text = "Krishna Reminder",
                    color = Color.White,
                    fontSize = 26.sp,
                    fontWeight = FontWeight.Bold
                )

                Spacer(Modifier.height(6.dp))

                Text(
                    text = "%02d:%02d".format(elapsed / 60, elapsed % 60),
                    color = Color(0xFFB9C9E8),
                    fontSize = 14.sp
                )
            }

            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(20.dp),
                colors = CardDefaults.cardColors(containerColor = Color(0x1FFFFFFF))
            ) {
                Column(Modifier.padding(20.dp)) {
                    Text(
                        text = payload.title,
                        color = Color.White,
                        fontSize = 24.sp,
                        fontWeight = FontWeight.Bold,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )

                    if (payload.amount.isNotBlank()) {
                        Spacer(Modifier.height(8.dp))

                        Text(
                            text = Formatters.money(payload.amount.toDoubleOrNull(), payload.currency),
                            color = Color(0xFFF2B33D),
                            fontSize = 22.sp,
                            fontWeight = FontWeight.Bold,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }

                    if (payload.person.isNotBlank()) {
                        Spacer(Modifier.height(4.dp))

                        Text(
                            text = payload.person,
                            color = Color(0xFFD6E1F5),
                            fontSize = 16.sp,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }

                    if (payload.shortCode.isNotBlank()) {
                        Spacer(Modifier.height(10.dp))

                        Text(
                            text = payload.shortCode,
                            color = Color(0xFF9DB2D8),
                            fontSize = 13.sp,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                }
            }

            Column(modifier = Modifier.fillMaxWidth()) {
                if (showSnoozeOptions) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceEvenly
                    ) {
                        listOf(5, 10, 15, 30, 60).forEach { minutes ->
                            TextButton(onClick = { onSnooze(minutes) }) {
                                Text("${minutes}m", color = Color(0xFFF2B33D), fontSize = 17.sp, fontWeight = FontWeight.Bold)
                            }
                        }
                    }

                    Spacer(Modifier.height(8.dp))
                }

                Button(
                    onClick = onDone,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(64.dp),
                    shape = RoundedCornerShape(18.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF1E8E5A))
                ) {
                    Text("✅  " + androidx.compose.ui.platform.LocalContext.current.getString(R.string.action_done), fontSize = 19.sp, fontWeight = FontWeight.Bold, color = Color.White)
                }

                Spacer(Modifier.height(10.dp))

                Row(modifier = Modifier.fillMaxWidth()) {
                    Button(
                        onClick = {
                            if (showSnoozeOptions) onSnooze(payload.snoozeMinutes) else showSnoozeOptions = true
                        },
                        modifier = Modifier
                            .weight(1f)
                            .height(56.dp),
                        shape = RoundedCornerShape(16.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0x33FFFFFF))
                    ) {
                        Text("⏰  ${payload.snoozeMinutes}m", fontSize = 16.sp, color = Color.White)
                    }

                    Spacer(Modifier.size(10.dp))

                    Button(
                        onClick = { onReschedule(System.currentTimeMillis() + 2 * 3_600_000L) },
                        modifier = Modifier
                            .weight(1f)
                            .height(56.dp),
                        shape = RoundedCornerShape(16.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0x33FFFFFF))
                    ) {
                        Text("📅  +2h", fontSize = 16.sp, color = Color.White)
                    }
                }

                Spacer(Modifier.height(6.dp))

                TextButton(
                    onClick = onDismiss,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text(
                        androidx.compose.ui.platform.LocalContext.current.getString(R.string.action_dismiss),
                        color = Color(0xFF9DB2D8),
                        fontSize = 15.sp
                    )
                }
            }
        }
    }
}

@Composable
private fun stringOrDefault(type: String): String = when (type) {
    "payment" -> "💰 Payment"
    "call" -> "📞 Call"
    "meeting" -> "👥 Meeting"
    "medicine" -> "💊 Medicine"
    "birthday" -> "🎂 Birthday"
    "bill" -> "🧾 Bill"
    else -> "🔔 Reminder"
}
