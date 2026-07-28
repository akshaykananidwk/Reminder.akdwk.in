package com.akdwk.krishnareminder.util

import android.Manifest
import android.app.AlarmManager
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat

/**
 * The three permissions that decide whether the phone actually rings, plus the
 * manufacturer autostart screens that silently kill background apps.
 */
object PermissionUtils {

    fun hasNotifications(context: Context): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED
        } else {
            NotificationManagerCompat.from(context).areNotificationsEnabled()
        }

    fun hasExactAlarms(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true

        val alarmManager = context.getSystemService(AlarmManager::class.java)
        return alarmManager?.canScheduleExactAlarms() == true
    }

    fun isIgnoringBatteryOptimizations(context: Context): Boolean {
        val power = context.getSystemService(PowerManager::class.java) ?: return true
        return power.isIgnoringBatteryOptimizations(context.packageName)
    }

    /**
     * Can this app show a full-screen notification over the lock screen?
     *
     * This is the permission the whole product depends on — without it the
     * reminder arrives as a quiet notification instead of a ringing call.
     *
     * On Android 14 (API 34) Google stopped granting USE_FULL_SCREEN_INTENT at
     * install time. Only apps whose core purpose is calling or alarms keep it
     * automatically, and the Play Store decides that, not the manifest. For
     * everyone else it has to be asked for at runtime — and an app that never
     * asks simply never rings, with no error anywhere to explain why.
     */
    fun hasFullScreenIntent(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < 34) return true

        val manager = context.getSystemService(NotificationManager::class.java) ?: return false

        return manager.canUseFullScreenIntent()
    }

    fun fullScreenIntentSettingsIntent(context: Context): Intent? {
        if (Build.VERSION.SDK_INT < 34) return null

        return Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT)
            .setData(Uri.parse("package:${context.packageName}"))
    }

    fun allCriticalGranted(context: Context): Boolean =
        hasNotifications(context) &&
            hasExactAlarms(context) &&
            isIgnoringBatteryOptimizations(context) &&
            hasFullScreenIntent(context)

    fun exactAlarmSettingsIntent(context: Context): Intent? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return null

        return Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM)
            .setData(Uri.parse("package:${context.packageName}"))
    }

    @SuppressWarnings("BatteryLife")
    fun batteryOptimizationIntent(context: Context): Intent =
        Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
            .setData(Uri.parse("package:${context.packageName}"))

    fun notificationSettingsIntent(context: Context): Intent =
        Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
            .putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)

    /**
     * Deep link to the OEM autostart screen. These are undocumented and change
     * between ROM versions, so every launch is guarded by resolveActivity.
     */
    fun autostartIntent(context: Context): Intent? {
        val candidates = listOf(
            // Samsung / One UI — the most common phone here, and it was missing.
            // "Sleeping apps" and "Deep sleeping apps" under Battery care will
            // stop alarms and background sync outright, and Samsung puts an app
            // there on its own after a few days of light use.
            "com.samsung.android.lool" to "com.samsung.android.sm.battery.ui.BatteryActivity",
            "com.samsung.android.lool" to "com.samsung.android.sm.ui.battery.BatteryActivity",
            "com.samsung.android.sm_cn" to "com.samsung.android.sm.ui.battery.BatteryActivity",
            "com.samsung.android.sm" to "com.samsung.android.sm.ui.battery.BatteryActivity",
            // Xiaomi / MIUI
            "com.miui.securitycenter" to "com.miui.permcenter.autostart.AutoStartManagementActivity",
            // Oppo / ColorOS
            "com.coloros.safecenter" to "com.coloros.safecenter.permission.startup.StartupAppListActivity",
            "com.coloros.safecenter" to "com.coloros.safecenter.startupapp.StartupAppListActivity",
            "com.oppo.safe" to "com.oppo.safe.permission.startup.StartupAppListActivity",
            // Vivo / FuntouchOS
            "com.vivo.permissionmanager" to "com.vivo.permissionmanager.activity.BgStartUpManagerActivity",
            "com.iqoo.secure" to "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity",
            // Realme
            "com.coloros.safecenter" to "com.coloros.safecenter.permission.startupapp.StartupAppListActivity",
            // Honor / Huawei
            "com.huawei.systemmanager" to "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity",
            "com.huawei.systemmanager" to "com.huawei.systemmanager.optimize.process.ProtectActivity",
            // Letv / Asus
            "com.letv.android.letvsafe" to "com.letv.android.letvsafe.AutobootManageActivity",
            "com.asus.mobilemanager" to "com.asus.mobilemanager.MainActivity"
        )

        for ((pkg, cls) in candidates) {
            val intent = Intent().setClassName(pkg, cls)

            if (context.packageManager.resolveActivity(intent, 0) != null) {
                return intent
            }
        }

        // Samsung and everyone else: the app info screen is the reliable fallback.
        val details = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
            .setData(Uri.parse("package:${context.packageName}"))

        return if (context.packageManager.resolveActivity(details, 0) != null) details else null
    }

    /** Samsung's battery screens have their own wording; name it correctly. */
    fun isSamsung(): Boolean = Build.MANUFACTURER.lowercase().contains("samsung")

    /**
     * What to tell the user once they are on the OEM screen. The deep link can
     * only get them to the right area — the toggle itself is several taps
     * further in and named differently on every ROM.
     */
    fun autostartInstructions(): String = when {
        isSamsung() ->
            "Battery → Background usage limits → make sure Krishna Reminder is NOT in " +
                "\"Sleeping apps\" or \"Deep sleeping apps\", then add it to \"Never sleeping apps\"."
        Build.MANUFACTURER.lowercase().let { it.contains("xiaomi") || it.contains("redmi") || it.contains("poco") } ->
            "Turn on Autostart, and set Battery saver to \"No restrictions\"."
        Build.MANUFACTURER.lowercase().let { it.contains("oppo") || it.contains("realme") || it.contains("oneplus") } ->
            "Allow Auto-launch, and set Battery usage to \"Allow background activity\"."
        Build.MANUFACTURER.lowercase().contains("vivo") ->
            "Allow \"Auto start\" and \"Run in background\" with High background power consumption."
        else ->
            "Allow the app to start automatically and run in the background."
    }

    /** True for manufacturers known to need the autostart step. */
    fun needsAutostartGuidance(): Boolean {
        val manufacturer = Build.MANUFACTURER.lowercase()

        return listOf("xiaomi", "redmi", "poco", "oppo", "vivo", "realme", "oneplus", "samsung", "huawei", "honor", "iqoo", "tecno", "infinix")
            .any { manufacturer.contains(it) }
    }
}
