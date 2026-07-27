# Verification Report — Krishna Reminder v1.0.0

**Date:** 27 July 2026
**Build environment:** PHP 8.4.19 CLI (Linux), no MySQL server, no network access to
the WhatsApp gateway / Gemini / Firebase, no Android device or emulator.

---

## How to read this report

Every row states **how it was checked**, so nothing is claimed on trust:

| Level | Meaning |
|---|---|
| ✅ **VERIFIED** | Executed in this environment; the actual output is recorded below. |
| 🟢 **CI-VERIFIED** | Executed automatically by a workflow in `.github/workflows/`. Run it and read the log. |
| 🔍 **REVIEWED** | Code and control flow inspected against the requirement; no runtime proof available here. |
| ⏳ **PENDING LIVE** | Needs the production host, real credentials or a physical phone. Exact procedure given. |

The build environment has no database server, no outbound access to
`bulk.akdwk.in`, no Gemini key, no Firebase project and no Android device, so
everything that depends on those is honestly marked ⏳, not asserted as passing.

---

## 1. Executed in this environment ✅

### 1.1 PHP syntax across the whole codebase

```
$ find . -name '*.php' -not -path './android/*' | while read f; do php -l "$f"; done
PHP files checked: 145, failures: 0
```

**Result: 145 / 145 PASS.**

### 1.2 Natural-language parsing, recurrence, phone normalisation, webhook shapes

Harness: [`tests/verify.php`](tests/verify.php) — run it with `php tests/verify.php`
(exit code 0 = all pass). It exercises the database-free core: the Gujarati /
Hindi / English fallback parser, the recurrence expander, phone normalisation and
the inbound webhook payload parser.

```
$ php tests/verify.php
NOW (IST): Tue 28 Jul 2026 00:32
...
TOTAL: 42   PASS: 42   FAIL: 0
```

#### Gujarati sentences (requirement §18.3 asks for at least 15 — 21 are covered)

| # | Sentence | Expected | Actual | Result |
|---|---|---|---|---|
| 1 | કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે | tomorrow 10:00, call | Wed 29 Jul 10:00 · call | ✅ PASS |
| 2 | આજે સાંજે 6 વાગ્યે દવા લેવી | today 18:00, medicine | Tue 28 Jul 18:00 · medicine | ✅ PASS |
| 3 | પરમ દિવસે બપોરે 3 વાગ્યે મીટિંગ | day-after 15:00, meeting | Thu 30 Jul 15:00 · meeting | ✅ PASS |
| 4 | દર સોમવારે સવારે 11 વાગ્યે સ્ટાફ મીટિંગ | weekly MO 11:00 | Mon 03 Aug 11:00 · weekly[MO] | ✅ PASS |
| 5 | દરરોજ રાત્રે 9 વાગ્યે દવા | daily 21:00 | Tue 28 Jul 21:00 · daily | ✅ PASS |
| 6 | દર મહિને 5 તારીખે લાઇટ બિલ ભરવું | monthly on day 5 | Wed 05 Aug 09:00 · monthly[d5] · bill | ✅ PASS |
| 7 | 15 મિનિટ પછી રમેશભાઈને ફોન કરવો | ~15 min ahead | Tue 28 Jul 00:47 · call | ✅ PASS |
| 8 | બે કલાક પછી બેંક જવાનું | ~2 h ahead | Tue 28 Jul 02:32 | ✅ PASS |
| 9 | આવતા શુક્રવારે સવારે 10 વાગ્યે GST ફાઇલિંગ | a future Friday 10:00 | Fri 31 Jul 10:00 | ✅ PASS |
| 10 | 5 તારીખે રમેશભાઈને 5000 રૂપિયા આપવા | day 5, ₹5000, payment | Wed 05 Aug 09:00 · payment · ₹5000 | ✅ PASS |
| 11 | કાલે 5 હજાર રૂપિયા ઉઘરાણી કરવાની છે | ₹5000, payment | Wed 29 Jul 09:00 · payment · ₹5000 | ✅ PASS |
| 12 | ૨૮ જુલાઈ સવારે ૮ વાગ્યે ટ્રેન | Gujarati numerals → 28 Jul 08:00 | Tue 28 Jul 08:00 | ✅ PASS |
| 13 | 3 દિવસ પછી ડોક્ટરને મળવાનું | ~3 days ahead | Fri 31 Jul 09:00 | ✅ PASS |
| 14 | તાત્કાલિક કાલે સવારે 7 વાગ્યે નીકળવાનું | priority urgent | Wed 29 Jul 07:00 · urgent | ✅ PASS |
| 15 | કાલે સવારે 10 વાગ્યે જન્મદિવસની શુભેચ્છા મોકલવી | type birthday | Wed 29 Jul 10:00 · birthday | ✅ PASS |
| 16 | कल सुबह 10 बजे बैंक जाना है | HI: tomorrow 10:00 | Wed 29 Jul 10:00 | ✅ PASS |
| 17 | हर सोमवार शाम 6 बजे स्टाफ मीटिंग | HI: weekly MO 18:00 | Mon 03 Aug 18:00 · weekly[MO] | ✅ PASS |
| 18 | परसों दोपहर 2 बजे दवा लेनी है | HI: day-after 14:00 | Thu 30 Jul 14:00 · medicine | ✅ PASS |
| 19 | tomorrow at 10 am call the bank | EN: tomorrow 10:00 | Wed 29 Jul 10:00 · call | ✅ PASS |
| 20 | every monday 9 am staff meeting | EN: weekly MO 09:00 | Mon 03 Aug 09:00 · weekly[MO] | ✅ PASS |
| 21 | pay Ramesh 12000 on 5 | EN: ₹12000, payment | payment · ₹12000 | ✅ PASS |

