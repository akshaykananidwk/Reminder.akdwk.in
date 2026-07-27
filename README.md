# Krishna Reminder

**WhatsApp + AI powered reminder, task and payment manager.**
Send a plain Gujarati, Hindi or English message on WhatsApp — your Android phone rings like a real call at the right minute and speaks the reminder out loud in your own language.

> Built by **AK Computer**, Shreeji Shopping Center, near City Palace Hotel, Dwarka, Gujarat · +91 99781 23146
> 🙏 જય શ્રી કૃષ્ણ

---

## What it does

| | |
|---|---|
| 💬 **Write naturally** | *"કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે"* → Gemini extracts date, time, repetition, amount and category |
| 📞 **Real ringing call** | Full-screen call over the lock screen with ringtone and vibration — three attempts, two minutes apart |
| 🗣️ **Speaks your language** | On-device TTS reads the reminder twice in Gujarati / Hindi / English, with a server-MP3 fallback |
| 💰 **Payment ledger** | Party, amount, due date, partial payments, receivable/payable totals, EMI series |
| 🌙 **Daily summaries** | Morning brief and night report on WhatsApp and in the app, at each user's own local time |
| 📅 **Google Calendar** | Optional two-way sync — and everything works perfectly without it |
| 📶 **Works offline** | Every occurrence also gets a local exact alarm; actions queue and sync when the network returns |
| 🔒 **Whitelist only** | Inbound messages are processed only from a verified, active number on an active account |

---

## Architecture

```
 WhatsApp user ──▶ bulk.akdwk.in (gateway)
                       │ outbound webhook
                       ▼
   ┌───────────────────────────────────────────────────────┐
   │  reminder.akdwk.in — PHP 8.1+ / MySQL, aaPanel        │
   │                                                       │
   │  api/wa_webhook.php  whitelist → raw store → queue    │
   │  cron/ai_queue.php   Gemini parse → reminders         │
   │  cron/dispatcher.php (60 s) due occurrences           │
   │       ├─▶ FCM high-priority data push (CALL)          │
   │       ├─▶ WhatsApp outbound queue                     │
   │       └─▶ in-app + web push notification              │
   │  cron/ morning_brief · daily_summary · recurrence     │
   │  cron/ google_sync · subscriptions · backup · cleanup │
   │                                                       │
   │  /client (dashboard)  /admin (owner)  /install        │
   │  /api/v1 (mobile)     /cron.php (web-cron fallback)   │
   └───────────────────────────────────────────────────────┘
                       │ FCM
                       ▼
     Android app (Kotlin, Compose) — full-screen call + TTS
                       │
                       ▼
       Google Calendar / Tasks (optional, per user)
```

No Laravel, no Node, no Redis, no Docker, no Composer. Plain PHP 8 + cURL + PDO, so it runs on any shared host with PHP-CLI cron.

---

## Repository layout

```
/                     index.php · cron.php · sw.js · .htaccess · robots.txt
/config               config.sample.php  (config.php is written by the installer)   [denied]
/app
   core/              App Database Router Auth Session Csrf Crypto Lang Validator …
   controllers/       HomeController AuthController client/* admin/*
   services/          WhatsApp Gemini FallbackParser Reminder Recurrence Scheduler
                      Fcm Google Tts Update Backup Payment Report Invoice Cron …
   views/             layouts/ public/ auth/ client/ admin/ errors/
/api                  v1/index.php · wa_webhook.php · health.php
/cron                 dispatcher ai_queue wa_queue recurrence google_sync
                      morning_brief daily_summary subscriptions backup cleanup
/database             schema.sql · seed.sql · migrations/
/install              one-click setup wizard
/assets               css/ js/ img/
/lang                 gu.php · hi.php · en.php
/storage /uploads     runtime data                                        [denied / no exec]
/android              Kotlin + Jetpack Compose app
/.github/workflows    android.yml · php-lint.yml
```

---

## Install

Upload the ZIP, extract it, open **`/install`**. That is the whole procedure — the wizard writes `config/config.php` itself, imports the schema, seeds the data, live-tests the WhatsApp gateway and Gemini, and prints the cron lines.

Full instructions: **[INSTALL.md](INSTALL.md)**

