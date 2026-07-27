package com.akdwk.krishnareminder.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat

val BrandBlue = Color(0xFF1B3A6B)
val BrandBlueDark = Color(0xFF16305A)
val BrandGold = Color(0xFFF2B33D)
val BrandGreen = Color(0xFF1E8E5A)
val BrandRed = Color(0xFFC0392B)
val SurfaceLight = Color(0xFFF7F8FB)
val SurfaceDark = Color(0xFF10141D)
val CardDark = Color(0xFF182030)

private val LightColors = lightColorScheme(
    primary = BrandBlue,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFE8EEFA),
    onPrimaryContainer = BrandBlue,
    secondary = BrandGold,
    onSecondary = Color(0xFF3A2A05),
    tertiary = BrandGreen,
    error = BrandRed,
    background = SurfaceLight,
    onBackground = Color(0xFF1A2233),
    surface = Color.White,
    onSurface = Color(0xFF1A2233),
    surfaceVariant = Color(0xFFEDF1F8),
    onSurfaceVariant = Color(0xFF697386),
    outline = Color(0xFFE3E7EF)
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF9FC0FF),
    onPrimary = Color(0xFF0A1B33),
    primaryContainer = Color(0xFF1B2740),
    onPrimaryContainer = Color(0xFFCFE0FF),
    secondary = BrandGold,
    onSecondary = Color(0xFF2C2313),
    tertiary = Color(0xFF6FD3A2),
    error = Color(0xFFFF8A80),
    background = SurfaceDark,
    onBackground = Color(0xFFE8ECF5),
    surface = CardDark,
    onSurface = Color(0xFFE8ECF5),
    surfaceVariant = Color(0xFF232C40),
    onSurfaceVariant = Color(0xFF98A2B8),
    outline = Color(0xFF27304A)
)

private val AppTypography = Typography(
    headlineLarge = TextStyle(fontSize = 30.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.5).sp),
    headlineMedium = TextStyle(fontSize = 24.sp, fontWeight = FontWeight.Bold),
    titleLarge = TextStyle(fontSize = 20.sp, fontWeight = FontWeight.Bold),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 16.sp),
    bodyMedium = TextStyle(fontSize = 14.sp),
    labelLarge = TextStyle(fontSize = 15.sp, fontWeight = FontWeight.SemiBold),
    labelSmall = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium)
)

@Composable
fun KrishnaTheme(
    themePreference: String = "auto",
    content: @Composable () -> Unit
) {
    val dark = when (themePreference) {
        "dark" -> true
        "light" -> false
        else -> isSystemInDarkTheme()
    }

    val colors = if (dark) DarkColors else LightColors
    val view = LocalView.current

    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as? Activity)?.window ?: return@SideEffect
            window.statusBarColor = colors.primary.toArgb()
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = false
        }
    }

    MaterialTheme(
        colorScheme = colors,
        typography = AppTypography,
        content = content
    )
}
