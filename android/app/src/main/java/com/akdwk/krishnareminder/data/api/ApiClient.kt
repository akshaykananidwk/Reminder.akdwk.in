package com.akdwk.krishnareminder.data.api

import android.content.Context
import com.akdwk.krishnareminder.BuildConfig
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import okhttp3.Authenticator
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Route
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Retrofit setup with a bearer-token interceptor and a synchronous
 * refresh-token Authenticator, so a 401 transparently renews the session
 * instead of logging the user out.
 */
object ApiClient {

    @Volatile
    private var retrofit: Retrofit? = null

    @Volatile
    private var cachedBaseUrl: String = ""

    fun service(context: Context): ApiService {
        val prefs = UserPrefs.get(context)
        val baseUrl = prefs.baseUrl

        val current = retrofit
        if (current != null && cachedBaseUrl == baseUrl) {
            return current.create(ApiService::class.java)
        }

        synchronized(this) {
            val built = build(context, prefs, baseUrl)
            retrofit = built
            cachedBaseUrl = baseUrl
            return built.create(ApiService::class.java)
        }
    }

    /** Drop the cached client, e.g. after the base URL changes. */
    fun reset() {
        synchronized(this) {
            retrofit = null
            cachedBaseUrl = ""
        }
    }

    private fun build(context: Context, prefs: UserPrefs, baseUrl: String): Retrofit {
        val auth = Interceptor { chain ->
            val builder: Request.Builder = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("X-Device-Id", prefs.deviceUid)

            prefs.accessToken?.takeIf { it.isNotBlank() }?.let {
                builder.header("Authorization", "Bearer $it")
            }

            chain.proceed(builder.build())
        }

        val refresher = Authenticator { _: Route?, response ->
            // Give up after one retry to avoid a refresh loop.
            if (response.request.header("X-Retry") != null) return@Authenticator null

            val refreshToken = prefs.refreshToken ?: return@Authenticator null

            val renewed = renewBlocking(baseUrl, refreshToken) ?: run {
                // The refresh token is dead — the user must log in again.
                prefs.clearSession()
                return@Authenticator null
            }

            prefs.accessToken = renewed.accessToken
            prefs.refreshToken = renewed.refreshToken

            response.request.newBuilder()
                .header("Authorization", "Bearer ${renewed.accessToken}")
                .header("X-Retry", "1")
                .build()
        }

        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BASIC else HttpLoggingInterceptor.Level.NONE
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(auth)
            .addInterceptor(logging)
            .authenticator(refresher)
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()

        return Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
    }

    /**
     * Token refresh has to be synchronous because it runs inside OkHttp's
     * Authenticator, so it uses a bare client of its own.
     */
    private fun renewBlocking(baseUrl: String, refreshToken: String): TokensDto? = try {
        val body = "{\"refresh_token\":\"$refreshToken\"}"
            .toRequestBody("application/json".toMediaType())

        val request = Request.Builder()
            .url(baseUrl + "api/v1/auth/refresh")
            .post(body)
            .header("Accept", "application/json")
            .build()

        val plain = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .build()

        plain.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return@use null

            val json = org.json.JSONObject(response.body?.string().orEmpty())
            val tokens = json.optJSONObject("data")?.optJSONObject("tokens") ?: return@use null

            TokensDto(
                accessToken = tokens.optString("access_token"),
                refreshToken = tokens.optString("refresh_token"),
                expiresAt = tokens.optString("expires_at")
            ).takeIf { it.accessToken.isNotBlank() }
        }
    } catch (e: Exception) {
        null
    }
}
