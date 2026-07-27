package com.akdwk.krishnareminder.data.prefs

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.akdwk.krishnareminder.BuildConfig
import java.util.UUID

/**
 * Encrypted local storage for tokens and preferences.
 *
 * Tokens live here and nowhere else; the login is designed to survive forever
 * (30-day access token, 2-year refresh token, silently renewed), so the user is
 * only ever logged out by tapping Log out or by an admin revoking the device.
 */
class UserPrefs private constructor(private val prefs: SharedPreferences) {

    var baseUrl: String
        get() = prefs.getString(KEY_BASE_URL, null) ?: BuildConfig.DEFAULT_BASE_URL
        set(value) = prefs.edit().putString(KEY_BASE_URL, value.trimEnd('/') + "/").apply()

    var accessToken: String?
        get() = prefs.getString(KEY_ACCESS, null)
        set(value) = prefs.edit().putString(KEY_ACCESS, value).apply()

    var refreshToken: String?
        get() = prefs.getString(KEY_REFRESH, null)
        set(value) = prefs.edit().putString(KEY_REFRESH, value).apply()

    var userId: Int
        get() = prefs.getInt(KEY_USER_ID, 0)
        set(value) = prefs.edit().putInt(KEY_USER_ID, value).apply()

    var userName: String
        get() = prefs.getString(KEY_USER_NAME, "") ?: ""
        set(value) = prefs.edit().putString(KEY_USER_NAME, value).apply()

    var phone: String
        get() = prefs.getString(KEY_PHONE, "") ?: ""
        set(value) = prefs.edit().putString(KEY_PHONE, value).apply()

    var language: String
        get() = prefs.getString(KEY_LANGUAGE, "gu") ?: "gu"
        set(value) = prefs.edit().putString(KEY_LANGUAGE, value).apply()

    var timezone: String
        get() = prefs.getString(KEY_TIMEZONE, java.util.TimeZone.getDefault().id) ?: "Asia/Kolkata"
        set(value) = prefs.edit().putString(KEY_TIMEZONE, value).apply()

    var fcmToken: String?
        get() = prefs.getString(KEY_FCM, null)
        set(value) = prefs.edit().putString(KEY_FCM, value).apply()

    var onboarded: Boolean
        get() = prefs.getBoolean(KEY_ONBOARDED, false)
        set(value) = prefs.edit().putBoolean(KEY_ONBOARDED, value).apply()

    var ttsEnabled: Boolean
        get() = prefs.getBoolean(KEY_TTS, true)
        set(value) = prefs.edit().putBoolean(KEY_TTS, value).apply()

    var ttsSpeed: Float
        get() = prefs.getFloat(KEY_TTS_SPEED, 0.95f)
        set(value) = prefs.edit().putFloat(KEY_TTS_SPEED, value).apply()

    var ringtone: String
        get() = prefs.getString(KEY_RINGTONE, "flute") ?: "flute"
        set(value) = prefs.edit().putString(KEY_RINGTONE, value).apply()

    var theme: String
        get() = prefs.getString(KEY_THEME, "auto") ?: "auto"
        set(value) = prefs.edit().putString(KEY_THEME, value).apply()

    var lastSyncAt: String?
        get() = prefs.getString(KEY_LAST_SYNC, null)
        set(value) = prefs.edit().putString(KEY_LAST_SYNC, value).apply()

    /** Stable per-install id used to identify the device to the server. */
    val deviceUid: String
        get() {
            prefs.getString(KEY_DEVICE_UID, null)?.let { return it }

            val generated = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UID, generated).apply()
            return generated
        }

    val isLoggedIn: Boolean
        get() = !accessToken.isNullOrBlank() && userId > 0

    fun clearSession() {
        prefs.edit()
            .remove(KEY_ACCESS)
            .remove(KEY_REFRESH)
            .remove(KEY_USER_ID)
            .remove(KEY_USER_NAME)
            .remove(KEY_PHONE)
            .remove(KEY_LAST_SYNC)
            .apply()
    }

    companion object {
        private const val FILE = "krishna_secure_prefs"
        private const val KEY_BASE_URL = "base_url"
        private const val KEY_ACCESS = "access_token"
        private const val KEY_REFRESH = "refresh_token"
        private const val KEY_USER_ID = "user_id"
        private const val KEY_USER_NAME = "user_name"
        private const val KEY_PHONE = "phone"
        private const val KEY_LANGUAGE = "language"
        private const val KEY_TIMEZONE = "timezone"
        private const val KEY_FCM = "fcm_token"
        private const val KEY_ONBOARDED = "onboarded"
        private const val KEY_TTS = "tts_enabled"
        private const val KEY_TTS_SPEED = "tts_speed"
        private const val KEY_RINGTONE = "ringtone"
        private const val KEY_THEME = "theme"
        private const val KEY_LAST_SYNC = "last_sync"
        private const val KEY_DEVICE_UID = "device_uid"

        @Volatile
        private var instance: UserPrefs? = null

        fun get(context: Context): UserPrefs = instance ?: synchronized(this) {
            instance ?: UserPrefs(open(context.applicationContext)).also { instance = it }
        }

        private fun open(context: Context): SharedPreferences = try {
            val masterKey = MasterKey.Builder(context)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()

            EncryptedSharedPreferences.create(
                context,
                FILE,
                masterKey,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
            )
        } catch (e: Exception) {
            // Some OEM ROMs have a broken keystore; a working app beats a crash.
            context.getSharedPreferences(FILE + "_plain", Context.MODE_PRIVATE)
        }
    }
}
