package com.akdwk.krishnareminder.widget

import android.content.Context
import androidx.compose.runtime.Composable
import androidx.compose.ui.unit.dp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.GlanceTheme
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.provideContent
import androidx.glance.background
import androidx.glance.layout.Column
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.padding
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import androidx.compose.ui.graphics.Color
import com.akdwk.krishnareminder.data.local.AppDatabase
import com.akdwk.krishnareminder.util.Formatters

/**
 * Home-screen widget: today's remaining reminders, straight from the Room cache
 * so it renders instantly and works offline.
 */
class TodayWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = TodayWidget()
}

class TodayWidget : GlanceAppWidget() {

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val db = AppDatabase.get(context)

        val items = db.occurrences()
            .pendingForAlarms(System.currentTimeMillis() - 3_600_000L)
            .filter { it.dueAtMillis <= Formatters.endOfToday() }
            .take(5)
            .map { Formatters.time(it.dueAtMillis) to it.title }

        provideContent {
            GlanceTheme {
                WidgetContent(items)
            }
        }
    }

    @Composable
    private fun WidgetContent(items: List<Pair<String, String>>) {
        Column(
            modifier = GlanceModifier
                .fillMaxSize()
                .background(ColorProvider(Color(0xFF1B3A6B)))
                .padding(12.dp)
        ) {
            Text(
                "🕉️  Today",
                style = TextStyle(
                    color = ColorProvider(Color(0xFFF2B33D)),
                    fontWeight = FontWeight.Bold
                )
            )

            if (items.isEmpty()) {
                Text(
                    "Nothing pending 🌸",
                    style = TextStyle(color = ColorProvider(Color.White)),
                    modifier = GlanceModifier.padding(top = 6.dp)
                )
            } else {
                items.forEach { (time, title) ->
                    Text(
                        "$time  ${title.take(28)}",
                        style = TextStyle(color = ColorProvider(Color.White)),
                        modifier = GlanceModifier.padding(top = 4.dp)
                    )
                }
            }
        }
    }
}
