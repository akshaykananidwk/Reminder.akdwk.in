# Krishna Reminder — API reference

Base URL: `https://reminder.akdwk.in`

Every response uses the same envelope:

```json
{ "success": true, "data": { }, "message": "", "code": "OK" }
```

HTTP status carries the real outcome; `code` is a stable machine-readable string
(`OK`, `VALIDATION_FAILED`, `UNAUTHENTICATED`, `QUOTA_EXCEEDED`, `RATE_LIMITED`,
`NOT_FOUND`, `MAX_SNOOZES`, `DEVICE_LIMIT`, `SERVER_ERROR`).

---

## 1. Mobile API v1 — `/api/v1`

### Authentication

WhatsApp-OTP login. The access token lasts 30 days, the refresh token 2 years,
and OkHttp renews silently on a `401` — so the app stays logged in indefinitely
unless the user taps Log out or an admin revokes the device.

Send `Authorization: Bearer <access_token>` on every authenticated call.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| POST | `/auth/request-otp` | `phone` | Never reveals whether the number exists. 60 s cooldown, 5/hour per number, 15/hour per IP. |
| POST | `/auth/verify-otp` | `phone`, `code`, `device_uid`, `fcm_token`, `model`, `manufacturer`, `os_version`, `app_version` | Returns tokens + user, and registers the device in the same call. |
| POST | `/auth/refresh` | `refresh_token` | Rotates both tokens. |
| POST | `/auth/logout` | — | Revokes this session only. |

```jsonc
// POST /api/v1/verify-otp  →  200
{
  "success": true,
  "data": {
    "tokens": { "access_token": "…", "refresh_token": "…", "expires_at": "2026-08-26T…" },
    "user": { "id": 12, "name": "Bharat", "phone": "919978123146", "language": "gu", "timezone": "Asia/Kolkata", "streak": 7 },
    "device_id": 4
  },
  "message": "Welcome back, Bharat!",
  "code": "OK"
}
```

### Account

| Method | Path | Notes |
|---|---|---|
| GET | `/me` | User, settings, plan and this month's usage |
| POST · PATCH | `/me` | `name`, `email`, `city`, `language`, `timezone` |
| GET | `/me/settings` | Full `user_settings` row |
| POST · PATCH | `/me/settings` | Any of: brief/summary times, DND window, snooze defaults, call attempts, ring seconds, ringtone, TTS, per-event WhatsApp toggles, theme, holiday mode |

### Devices

| Method | Path | Notes |
|---|---|---|
| POST | `/devices/register` | `device_uid` (required) + model/manufacturer/os/app version. Enforces the plan's device limit. |
| POST | `/devices/heartbeat` | Refreshes `last_seen_at` and the FCM token |
| GET | `/devices` | Linked devices and push status |

### Reminders

| Method | Path | Notes |
|---|---|---|
| GET | `/reminders` | `?since=` for delta sync (includes soft-deleted rows so the client can drop them), `?status=`, `?limit=` |
| POST | `/reminders` | **Requires `Idempotency-Key`.** Replays return the identical stored response. |
| GET | `/reminders/{id}` | Reminder + occurrences + response timeline |
| POST · PUT · PATCH | `/reminders/{id}` | Partial update; changing the time rebuilds future occurrences |
| DELETE | `/reminders/{id}` | Soft delete (30-day trash) |
| POST | `/reminders/parse` | `text`, `create` (bool) — natural language → structured, optionally created |

```jsonc
// POST /api/v1/reminders   Idempotency-Key: 7c1f…
{
  "title": "બેંકમાં ચેક જમા કરાવવો",
  "due_at": "2026-07-28T10:00:00+05:30",
  "type": "task",
  "priority": "high",
  "call_reminder": true,
  "advance_alerts": [60, 10],
  "recurrence": { "freq": "weekly", "interval": 1, "by_day": ["MO"] },
  "amount": null,
  "person_name": null
}
```

### Occurrences

