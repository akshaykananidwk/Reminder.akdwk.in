# Changelog

All notable changes to Krishna Reminder are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the
project uses [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — one cron for the whole application

### Changed — every scheduled task now runs from a single master cron

The server used to need ten crontab lines, one per background task, each with
its own script, its own flock file and its own copy of the begin/finish
boilerplate. Adding a feature that needed scheduling meant editing the crontab
on the server. Now there is one line:

```cron
* * * * * /usr/bin/php /path/to/cron/run.php >/dev/null 2>&1
```

- **Thirteen registered jobs**, each a class in `app/jobs/` with its own
  schedule, priority, timeout and retry policy. Adding a background task is a
  class plus one line in `Scheduler::REGISTRY` — no new script, no new crontab
  entry.
- **Admin → Cron** manages all of it: status, last run, next run, failure
  count, enable/disable per job, an editable schedule (every N / daily at /
  weekly on), Run now, Run everything due, Retry, Unlock, and the full
  execution history with stack traces.
- **The old per-job scripts still work.** Each is now a wrapper that runs the
  same job through the same scheduler with the same lock, and respects the
  schedule rather than forcing it — so a machine running both the old lines and
  the new master executes each job once, never twice.
- `cron.php?job=all` keeps working and quietly gets better: it now runs
  everything due, not just the three one-minute jobs.

### Added

- `payment_due` — payments entered without a reminder of their own had nothing
  watching them: the due date passed and nobody was told. One message per
  payment per local day, and only for money that no reminder already covers.
- `health_check` — alerts the admin WhatsApp number when a job stops reporting
  or fails three times in a row, at most hourly.
- `cron/run.php --list` prints the schedule, next run times and last status.
- `docs/SCHEDULER.md`, and `tests/verify_scheduler.php` (107 assertions).

### Fixed

- **Locks were per-machine.** flock() cannot coordinate two web servers, or a
  CLI cron and an admin "Run now" whose PHP-FPM pool has a different /tmp —
  both would believe they held it and the customer would get the reminder
  twice. The lock is now a conditional UPDATE, proved in the test suite by
  racing two claims against a real SQL engine.
- **A killed run wedged its job forever.** Stale locks are now reaped every
  pass, the run is marked `timeout` in the history, and the reason is logged.
- **A missing crontab looked healthy.** Every job individually reads as "not
  overdue yet" for a while after the cron is removed. The master now writes a
  heartbeat on every pass, and the admin page, /api/health and cron/repair.php
  all lead with it.
- **Daily jobs were scheduled in the server's timezone.** "09:00" now means
  09:00 where the business is, computed in the site timezone and converted
  afterwards, so it survives daylight saving.
- A recurrence test compared against a hard-coded occurrence count and started
  failing after local midnight. The expectation is now derived from the
  calendar, in the reminder's own timezone.

---

## [Unreleased] — migration to the official Meta WhatsApp Cloud API

### Changed — WhatsApp now runs entirely on Meta's official API

- **`bulk.akdwk.in` is out of the send path.** `meta_only_mode` defaults to
  on, so `providerOrder()` returns the Cloud API alone. The old credentials
  are kept, unreachable, purely so the switch is reversible if a number
  turns out not to be registered yet.
- **Embedded Signup** — a business connects its own WhatsApp Business
  Account from inside this application. The authorisation code is exchanged
  server-side, the token is encrypted at rest, and the steps everyone
  forgets are done automatically: webhook subscription, phone number sync,
  phone registration with the two-step PIN, and template sync. Each step
  reports its own outcome, because a connection that stored a token but
  failed to subscribe looks identical to a working one until the first
  webhook does not arrive.
- **A pasted System User token is a first-class path**, so nobody has to
  wait for Meta app review to use the product.

### Added

- **Full send API** — text, image, video, audio, document, sticker,
  location, contact, template, OTP, reply buttons and list pickers. Every
  message is recorded in `wa_messages` *before* the wire call, so a message
  Meta accepted is never invisible to us.
- **Template manager** — create, validate, submit, edit, sync, clone,
  import, export, delete. Validation catches what Meta rejects days later
  without naming the reason: gapped `{{n}}`, a body starting or ending with
  a variable, a header with two variables, a name with capitals.
- **Webhook ingestion** — raw events stored before processing, one body
  split into its independent facts, each deduplicated by its own key.
  Inbound of every type is kept in full with media ids, reply context and
  interactive selections. A late `sent` receipt can never drag a message
  back from `read`.
- **Billing and pricing** — per-conversation cost by category and day, with
  an operator-maintained rate card. Meta sends the pricing category, not the
  price, so the card is seeded at zero and the dashboard says the totals are
  unknown rather than reporting a month of messaging as free.
- **Media manager** — uploads keyed by SHA-256 so the same file is never
  uploaded twice; inbound media downloaded with the bearer token and stored
  outside the web root.
- **Conversation dashboard**, **Graph API log** with tokens redacted, and a
  **webhook event log** showing what was signed and what was processed.
- **Multi-tenancy** — every Meta-facing row is addressed by a
  `waba_accounts` row with its own token, numbers, templates, webhooks and
  billing.
- `cron/meta_sync.php` — the reconciliation pass for template decisions,
  quality ratings and webhook events that arrived while the site was down.
- `tests/verify_meta_platform.php` — 142 assertions, no network, no
  database.
- `docs/META-WHATSAPP-PLATFORM.md`.

### Fixed

- **A fresh install was missing every table added since the original
  schema.** `install/index.php` imported `schema.sql` and `seed.sql` but
  never ran `database/migrations`, so a brand-new install was *behind* an
  upgraded one and its first update tried to apply everything at once.
- **`class="alert warn"` matched no CSS rule**, so several notices rendered
  as unstyled white panels with no colour. Both spellings now work.

### Security

- A send is never retried on a timeout. The Cloud API has no idempotency
  key, and cURL cannot distinguish a request Meta never saw from one it
  accepted slowly — repeating the second delivers the reminder twice.
  Non-idempotent calls retry only on an explicit 429 or 503.
- Access tokens and app secrets encrypted with AES-256-GCM; tokens stripped
  from `wa_api_log` by key name *and* by pattern.
- Webhook signatures verified against the connected account's app secret,
  falling back to the platform's.

---

## [1.0.0] — 2026-07-27

First complete release: web platform, WhatsApp + AI engine, and the Android app.

### Web platform (PHP 8.1+ / MySQL)

- **One-click installer** (`/install`) — eight steps, writes `config/config.php`
  itself, imports the schema and seed, live-tests the WhatsApp gateway and
  Gemini, verifies cron actually ran, then locks itself.
- **Front controller** with a pattern router, middleware (auth, admin, guest,
  CSRF) and hardened sessions.
- **Public site** — landing page, features, pricing, blog CMS, FAQ, contact,
  ten city landing pages, and the legal pages a payment gateway asks for
  (privacy, terms, refund, cancellation). SEO: dynamic meta, Open Graph,
  JSON-LD `SoftwareApplication` + `FAQPage`, `sitemap.xml`, `robots.txt`, GA4
  and Search Console fields.
- **Auth** — registration with mandatory WhatsApp number, 6-digit OTP over our
  own gateway (5 min expiry, 5 attempts, 60 s cooldown, per-number and per-IP
  limits), password or OTP login, 90-day remember-me, WhatsApp password reset,
  optional Google sign-in, session list with per-device revoke.
- **Client dashboard** — home with live countdown and progress ring, reminders
  with filters and bulk actions, full add/edit form, calendar month view,
  payment ledger with partial payments and EMI series, notes, contacts and
  staff, reports with CSV/ICS/print-to-PDF export, WhatsApp Inbox showing what
  the AI understood, devices with test call, integrations, billing, referral
  ledger, settings, and a Gujarati command cheat-sheet.
- **Admin panel** — dashboard with AI cost chart and cron health, user
  management with audit-logged impersonation, plans/subscriptions/invoices/
  coupons, WhatsApp settings with live test and logs, Gemini settings with a
  hard monthly token budget, per-language message templates, cron monitor with
  *Run now*, logs, per-user AI cost report, broadcast, content and SEO, system
  page with backups and restore, and the GitHub updater.
- **GitHub auto-update engine** — check shows commit SHA, message, author, date
  and changed-file count; update runs backup → download → extract → copy
  (respecting the protected list and `.updateignore`) → migrations → health
  check, with **automatic rollback** and a WhatsApp alert on failure.
- **Cron engine** — ten PHP-CLI jobs, `flock` mutexes, `cron_runs` accounting,
  stale-job WhatsApp alerts, and a token-protected web-cron fallback.
- **Security** — PDO prepared statements throughout, CSRF on every POST, output
  escaping, Argon2id/bcrypt, login throttling and lockout, rate limits on OTP,
  API and webhook, HMAC or shared secret on the webhook, AES-256-GCM encryption
  for API keys and OAuth tokens, secure uploads with no execution in
  `/uploads`, CSP and security headers, audit log, data export and account
  deletion.
- **Backups outside the web root** (`../kr-backups`), pure-PHP `mysqldump`,
  rotation, and a repeated check that no `.zip`/`.sql` is reachable over HTTP.

### WhatsApp + AI

- Gateway client for `bulk.akdwk.in` — POST + JSON only, queued, rate-limited,
  retried three times with exponential backoff, fully logged.
- Inbound webhook with secret/HMAC verification, sub-second `200`, phone
  normalisation, strict whitelist, deduplication and asynchronous processing.
- **Quick commands** (LIST/યાદી, TODAY/આજે, PENDING/બાકી, DONE, SNOOZE, CANCEL,
  PAID, SUMMARY, LANG, STOP/START, HELP/મદદ) — instant and free of AI cost.
- **Gemini engine** — short system prompt, JSON-only output, low temperature,
  capped tokens, 24-hour cache, key rotation, per-user monthly quotas, global
  budget hard stop, and per-request token/cost logging.
- **Fallback parser** — pure PHP, covering Gujarati, Hindi and English date,
  time, repetition and amount expressions (કાલે, પરમ દિવસે, દર સોમવારે,
  દર મહિને 5 તારીખે, 15 મિનિટ પછી, બે કલાક પછી, આવતા શુક્રવારે …), plus
  Gujarati and Devanagari numeral normalisation.
- Twenty message templates × three languages, all editable in admin.
- Morning brief and night summary at each user's own local time.

### Android app (Kotlin, Jetpack Compose)

- Language selection, three onboarding slides, and a permission wizard that
  explains each item and deep-links to the OEM autostart screen on Xiaomi,
  Oppo, Vivo, Realme, Samsung, Huawei and others.
- WhatsApp-OTP login that stays signed in (30-day access token, 2-year refresh
  token, silent renewal on 401).
- **The call experience** — FCM high-priority data push starts a foreground
  service that posts a CallStyle full-screen-intent notification and launches a
  full-screen activity over the lock screen, rings, vibrates, and speaks the
  reminder twice with `gu-IN → hi-IN → en-IN` fallback. Done / Snooze
  (5·10·15·30·60) / Reschedule / Dismiss.
- **Local exact alarms** for every occurrence (`setExactAndAllowWhileIdle`) with
  on-device speech composition, so the phone rings with no network at all.
- Boot, app-update, timezone and clock-change receivers re-arm every alarm.
- Room cache, offline action queue with client-id deduplication, and a
  15-minute WorkManager reconcile.
- Home dashboard, reminders with filters, payments, natural-language and full
  add forms, settings with a test call, about with update check.
- Home-screen widget (today's list) and a Quick Settings "Add reminder" tile.
- Share text from any app to create a reminder.

### Build and CI

- `.github/workflows/android.yml` — JDK 17 + Gradle cache, debug and release
  APKs, signing from repository secrets, `versionCode` auto-incremented from the
  run number, artifacts on every build, and a GitHub Release with the APK
  attached on tags.
- `.github/workflows/php-lint.yml` — PHP 8.1 and 8.3 syntax check over every
  file, a MySQL service that imports `schema.sql` + `seed.sql` and asserts the
  core tables and seeded templates exist, and a guard against archives in the
  web root.

[1.0.0]: https://github.com/akshaykananidwk/reminder.akdwk.in/releases/tag/v1.0.0
