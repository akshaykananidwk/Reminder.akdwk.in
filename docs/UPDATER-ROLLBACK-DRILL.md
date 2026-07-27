# Updater rollback drill

**Never let a production site be the first place the updater fails.**

The one-click updater takes a backup, downloads the new release, copies it over
the live tree, runs migrations, and health-checks the result. If any step
throws, it rolls back. That rollback path only runs when something has already
gone wrong — which is the worst possible moment to discover it does not work.

So it gets exercised twice before production: once offline, once on staging.

---

## 1. Offline — run this now, it costs nothing

```bash
php tests/verify_update_rollback.php
```

35 assertions on a throwaway directory tree in the system temp folder. It
builds a fake site (application files *plus* `config/config.php`, `.env`,
`uploads/`, `storage/`, `backups/`, `.git/`), takes a backup in the real
archive format, then deliberately breaks the update: the new release ships
`app/legacy` as a **directory** where the installed site has a **file**, so
`mkdir()` cannot succeed and the copy aborts with the tree half-replaced.

It then checks that:

- the failure is raised rather than swallowed;
- the tree really is part-updated (otherwise the rollback proves nothing);
- nothing protected was touched by the failed update;
- the rollback puts every application file back;
- the rollback does **not** restore `config/config.php`, `.env`, `uploads/`,
  `storage/`, `backups/` or `.git/` — restoring those would throw away live
  configuration and user data;
- a tampered archive containing `files/../OWNED.txt` cannot write outside the
  site root;
- a missing backup, or one with no `files/` entries, fails loudly instead of
  reporting a successful rollback.

Nothing here touches a live site, contacts GitHub, or needs a database.

This does not cover the database restore, maintenance mode, or the admin
WhatsApp alert. Those need a server — which is what section 2 is for.

---

## 2. Staging — do this before the first production update

### Set the staging site up

1. Create a subdomain, e.g. `staging.akdwk.in`, with its **own database**.
   Never point staging at the production database. The drill restores a
   database from a backup; against production data that is a data-loss event.
2. Install Krishna Reminder there normally, through `/install`.
3. Add realistic data — a few users, reminders with occurrences, a payment or
   two, some uploads. An empty site will not surface a broken restore.
4. In **Admin → Settings → Updates**, point it at the same GitHub repository
   and branch production uses, and confirm **Check for updates** works.
5. Set **Admin alert number** to your own WhatsApp number so you can confirm
   the failure notification actually arrives.

### Record the "before" state

```bash
cd /www/wwwroot/staging.akdwk.in

sha256sum config/config.php .env > /root/before.txt
find uploads storage -type f | sort | xargs sha256sum >> /root/before.txt
mysql -u USER -p DBNAME -e "SELECT COUNT(*) FROM users; \
  SELECT COUNT(*) FROM reminders; SELECT COUNT(*) FROM reminder_occurrences; \
  SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 5;" \
  > /root/before-db.txt
```

### Force the failure

Pick **one** per run — each exercises a different part of the path.

**A. Health check fails after the files are already copied.** The most
realistic failure and the one that most needs to work: the release installed
cleanly but the application is broken.

```sql
RENAME TABLE reminder_occurrences TO reminder_occurrences_drill;
```

`healthCheck()` requires `users`, `reminders`, `reminder_occurrences` and
`settings`. Run the update from the admin panel. Expect: update aborts, files
roll back, database restores from the backup (which brings the table back),
`maintenance_mode` returns to `0`, and a WhatsApp alert arrives.

If the drill somehow leaves the table missing, put it back by hand:

```sql
RENAME TABLE reminder_occurrences_drill TO reminder_occurrences;
```

**B. Copy fails part-way.** Proves a half-written tree is recoverable.

```bash
mkdir -p app/services/GeminiService.php   # a directory where a file must go
```

Expect: `Could not create directory` or `Could not write file`, then rollback.
Remove the decoy afterwards: `rmdir app/services/GeminiService.php`.

**C. A migration fails.** Proves a bad schema change does not strand the site.

Add `database/migrations/9999_drill_fail.sql` containing:

```sql
ALTER TABLE this_table_does_not_exist ADD COLUMN drill INT;
```

Expect: rollback, and `schema_migrations` back to its pre-update contents.
Delete the file afterwards.

**D. Backup fails.** Proves the updater refuses to proceed unprotected.

```bash
chmod 000 ../krishna-backups     # wherever backup_path points
```

Expect: the update stops at the backup step and **never copies anything** —
no rollback needed because nothing changed. Restore with `chmod 755`.

### Verify the "after" state

```bash
sha256sum -c /root/before.txt          # every line must say OK
mysql -u USER -p DBNAME -e "..."       # compare against /root/before-db.txt
```

Then check by hand:

- the site loads and you can log in;
- `Admin → Settings` shows **Maintenance mode: off**;
- `Admin → Updates → History` shows the run with status `rolled_back` and the
  real error message;