| Method | Path | Body |
|---|---|---|
| GET | `/occurrences` | `?from=`&`?to=` (ISO or UTC datetime) |
| POST | `/occurrences/{id}/done` | `note?`, `device_uid?` |
| POST | `/occurrences/{id}/snooze` | `minutes?` (defaults to the reminder's own default) — `409 MAX_SNOOZES` past the limit |
| POST | `/occurrences/{id}/reschedule` | `due_at` |
| POST | `/occurrences/{id}/cancel` | — |
| POST | `/occurrences/{id}/dismiss` | Records the response without resolving the reminder |

### Data

| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard/stats` | Counters, payment totals, today's list, next occurrence |
| GET | `/summary/{date\|today}` | Night summary text and counts |
| GET | `/reports` | `?from=`&`?to=` — totals, category and hour breakdown, average delay |
| GET · POST | `/payments`, `/payments/{id}/pay` | Ledger and partial payments |
| GET · POST | `/notes` | Notes captured from WhatsApp |
| GET · POST | `/contacts` | Contacts and staff |
| GET | `/categories` | System + user categories |

### Offline sync

| Method | Path | Notes |
|---|---|---|
| GET | `/sync/pull` | `?since=` — changed reminders, occurrences and settings, plus `server_time` |
| POST | `/sync/push` | Batch replay of offline actions |

```jsonc
// POST /api/v1/sync/push
{
  "actions": [
    { "client_id": "b0c2…", "type": "done",   "occurrence_id": 8412 },
    { "client_id": "9ad4…", "type": "snooze", "occurrence_id": 8419, "minutes": 15 }
  ]
}
// → { "results": [ { "client_id": "b0c2…", "status": "ok" },
//                  { "client_id": "9ad4…", "status": "duplicate" } ] }
```

`client_id` makes replays safe: a duplicate is acknowledged, never re-applied.

### Misc

| Method | Path | Notes |
|---|---|---|
| GET | `/health` | Liveness |
| GET | `/app/version` | Latest version, minimum version, APK URL, release notes, force flag |
| POST | `/tts` | `text` → server-generated MP3 URL (when Cloud TTS is configured) |
| POST | `/attachments` | Multipart `file` (+ optional `reminder_id`) |
| POST | `/test/call` | Rings every device of the caller |

### Rate limits

600 requests / 5 minutes per IP. Exceeding it returns `429 RATE_LIMITED`.

---

## 2. Inbound WhatsApp webhook

```
POST /api/wa_webhook.php?secret=XXXXXXXX
```

Authentication is the shared secret in the query string **or** an
`X-Signature: sha256=<hmac>` header over the raw body using the same secret.
Anything else gets `401` and is logged.

Processing order:

1. Verify the secret / signature.
2. Answer `200` immediately (`fastcgi_finish_request`), then work asynchronously.
3. Normalise the number — strips `+`, spaces, dashes, a leading `0`, the
   `whatsapp:` prefix and `@c.us` / `@s.whatsapp.net` suffixes, and adds the
   country code (default 91).
4. **Whitelist**: the number must match a verified, active `whatsapp_numbers`
   row on an active account. Otherwise it goes to `unknown_inbound`, gets at
   most one signup invite per 7 days, and stops.
5. Deduplicate by gateway message id.
6. Store the raw payload in `wa_inbound_raw` and queue an `ai_queue` job.

The gateway's field names vary, so the parser accepts `from`/`sender`/`number`/
`phone`/`wa_id`/`chatId`/`remoteJid`, `message`/`body`/`text`/`content`, and one
level of `data`/`message`/`payload` nesting.

## 3. Outbound WhatsApp

```http
POST {WA_ENDPOINT}/api.php
Content-Type: application/json

{ "api_key": "…", "session_id": "…", "number": "919876543210", "message": "…", "media_url": "…" }
```

Always POST + JSON — the key must never appear in a URL, where it would leak
into access logs and browser history. Everything is queued through
`wa_outbound_queue`, sent at the configured rate, retried three times with
exponential backoff (1, 4, 9 minutes) and logged with a request id.

---

## 4. Personal API (Business plan)

Users generate a key in **Integrations**. It is shown once and stored as a
SHA-256 hash.

Their webhooks receive:

```http
POST https://your-app.example.com/hook
X-Krishna-Event: reminder.due
X-Krishna-Signature: <hmac-sha256 of the raw body with the webhook secret>

{ "event": "reminder.due", "data": { "occurrence_id": 8412, "short_code": "A12", … }, "sent_at": "…" }
```

Events: `reminder.created`, `reminder.due`, `reminder.done`, `reminder.missed`.

---

## 5. FCM payloads (server → app)

All pushes are high-priority **data** messages so the app always gets control.

```jsonc
// type=call — start the ringing call
{
  "type": "call", "occurrence_id": "8412", "reminder_id": "1204", "short_code": "A12",
  "title": "ઓફિસ સ્ટાફને કોલ કરવાનો", "reminder_type": "call", "priority": "high",
  "due_at": "2026-07-28 04:30:00", "amount": "", "currency": "INR",
  "language": "gu", "speech": "નમસ્તે … થઈ ગયું દબાવો.",
  "ringtone": "flute", "ring_seconds": "45", "snooze_minutes": "5",
  "tts_enabled": "1", "tts_speed": "1.0", "attempt": "1", "ignore_dnd": "0"
}
```

| `type` | Effect in the app |
|---|---|
| `call` | Foreground service + full-screen call + TTS |
| `advance` | Silent notification ("in 30 min …") |
| `occurrence_resolved` | Another device answered — stop ringing here and re-sync |
| `broadcast` | Announcement notification |
| `sync` | Silent nudge to re-sync |

---

## 6. Health

```
GET /api/health.php
GET /api/health.php?full=1&token=<CRON_TOKEN>
```

The full form reports database connectivity, stale cron jobs, WhatsApp gateway
health, FCM configuration, queue depth, free disk, and any archive exposed in
the web root.
