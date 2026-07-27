# Installing Krishna Reminder

Target: **aaPanel + Apache + PHP 8.1+ + MySQL/MariaDB**. No Composer, no Node, no Docker.

---

## 1. Requirements

| Item | Minimum |
|---|---|
| PHP | 8.1 (8.2 / 8.3 recommended) |
| Extensions | `pdo_mysql`, `curl`, `mbstring`, `json`, `openssl`, `fileinfo` (required) · `zip`, `gd` (backups, icons) |
| MySQL / MariaDB | 5.7 / 10.3+ |
| Web server | Apache with `mod_rewrite` (nginx and LiteSpeed work — see §7) |
| Cron | PHP-CLI, one-minute granularity (a web-cron fallback is included) |
| TLS | Required — WhatsApp webhooks and Google OAuth will not accept plain HTTP |

Make sure PHP-CLI is **not** in safe/restricted mode and that `curl` can reach the internet outbound.

---

## 2. Upload and run the installer

1. Create the site in aaPanel (`reminder.akdwk.in`) and issue an SSL certificate.
2. Create an **empty** MySQL database plus a user with full rights on it.
3. Upload the project ZIP into the site root and extract it. `index.php` must sit at the top level of the document root.
4. Set ownership so PHP can write: `chown -R www:www .` (aaPanel's user is usually `www`).
5. Open **`https://reminder.akdwk.in/install`**.

The wizard has eight steps:

| Step | What happens |
|---|---|
| 1 · Requirements | Red/green table of extensions and writable folders. Blocking items must be green. |
| 2 · Database | Connection is tested, then `schema.sql` and `seed.sql` are imported and `config/config.php` is written (with a fresh 32-byte encryption key, cron token and webhook secret). |
| 3 · Site settings | Name, URL, timezone, language, currency, and the company details used on GST invoices. |
| 4 · Admin account | Owner login for `/admin`, with a password strength meter. |
| 5 · WhatsApp API | Endpoint, api_key, session_id — then a **live test message** must arrive before you continue. |
| 6 · Gemini | API key is **live-tested** before continuing. You may skip; the built-in parser then handles everything. |
| 7 · Cron | The exact crontab lines to paste, plus a web-cron URL. The installer verifies a run was actually recorded. |
| 8 · Finish | Writes `config/install.lock`, prepares the backup directory, and marks migrations as applied. |

**After finishing, delete the `/install` folder.** The installer already locks itself, but removing it is cleaner.

---

## 3. Cron

Paste these into aaPanel → Cron (adjust the path and the PHP binary):

```cron
* * * * *  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/dispatcher.php    >/dev/null 2>&1
* * * * *  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/ai_queue.php      >/dev/null 2>&1
* * * * *  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/wa_queue.php      >/dev/null 2>&1
*/5 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/morning_brief.php  >/dev/null 2>&1
*/5 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/daily_summary.php  >/dev/null 2>&1
*/15 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/google_sync.php   >/dev/null 2>&1
0 * * * *  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/recurrence.php    >/dev/null 2>&1
0 9 * * *  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/subscriptions.php >/dev/null 2>&1
0 3 * * *  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/backup.php        >/dev/null 2>&1
0 4 * * 0  /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/cleanup.php       >/dev/null 2>&1
```

Every job takes an `flock` mutex, so overlapping runs are impossible and a stuck run is detected after 30 minutes.

**No PHP-CLI cron available?** Point any uptime monitor at this URL once a minute — it runs the three one-minute jobs:

```
https://reminder.akdwk.in/cron.php?job=all&token=<CRON_TOKEN>
```

The token is in `config/config.php` under `security.cron_token`, and the ready-made URL is shown in **Admin → Cron monitor**.

The admin cron monitor shows last run, duration, rows processed and errors for each job, with a **Run now** button. If a job stops reporting, the owner gets a WhatsApp alert (at most hourly).

---

## 4. WhatsApp gateway

In **Admin → WhatsApp**:

- **Endpoint** — `https://bulk.akdwk.in` (no `/api.php`; the service appends it)
- **API key** and **Session id** — stored encrypted; leaving the field blank keeps the existing value
- **Sender number** — `919978123146`
- **Rate** — default 30 messages/minute; the queue paces itself to match
- **Test send** — verifies the whole path end to end

Then, in the gateway's own admin, set the **outbound webhook** to the URL shown on that page:

```
https://reminder.akdwk.in/api/wa_webhook.php?secret=<WEBHOOK_SECRET>
```

Only verified, active numbers are processed. Everything else is recorded in `unknown_inbound` and — if enabled — gets one signup invite per 7 days.

---

## 5. Firebase (so phones can ring)

1. Create a Firebase project and add an Android app with package `com.akdwk.krishnareminder`.
2. Download `google-services.json` → put it in `android/app/` (or store it as the `GOOGLE_SERVICES_JSON` repository secret for CI).
3. In the Google Cloud console, create a service account with the **Firebase Cloud Messaging API** role and download its JSON key.
4. Paste that JSON into **Admin → Settings → Push → Service account JSON**. It is encrypted at rest.

The server prefers FCM HTTP v1 (service account, RS256 JWT signed with `openssl` — no libraries needed) and falls back to the legacy server key if only that is set.

Without Firebase the product still works: reminders arrive by WhatsApp, and the Android app still rings from its local alarms.

---

## 6. Google Calendar (optional, per user)

1. Google Cloud console → OAuth consent screen → add scopes `calendar.events`, `tasks`, `openid`, `email`, `profile`.
2. Create an OAuth **Web application** client with the redirect URI:
   `https://reminder.akdwk.in/auth/google/callback`
3. Put the client id and secret in **Admin → Settings → Google**, and switch the integration on.

Users connect from **Integrations** in their dashboard. Refresh tokens are encrypted at rest. Disconnecting revokes the token and can optionally delete the synced events.

---

## 7. nginx / LiteSpeed

The shipped `.htaccess` covers Apache. For nginx, add:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ^~ /api/v1 {
    try_files $uri /api/v1/index.php?$query_string;
}

# Never serve these
location ~* ^/(config|storage|backups|database|app|android)/ { deny all; return 404; }
location ~* \.(sql|zip|tar|gz|bak|log|env|lock)$        { deny all; return 404; }

# No script execution in uploads
location ^~ /uploads/ {
    location ~ \.php$ { deny all; return 404; }
}
```

---

## 8. Updating

**Admin → Updates**:

1. Enter the GitHub owner, repository, branch and a token (encrypted at rest).
2. **Check for update** shows the current vs latest commit, its message, author, date and changed-file count (cached 15 minutes).
3. **Update now** runs: pre-flight (disk, writability) → maintenance mode ON → **full backup** (files + database, outside the web root) → download the branch archive → extract → copy files **skipping the protected list** → run pending SQL migrations from `database/migrations/` (tracked in `schema_migrations`) → clear cache and bump the asset version → health check → maintenance mode OFF.
4. **Any failure rolls back automatically** — files and database are restored from the backup, the failure is logged, and the owner gets a WhatsApp alert.

Never overwritten: `config/config.php`, `config/install.lock`, `.env`, `/uploads/**`, `/storage/**`, `/backups/**`, `.git`, plus anything listed in `.updateignore`.

---

## 9. Backups

- Nightly at 03:00 (`cron/backup.php`): full ZIP with a pure-PHP `mysqldump` (no `shell_exec` needed) plus the file tree.
- Stored in `../kr-backups` — **outside the document root**. If that is not writable, `/backups` is used with a deny-all `.htaccess` and an `index.html`.
- Retention is configurable (default 14 days) and old backups are rotated automatically.
- Download and restore are authenticated admin actions and are audit-logged. There is no public URL to a backup, ever.

---

## 10. Troubleshooting

| Symptom | Where to look |
|---|---|
| Reminders never fire | **Admin → Cron monitor**. If `dispatcher` is stale, cron is not running. |
| Phone does not ring | Device page → *Send test call*. Then in the app: permission wizard (exact alarms, notifications, battery), and Autostart on Xiaomi/Oppo/Vivo/Realme/Samsung. |
| WhatsApp not delivered | **Admin → WhatsApp** → outbound log and queue. Check `wa_enabled`, the API key, and the gateway session. |
| AI misreads a message | The user's **WhatsApp Inbox** shows the raw text, what the AI understood and the reply — with *Reprocess with AI*. |
| Costs climbing | **Admin → AI cost report**: per-user tokens and spend, plus the global budget hard stop. |
| Blank page | `storage/logs/php-YYYY-MM-DD.log` and **Admin → Logs → Errors**. |
| Site says "not installed" | `config/config.php` is missing or unreadable — check permissions, then re-run `/install`. |

Health endpoint for uptime monitors:

```
GET /api/health.php                                  → {"status":"ok", …}
GET /api/health.php?full=1&token=<CRON_TOKEN>        → cron staleness, queue depth, disk, web-root exposure
```