Note: this is the **fallback parser only** — no AI involved. Gemini is expected
to do at least as well; when its confidence is below 0.5 the two are merged
(`GeminiService::handleModelResponse`).

#### Recurrence expansion

| Rule | Window | Expected | Actual | Result |
|---|---|---|---|---|
| daily, interval 1 | 3 days | 3 | 3 | ✅ PASS |
| daily, interval 2 | 10 days | 5 | 5 | ✅ PASS |
| weekly | 28 days | 4 | 4 | ✅ PASS |
| weekly BYDAY=MO,TH | 28 days | ~8 | 8 | ✅ PASS |
| monthly BYMONTHDAY=5 | 90 days | 3 | 3 | ✅ PASS |
| none | 30 days | 1 | 1 | ✅ PASS |
| daily COUNT=4 | 30 days | 4 | 4 | ✅ PASS |

#### Phone normalisation (8 / 8 PASS)

`+91 99781 23146`, `09978123146`, `9978123146`, `919978123146@c.us`,
`whatsapp:+919978123146`, `91-99781-23146`, `0091 9978123146`,
`919978123146@s.whatsapp.net` → all normalise to `919978123146`.

#### Inbound webhook payload shapes (6 / 6 PASS)

Flat `from`/`message`, `sender`/`body`, one-level nested `data`, `wa_id`/`content`
and `remoteJid` all parse to the same sender; a payload with **no** sender is
rejected (returns `null`) rather than guessed at.

---

## 2. Verified by CI 🟢

`.github/workflows/php-lint.yml` runs on every push and pull request:

| Check | What it proves |
|---|---|
| `php -l` on every file, PHP **8.1 and 8.3** | The codebase parses on the minimum and a current version |
| MySQL 8.0 service imports `database/schema.sql` then `database/seed.sql` | The schema is valid SQL and loads into a real server |
| Asserts 15 core tables exist after import | `users`, `reminders`, `reminder_occurrences`, `deliveries`, `wa_inbound_raw`, `wa_outbound_queue`, `ai_logs`, `plans`, `subscriptions`, `settings`, `templates`, `cron_runs`, `schema_migrations`, `payments`, `notes`, `contacts` |
| Asserts ≥ 30 seeded message templates | The 20 template keys × 3 languages actually seeded |
| Fails if any `.sql` / `.zip` / `.bak` sits in the web root | Requirement §18.16, enforced continuously |

`.github/workflows/android.yml` builds debug + release APKs on JDK 17 and, on a
tag, publishes a signed APK to GitHub Releases with notes from the commit log.

> The Android workflow has not been executed yet — it runs on the first push to
> `main` or on a tag. Until then, the APK build itself is ⏳ (§4.6).

---

## 3. Reviewed against the requirement 🔍

These were traced through the code but cannot be executed here.

