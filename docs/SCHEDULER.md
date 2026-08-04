# The scheduler

One line in the server's crontab. Everything else is managed from
**Admin → Cron**.

```cron
* * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/run.php >/dev/null 2>&1
```

`cron/run.php` wakes every minute, asks the database what is due, and runs it.
Which jobs exist, how often each runs, whether it is switched on, what happened
last time and what went wrong — all of it lives in `cron_jobs` and `cron_runs`
and is editable from the admin panel.

---

## 1. Adding a background task

Two steps. There is no third.

**Write a job class** in `app/jobs/`:

```php
namespace App\Jobs;

class WeeklyReportJob extends Job
{
    public static function key(): string   { return 'weekly_report'; }
    public static function label(): string { return 'Weekly report'; }

    public static function description(): string
    {
        return 'Emails each account owner last week&rsquo;s numbers.';
    }

    public static function scheduleKind(): string { return 'weekly'; }
    public static function runAt(): ?string       { return '07:00'; }
    public static function weekday(): ?int        { return 1; }   // Monday

    public function handle(): array
    {
        $sent = /* … the work … */ 0;

        return ['processed' => $sent, 'message' => "sent=$sent"];
    }
}
```

**Register it** in `App\Services\Scheduler::REGISTRY`:

```php
WeeklyReportJob::class,
```

That is all. The next master pass creates its schedule row, the admin panel
shows it with its own enable switch, schedule editor, run button and history,
and the health check starts watching it. No new script, no new crontab entry,
no call to the hosting provider.

A job reports failure by **throwing**. A job that swallows its own exceptions
reports success forever while doing nothing, which is worse than one that fails
loudly. A job that has nothing to do because a feature is switched off returns
a reason from `skipReason()` instead — that is recorded as *skipped*, not
*failed*, so a disabled integration never looks like a broken cron.

---

## 2. What runs

| Key | Job | Default schedule | Group |
|---|---|---|---|
| `dispatcher` | Reminder dispatch | every minute | reminders |
| `wa_queue` | WhatsApp / Telegram queue | every minute | messaging |
| `ai_queue` | Inbound message processing | every minute | messaging |
| `recurrence` | Repeating reminders | hourly | reminders |
| `morning_brief` | Morning brief | every 5 minutes | summaries |
| `daily_summary` | Night summary | every 5 minutes | summaries |
| `payment_due` | Payment due reminders | daily 10:00 | billing |
| `google_sync` | Google Calendar sync | every 15 minutes | integrations |
| `meta_sync` | WhatsApp platform sync | every 15 minutes | messaging |
| `subscriptions` | Subscription & plan expiry | daily 09:00 | billing |
| `health_check` | Scheduler health check | every 15 minutes | maintenance |
| `backup` | Nightly backup | daily 03:00 | maintenance |
| `cleanup` | Retention & cleanup | weekly Sunday 04:00 | maintenance |

Times are in the site timezone (**Admin → Settings → Timezone**), not the
server's and not UTC.

---

## 3. The three decisions that matter

### The lock is in the database, not in a file

`flock()` is per-machine and per-filesystem. Two web servers behind a load
balancer, or a CLI cron and an admin "Run now" whose PHP-FPM pool has a
different `/tmp`, will both believe they hold it — and the customer gets the
same reminder twice.

The lock is one conditional `UPDATE`:

```sql
UPDATE cron_jobs
   SET locked_at = :now, locked_by = :owner, …
 WHERE job_key = :key
   AND (locked_at IS NULL OR locked_at < :stale)
   AND is_enabled = 1
   AND (next_run_at IS NULL OR next_run_at <= :now)
```

MySQL applies the `WHERE` and the `SET` as a unit, so exactly one caller can see
the row unlocked and change it. Two racers produce one claim and one refusal,
with no window in between. `rowCount() === 1` is the whole guarantee, and
`tests/verify_scheduler.php` proves it by racing two claims against a real SQL
engine.

A run that dies — killed process, OOM, deploy mid-job — leaves its lock behind.
Every pass reaps locks older than the job's own timeout, marks that run
`timeout` in the history, and says so in the log. Nothing stays stuck.

### The master takes no lock of its own

If it did, a backup running for four minutes would block four minutes of
reminder dispatch. Per-job locks already make double execution impossible, and
a pass where everything is locked costs one query per job and exits in
milliseconds.

Instead the pass has a **budget** (50 seconds by default). Quick jobs run first,
ordered by priority — reminder delivery has the lowest number of any job and
never queues behind bookkeeping. A job marked **heavy** (backup, cleanup) is
only *started* in the first half of the budget; if it runs past the minute, the
next pass simply finds it locked and runs everything else.

### Time is UTC, schedules are local

Every stored timestamp is UTC. "Run at 09:00" means 09:00 where the business is:
the calculation happens in the site timezone and is converted afterwards, so it
survives daylight saving and a server that thinks it is in London.

---

## 4. Retries and failure

`maxAttempts()` on the job (default 1). A failing attempt is recorded in the
history with its own row, its message and its stack trace, then retried after a
short growing pause — a transient database hiccup or a rate-limited API is
usually gone by the second try, and hammering it is not help.

After the final attempt the job goes back on its normal schedule and its
`consecutive_failures` counter increases. **Admin → Cron → Retry** puts it back
in the queue immediately and resets that counter.

---

## 5. Health

`health_check` runs every fifteen minutes and alerts the admin WhatsApp number
when a job has stopped reporting or has failed three times in a row — at most
once an hour, because an alert that fires every fifteen minutes trains people to
ignore it.

Overdue is measured against each job's own schedule with generous slack: three
missed runs for a frequent job, thirty hours for a daily one. A nightly backup
at 03:00 is not "missing" at 03:05.

The one failure that individual jobs cannot detect is **the master not running
at all** — for a while, every job is merely "not overdue yet". So the master
writes a heartbeat (`scheduler_last_tick`) on every pass, and the admin page,
`/api/health.php?full=1` and `cron/repair.php` all lead with it.

---

## 6. Operating it

From the shell:

```bash
php cron/run.php              # one pass — run whatever is due
php cron/run.php --list       # the schedule, next run times and last status
php cron/run.php --job=backup # run one job now, ignoring its schedule
php cron/run.php --budget=120 # a longer pass, for catching up by hand
```

From the browser, when the host has no PHP-CLI cron:

```
https://reminder.akdwk.in/cron.php?token=<CRON_TOKEN>
```

From the admin panel: enable/disable each job, change its schedule, run it now,
run everything due, retry a failure, clear a stuck lock, read the full history
with stack traces, and switch the whole scheduler off without touching the
server.

---

## 7. The old crontab

The per-job scripts (`cron/dispatcher.php`, `cron/wa_queue.php`, …) still exist
and still work. Each is now a wrapper that runs the same job through the same
scheduler with the same lock, and **respects the schedule rather than forcing
it** — so a machine running both the old ten lines and the new master executes
each job at its configured cadence, once, never twice.

They can be removed from the crontab whenever convenient. Nothing breaks if they
are left.

`cron/repair.php` is unchanged in purpose: a hand-run diagnostic for when the
site is misbehaving. It now reports the master's heartbeat too.