---

## The WhatsApp contract

Outbound (always POST + JSON — the key must never appear in a URL):

```http
POST {WA_ENDPOINT}/api.php
Content-Type: application/json

{ "api_key": "…", "session_id": "…", "number": "919876543210", "message": "Hello" }
```

Inbound — point the gateway's outbound webhook at:

```
https://reminder.akdwk.in/api/wa_webhook.php?secret=XXXXXXXX
```

The handler verifies the secret (or an HMAC-SHA256 signature), answers `200` in well under a second, then processes asynchronously: normalise the number → whitelist check → deduplicate by gateway message id → store raw → queue for AI.

### Quick commands (instant, zero AI cost)

`LIST` · `યાદી` — today · `TODAY` · `આજે` · `PENDING` · `બાકી`
`DONE A12` · `થઈ ગયું A12` · `SNOOZE A12 10` · `CANCEL A12` · `PAID A12 5000`
`SUMMARY` · `LANG GU|HI|EN` · `STOP` / `START` · `HELP` · `મદદ`

Anything else goes to Gemini.

---

## Cost control

Runaway AI spend is the failure mode that kills products like this, so it is engineered against on five levels:

1. A deliberately **short system prompt** — never a knowledge base.
2. `response_mime_type: application/json`, temperature 0.2, capped `max_output_tokens`.
3. **24-hour cache** on identical message hashes.
4. **Per-user monthly quotas** (messages *and* tokens) enforced by plan; on exceed the built-in regex parser takes over and the user is told once.
5. A **global monthly token budget with a hard stop**, plus a per-user cost report in the admin panel to find the runaway account.

Every call logs `prompt_tokens`, `completion_tokens`, model, latency and computed cost to `ai_logs`.

---

## Reliability

- **Local exact alarms** for every synced occurrence (`setExactAndAllowWhileIdle`) — the phone rings with no network, no push and no server.
- **Boot / update / timezone / clock-change receivers** re-arm the whole alarm set.
- **WorkManager** reconciles server ↔ device every 15 minutes.
- **Offline action queue** with client ids; the server deduplicates, so an offline "Done" is never lost and never applied twice.
- **Graceful degradation**: Gemini down → regex parser · FCM down → WhatsApp · WhatsApp down → in-app. The user is always told something.
- `flock` on every cron job, `cron_runs` accounting, and a WhatsApp alert to the owner when a job stops running.

---

## Security

PDO prepared statements everywhere · CSRF on every POST · output escaping · Argon2id/bcrypt passwords · login throttling and lockout · OTP rate limits with attempt caps · HMAC or shared secret on the webhook · API keys and OAuth tokens encrypted at rest (AES-256-GCM) · secure uploads (MIME + extension whitelist, random names, no execution in `/uploads`) · CSP and the usual security headers · full audit log · data export and account deletion for users.

**Backups are written outside the document root** (`../kr-backups`, falling back to a deny-all `/backups`). Nothing with a `.zip`, `.sql` or `.log` extension is reachable over HTTP — enforced in `.htaccess`, again by the backup service, and again by a CI check.

---

## Android app

Kotlin · Jetpack Compose (Material 3) · MVVM · Retrofit + OkHttp with silent token refresh · Room · WorkManager · FCM · AlarmManager · TextToSpeech · min SDK 24.

Build locally:

```bash
cd android
gradle wrapper --gradle-version 8.7   # first time only
./gradlew assembleDebug
```

CI builds and publishes a signed APK to GitHub Releases on every tag — see [`.github/workflows/android.yml`](.github/workflows/android.yml). Firebase is optional: without `google-services.json` the app still builds and every reminder still arrives via local alarms and WhatsApp.

---

## Documentation

| File | What is in it |
|---|---|
| [INSTALL.md](INSTALL.md) | Fresh install on aaPanel, cron, Firebase, Google OAuth, updates |
| [API.md](API.md) | Mobile API v1 reference, webhook contract, personal API |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [VERIFICATION-REPORT.md](VERIFICATION-REPORT.md) | What was tested, how, and what still needs the live environment |

---

## Licence

Proprietary — © AK Computer, Dwarka, Gujarat.
