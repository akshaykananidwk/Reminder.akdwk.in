package com.akdwk.krishnareminder.data.api

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Krishna Reminder mobile API (v1).
 * Base URL is per-install so a self-hosted server can be pointed at.
 */
interface ApiService {

    @POST("api/v1/auth/request-otp")
    suspend fun requestOtp(@Body body: Map<String, String>): Response<ApiEnvelope<OtpResponse>>

    @POST("api/v1/auth/verify-otp")
    suspend fun verifyOtp(@Body body: Map<String, String>): Response<ApiEnvelope<LoginResponse>>

    /**
     * Sign in with the 4-digit PIN set on the website. Needs no OTP, so it
     * still works when the WhatsApp gateway is down.
     */
    @POST("api/v1/auth/pin")
    suspend fun pinLogin(@Body body: Map<String, String>): Response<ApiEnvelope<LoginResponse>>

    @POST("api/v1/auth/refresh")
    suspend fun refresh(@Body body: Map<String, String>): Response<ApiEnvelope<RefreshResponse>>

    @POST("api/v1/auth/logout")
    suspend fun logout(): Response<ApiEnvelope<Any>>

    @GET("api/v1/me")
    suspend fun me(): Response<ApiEnvelope<MeResponse>>

    @PATCH("api/v1/me/settings")
    suspend fun updateSettings(@Body body: Map<String, String>): Response<ApiEnvelope<Any>>

    @POST("api/v1/devices/register")
    suspend fun registerDevice(@Body body: Map<String, String>): Response<ApiEnvelope<Any>>

    @POST("api/v1/devices/heartbeat")
    suspend fun heartbeat(@Body body: Map<String, String>): Response<ApiEnvelope<Any>>

    @GET("api/v1/reminders")
    suspend fun reminders(
        @Query("since") since: String? = null,
        @Query("limit") limit: Int = 200
    ): Response<ApiEnvelope<RemindersResponse>>

    @POST("api/v1/reminders")
    suspend fun createReminder(
        @Body body: Map<String, Any?>,
        @Header("Idempotency-Key") idempotencyKey: String
    ): Response<ApiEnvelope<ReminderDto>>

    @POST("api/v1/reminders/{id}")
    suspend fun updateReminder(
        @Path("id") id: Int,
        @Body body: Map<String, Any?>
    ): Response<ApiEnvelope<ReminderDto>>

    @POST("api/v1/reminders/parse")
    suspend fun parse(@Body body: Map<String, Any?>): Response<ApiEnvelope<ParseResponse>>

    @GET("api/v1/occurrences")
    suspend fun occurrences(
        @Query("from") from: String,
        @Query("to") to: String
    ): Response<ApiEnvelope<OccurrencesResponse>>

    @POST("api/v1/occurrences/{id}/{action}")
    suspend fun occurrenceAction(
        @Path("id") id: Int,
        @Path("action") action: String,
        @Body body: Map<String, Any?>
    ): Response<ApiEnvelope<Any>>

    @GET("api/v1/dashboard/stats")
    suspend fun dashboard(): Response<ApiEnvelope<DashboardStats>>

    @GET("api/v1/payments")
    suspend fun payments(): Response<ApiEnvelope<PaymentsResponse>>

    @POST("api/v1/payments/{id}/pay")
    suspend fun payPayment(
        @Path("id") id: Int,
        @Body body: Map<String, Any?>
    ): Response<ApiEnvelope<Any>>

    @GET("api/v1/notes")
    suspend fun notes(): Response<ApiEnvelope<List<NoteDto>>>

    @POST("api/v1/notes")
    suspend fun createNote(@Body body: Map<String, String>): Response<ApiEnvelope<Any>>

    @GET("api/v1/sync/pull")
    suspend fun syncPull(@Query("since") since: String?): Response<ApiEnvelope<SyncPullResponse>>

    @POST("api/v1/sync/push")
    suspend fun syncPush(@Body body: Map<String, Any?>): Response<ApiEnvelope<SyncPushResponse>>

    @GET("api/v1/app/version")
    suspend fun appVersion(): Response<ApiEnvelope<AppVersionDto>>

    @POST("api/v1/test/call")
    suspend fun testCall(@Body body: Map<String, String> = emptyMap()): Response<ApiEnvelope<Any>>
}