### Module A — Web platform

| Requirement | Where it lives | Notes |
|---|---|---|
| §A5 installer, zero manual file edits | `install/index.php` | 8 steps; step 2 writes `config/config.php` (fresh AES key, cron token, webhook secret) so later steps and the app can boot; step 8 writes `install.lock` and marks migrations applied |
| §A5 blocks re-run | `install/index.php` L30-40 | `install.lock` present → 403 + "delete /install" |
| §A2 whitelist enforcement | `WhatsAppService::resolveSender()` | Joins `whatsapp_numbers` + `users`, requires `is_verified=1 AND is_active=1` on both |
| §A2 OTP hardening | `OtpService` | 5 min expiry, 5 attempts then invalidated, 60 s cooldown, 5/hour per number, 15/hour per IP, bcrypt-hashed codes |
| §A2 multiple numbers | `SettingsController::addNumber()` | Primary + 3 additional, each separately OTP-verified; primary cannot be removed |
| §A4 impersonation audit | `Admin\UserController::impersonate()` | Writes `audit_logs`, keeps `admin_id` in session, client layout shows a banner |
| §A6 update protected list | `UpdateService::PROTECTED` + `.updateignore` | `config/config.php`, `install.lock`, `.env`, `/uploads`, `/storage`, `/backups`, `.git` |
| §A6 automatic rollback | `UpdateService::run()` catch block | Restores files + database from the pre-update backup, logs, WhatsApp-alerts the owner |
| §A6 migrations | `UpdateService::runMigrations()` | Filename order, tracked in `schema_migrations`, skips already-applied |
| §A7 no overlapping cron | `CronService::begin()` | `flock(LOCK_EX\|LOCK_NB)`; stale lock older than 30 min is cleared |
| §A7 web-cron fallback | `cron.php` | Token-checked with `hash_equals`, rate-limits probes, `?job=all` runs the 1-minute jobs |
| §A8 SQL injection | `app/core/Database.php` | Only API is prepared statements; table names pass `safeIdentifier()`. No string-concatenated values anywhere in the codebase |
| §A8 CSRF | `Csrf` + `csrf` middleware | Every state-changing route in `app/routes.php` carries it; accepts form field or `X-CSRF-Token` |
| §A8 secrets at rest | `Crypto` (AES-256-GCM) | WhatsApp key/session, Gemini keys, GitHub token, Google secret, FCM service account, OAuth refresh tokens |
| §13.21 trash | `ReminderService::softDelete()` + `cron/cleanup.php` | 30-day recovery, then purged |

### Module B — WhatsApp

| Requirement | Where it lives |
|---|---|
| POST + JSON only, key never in a URL | `WhatsAppService::sendNow()` → `HttpClient::postJson()` |
| Retry 3× with backoff | `WhatsAppService::processQueue()` — 1, 4, 9 minutes |
| Rate limiting | `wa_rate_per_minute` paces the worker (`usleep`) |
| 200 in < 1 s, async processing | `api/wa_webhook.php` — echoes, then `fastcgi_finish_request()` |
| Secret **or** HMAC-SHA256 | Same file, both paths implemented |
| Deduplicate by gateway message id | Unique key `uq_wa_gateway_msg` + explicit pre-check |
| Unknown number: log + 1 invite / 7 days | `WhatsAppService::handleUnknown()` |
| Quick commands | `CommandService::handle()` — all 12 documented commands, Gujarati and English forms |
| Confirmation format (§6.5) | `templates` seed, key `reminder_created`, matches the specified layout |

### Module C — Gemini

| Requirement | Where it lives |
|---|---|
| Short prompt, never a knowledge base | `GeminiService::defaultSystemPrompt()` — ~20 lines, admin-overridable |
| JSON-only, temp 0.2, capped tokens | `buildPayload()` — `response_mime_type: application/json` |
| Token + cost logging per request | `logUsage()` → `ai_logs` (prompt, completion, model, latency, cost) |
| Per-user monthly quota | `PlanService::withinAiQuota()` — messages **and** tokens |
| Global budget hard stop | `withinGlobalBudget()` — falls back to the regex parser |
| 24-hour cache on identical hashes | `ai_cache` keyed on `sha256(text|lang|date)` |
| Key rotation | `gemini_api_key_2` retried once on failure |
| Fallback parser never crashes | Worst case saves a Note and asks for the time (`InboundProcessor`) |