- the WhatsApp alert arrived on the admin number;
- `storage/logs/update-*.log` records `Rollback completed` with a file count.

### What a failed drill looks like

| Symptom | Meaning |
|---|---|
| History says `rolled_back` but files are still the new version | `restoreFiles()` failed — check `storage/logs` for `Rollback did not restore the files` |
| Files are back but the schema is new | The database restore failed. This is reported as a **failed** rollback, not a success — check `restoreDatabase` in the log |
| `config/config.php` changed | A protected path was written. Stop; do not update production |
| Site stuck in maintenance mode | `maintenance_mode` was not cleared — set it to `0` in the `settings` table |
| No WhatsApp alert | `alert_admin_number` is unset, or the gateway is down |

Any of these means **do not run the updater on production** until it is fixed.

---

## 3. Production

Only after both sections pass:

1. Run the update during quiet hours (early morning IST).
2. Take a manual backup first from **Admin → Backups**, in addition to the one
   the updater takes.
3. Confirm the backup is written **outside** the document root
   (`backup_path` must not be under the web root — a reachable `.zip` is enough
   on its own to get the domain flagged).
4. Keep a terminal open. If the browser tab dies mid-update, the update
   continues server-side; check `Admin → Updates → History` rather than
   re-running it.

## If the site is broken right now: `cron/repair.php`

A self-updating application must have a way back that does not go through
itself. When an update leaves the site throwing 500s, the admin panel is
exactly what you cannot use to fix it.

```bash
php /www/wwwroot/reminder.akdwk.in/cron/repair.php
```

It applies any migrations that have not run yet — **one at a time, naming each
one** — clears the bytecode cache, clears the application cache, checks that
every column the current code needs actually exists, and switches off a
maintenance mode that was left on. It deletes nothing and is safe to run twice.

If it stops on a migration, that migration is the problem. Its error is printed
in full, and it matters more than it looks: a migration is only recorded in
`schema_migrations` **after** it succeeds, so one that fails is retried on the
next update, fails again, and rolls that whole update back. The site then stays
stuck on the old release no matter how many times you press Update — which
looks exactly like "the update does nothing".

Afterwards, restart PHP-FPM from aaPanel (Website → PHP → Service). The repair
script runs on the command line, and the web server has its own separate
bytecode cache that a CLI run cannot reach.

## "Something went wrong" straight after an update

Seen once on production: the update finished and applied correctly, the browser
was redirected to `/admin/update`, and *that* page returned a 500. Signing in
again a moment later showed everything working and the update in place.

That is stale bytecode, not a failed update.

PHP's opcache stores the compiled version of each file and only re-checks the
file's timestamp every `opcache.revalidate_freq` seconds — **60 by default on
aaPanel**. The updater replaces the files in place, so for up to a minute
afterwards the site runs a *mixture* of the old and new release. A new view
calling a method that opcache still has the old copy of is a fatal error.

The updater now calls `opcache_reset()` immediately after copying, and again
after a rollback (which also replaces files). If the host has disabled
`opcache_reset()`, it falls back to invalidating each PHP file individually.
Either way the outcome is recorded as an `opcache` step, so
**Admin → Updates → History** shows what actually happened:

| Step detail | Meaning |
|---|---|
| `reset` | The whole cache was cleared. Nothing further to do. |
| `N file(s) invalidated` | `opcache_reset()` was blocked; files were cleared one by one. Fine. |
| `opcache not enabled` | Nothing to clear. Fine. |
| `could not be cleared — restart PHP-FPM…` | The host blocks both. **Restart PHP-FPM after each update**, or the site will serve mixed code for a minute. |

If it happens anyway: wait a minute and reload, or restart PHP-FPM from aaPanel.
Nothing is broken and nothing needs restoring.

Two other things were fixed at the same time, both of which made this harder to
diagnose than it should have been:

- **The error page told the admin nothing.** A signed-in admin now sees the real
  exception, file, line and stack trace on the 500 page instead of "We have
  logged the problem". Ordinary visitors still get the friendly page. The check
  reads `$_SESSION` directly and never touches the database, because the thing
  that failed may *be* the database.
- **The admin could be signed out.** `session_regenerate_id(true)` deletes the
  old session at once, so if the new id could not be sent as a cookie — which is
  the case once a page has begun sending output — the browser was left holding
  an id that no longer existed. It now only rotates while the headers can still
  be changed. The updater also releases the session lock while it works, so a
  multi-minute update no longer freezes every other tab.

## What the updater deliberately does not do

Rollback restores the files that were in the backup. It does **not** delete
files the new release added. A file from the failed release can therefore
survive a rollback. This is intentional — a rollback that deletes is a rollback
that can delete the wrong thing — but it means that after a failed update you
should compare the tree against the repository before declaring it clean:

```bash
git -C /www/wwwroot/staging.akdwk.in status --short
```