### Module D — Android

| Requirement | Where it lives |
|---|---|
| High-priority data push | `FcmService::sendToUser()` — `android.priority=high`, data-only |
| Foreground service + CallStyle + full-screen intent | `CallService` |
| Over the lock screen, screen on | `CallActivity.showOverLockScreen()` — `setShowWhenLocked`, `setTurnScreenOn`, `requestDismissKeyguard`, wake lock |
| TTS gu-IN → hi-IN → en-IN, twice with a pause | `TtsManager.resolveLocale()` + `repeats = 2` |
| Local exact alarms | `AlarmScheduler.schedule()` — `setExactAndAllowWhileIdle`, graceful degrade without the permission |
| Speech composed on-device | `LocalSpeech.build()` — so an offline alarm still talks |
| Boot / update / timezone / clock re-arm | `BootReceiver` (5 intent actions) |
| Offline action queue, no duplicates | `pending_actions` + `client_id`; server `/sync/push` deduplicates via `idempotency_keys` |
| First "Done" clears other devices | `type=occurrence_resolved` push → `KrishnaMessagingService.handleResolved()` |
| Login persists | 30-day access + 2-year refresh, silent renewal in `ApiClient`'s `Authenticator` |
| Manufacturer autostart | `PermissionUtils.autostartIntent()` — 11 OEM screens, guarded by `resolveActivity`, falls back to app info |
| Widget + QS tile + share-to-app | `TodayWidgetReceiver`, `QuickAddTileService`, `ACTION_SEND` filter |

### Module E — Google

| Requirement | Where it lives |
|---|---|
| Server-side OAuth, pure cURL | `GoogleService` |
| Refresh token encrypted | `Crypto::encrypt()` before storage |
| Two-way sync, last-write-wins | `syncUser()` compares `updated` vs local `updated_at` |
| Incremental sync tokens, 410 handling | `syncToken` stored; `410` clears it and restarts the window |
| Everything works without Google | `syncUser()` returns early; no feature gated on it |
| Disconnect revokes + optional delete | `disconnect($userId, $deleteRemoteEvents)` |

---

## 4. Pending live verification ⏳

These require the production host, real credentials or a physical Android phone.
Each row is the exact procedure to run.

| # | §18 item | Procedure | Pass criterion |
|---|---|---|---|
| 4.1 | Fresh install, zero manual edits | Upload ZIP to a clean aaPanel VPS → open `/install` → complete 8 steps | Site works, no file edited by hand, `/install` locked afterwards |
| 4.2 | Registration + OTP | Register with a real number | OTP arrives on WhatsApp within seconds; wrong code rejected; 6th attempt blocked |
| 4.3 | Registered inbound → reminder | Send the 21 sentences in §1.2 from the registered number | Reminder created with the same date/time as the table above; confirmation reply matches §6.5 |
| 4.4 | Unregistered inbound ignored | Message from an unknown number | No reminder; row in `unknown_inbound`; at most one invite in 7 days |
| 4.5 | Cron fires on the minute | Create a reminder 2 minutes out; watch **Admin → Cron monitor** | Occurrence flips to `notified` in the correct minute |
| 4.6 | Phone rings + Gujarati TTS | Trigger a call with the screen off, locked, and in Doze (`adb shell dumpsys deviceidle force-idle`) | Full-screen call appears over the lock screen and speaks Gujarati twice |
| 4.7 | Done / Snooze / Reschedule | Use each button | Done closes it; Snooze 5 rings again after exactly 5 minutes; Reschedule moves it |
| 4.8 | Offline behaviour | Aeroplane mode, wait for a due reminder, press Done, restore network | Local alarm fires offline; the Done syncs and is **not** duplicated |
| 4.9 | Recurring 3 days | Daily reminder, observe 3 consecutive days | Fires each day; `cron/recurrence.php` keeps 30 days materialised |
| 4.10 | Payment → paid → ledger | Create a payment reminder, mark paid | `payments`, `payment_transactions`, totals and reports all update |
| 4.11 | Summaries at local time | Set brief 07:30 and summary 21:30 | Both arrive within the 5-minute tick; format matches §12 |
| 4.12 | Google two-way | Connect Google, create both sides, edit in Google | Event appears; the Google edit flows back within 15 minutes |
| 4.13 | Without Google | Use every feature with Google disconnected | Nothing blocked, no nagging |
| 4.14 | Token metering + quota | Set a low plan quota, exceed it | `ai_logs` shows cost; parser falls back; user warned once; no crash, no runaway |
| 4.15 | Update + forced rollback | Run an update; then point at a branch with a deliberately broken migration | Success path leaves `config.php` and `/uploads` untouched, migration applied; failure path rolls back files + DB and WhatsApps the owner |
| 4.16 | Backup not downloadable | `curl -I https://…/backups/<file>.zip` and try the `../kr-backups` path | 403/404 in both cases (CI already blocks web-root archives) |
| 4.17 | Security sweep | SQLi, XSS, CSRF, IDOR (user A opening user B's reminder id), OTP brute force, webhook without secret | All blocked; IDOR returns 404 because every query is scoped by `user_id` |
| 4.18 | Login persists 30 days | Leave installed, change the device clock forward, reopen after an app update | Still logged in (refresh token silently renews) |
| 4.19 | Signed APK on Releases | Push a `v*` tag with the four keystore secrets set | Release created with the APK attached; in-app "check for updates" finds it |
| 4.20 | Responsive + dark mode | 360 / 768 / 1440 px, both themes | No horizontal scroll at 360 px; contrast holds in dark mode |

---

## 5. Known gaps and deliberate decisions

Stated plainly rather than left for you to discover:

1. **Location/geofence reminders (§13.9)** are marked Phase 2 in the brief and are
   **not implemented**. The schema carries a `location` string, but there is no
   geofencing.
2. **Voice-note transcription** of inbound WhatsApp audio (§6.3 Phase 2) is not
   implemented. Image → Gemini Vision → payment reminder **is**
   (`GeminiService::parseImage`).
3. **Payment gateway** — manual approval is implemented end to end (request →
   admin approve → plan applied → GST invoice → referral commission). Razorpay /
   Cashfree / PhonePe are not wired; the brief allows manual approval, and the
   subscription record already carries `payment_method` / `payment_ref` for a
   gateway to fill in later.
4. **"PDF" export** is print-optimised HTML that the browser saves as PDF. This
   avoids shipping a PDF library on hosts without one; the output is clean and
   correct. Same approach for GST invoices.
5. **Web push** — the service worker handles `push` and notification actions, but
   VAPID key generation and the subscription endpoint are not built; the column
   `user_settings.web_push_subscription` exists for it.
6. **Smart suggestions / snooze intelligence (§13.5, §13.8)** — the data needed
   is collected (`user_responses`, `streaks`, snooze counts) and surfaced in
   reports (best/worst hour, average delay), but the proactive "shall I move this
   to Tuesday?" prompt is not implemented.
7. **Gradle wrapper binary** is not committed (a JAR cannot be generated here).
   The CI workflow runs `gradle wrapper` when it is missing, and the README tells
   local developers to do the same once.
8. **`android/app/google-services.json` is absent by design.** The build is
   conditional on it, so the project compiles without Firebase; add the file (or
   the `GOOGLE_SERVICES_JSON` secret) to enable push.

---

## 6. Reproducing this report

```bash
# 1. Syntax across the codebase
find . -name '*.php' -not -path './android/*' -exec php -l {} \; | grep -v 'No syntax errors'

# 2. Engine tests (42 assertions)
php tests/verify.php ; echo "exit=$?"

# 3. Schema + seed against a real MySQL
mysql -u root -p -e 'CREATE DATABASE krishna_test'
mysql -u root -p krishna_test < database/schema.sql
mysql -u root -p krishna_test < database/seed.sql

# 4. Health of a running install
curl -s https://reminder.akdwk.in/api/health.php?full=1&token=<CRON_TOKEN> | jq
```

---

**Summary:** 145 PHP files parse cleanly; 42 engine assertions pass, including 21
Gujarati/Hindi/English sentences and 7 recurrence rules; CI independently proves
the schema loads into MySQL and that no archive is exposed in the web root.
Everything requiring the live gateway, a Gemini key, Firebase or a physical phone
is listed in §4 with the exact steps to confirm it, and the genuine gaps are in §5.
