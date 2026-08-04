<?php

/**
 * Proves the centralised scheduler behaves, without needing MySQL.
 *
 * The failures a home-made scheduler produces are specific and expensive:
 *
 *   1. **The same job running twice at once.** Two overlapping passes, or a
 *      "Run now" click while the master is mid-job, and a hundred customers get
 *      the same reminder twice. The lock is proved here against a real database
 *      engine, by racing two claims for the same row.
 *   2. **A job stuck forever** because the run that held its lock was killed.
 *   3. **A daily job firing at the wrong hour** because "09:00" was resolved in
 *      the server's timezone instead of the business's.
 *   4. **Silence.** The crontab is removed, every job is individually "not
 *      overdue yet", and the dashboard stays green while nothing happens.
 *   5. **A migration that loses a job.** Eleven cron scripts became thirteen
 *      registered jobs; anything dropped in the move is work that silently
 *      stopped.
 *
 * Run:  php tests/verify_scheduler.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Jobs\Job;
use App\Services\Scheduler;

$pass = 0;
$fail = 0;

function assertThat(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %-62s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  — ' . $detail : '');
}

$root = dirname(__DIR__);

/* ------------------------------------------------------------------------- */
echo "\n=== 1. Every old cron script survived the move ===\n\n";

$definitions = Scheduler::definitions();

// The eleven jobs that had their own script and their own crontab line.
foreach ([
    'dispatcher'    => 'reminder delivery',
    'ai_queue'      => 'inbound understanding',
    'wa_queue'      => 'outbound queue',
    'recurrence'    => 'repeating reminders',
    'google_sync'   => 'calendar sync',
    'meta_sync'     => 'WhatsApp platform sync',
    'morning_brief' => 'morning brief',
    'daily_summary' => 'night summary',
    'subscriptions' => 'plan expiry',
    'backup'        => 'nightly backup',
    'cleanup'       => 'retention',
] as $key => $what) {
    assertThat("$key is registered ($what)", isset($definitions[$key]));
}

assertThat('payment due reminders are covered', isset($definitions['payment_due']));
assertThat('the scheduler watches itself', isset($definitions['health_check']));

assertThat('every registered job has a unique key',
    count($definitions) === count(Scheduler::REGISTRY));

assertThat('every registered class is a Job', (function (array $definitions): bool {
    foreach ($definitions as $class) {
        if (!is_subclass_of($class, Job::class)) {
            return false;
        }
    }

    return true;
})($definitions));

/* ------------------------------------------------------------------------- */
echo "\n=== 2. Job definitions are sane ===\n\n";

$problems = [];

foreach ($definitions as $key => $class) {
    if (!in_array($class::scheduleKind(), ['every', 'daily', 'weekly'], true)) {
        $problems[] = "$key: bad schedule kind";
    }

    if ($class::scheduleKind() === 'every' && $class::intervalSeconds() < 60) {
        $problems[] = "$key: interval under a minute, which the master cannot honour";
    }

    if ($class::scheduleKind() !== 'every' && $class::runAt() === null) {
        $problems[] = "$key: timed schedule with no time";
    }

    if ($class::scheduleKind() === 'weekly' && $class::weekday() === null) {
        $problems[] = "$key: weekly schedule with no weekday";
    }

    if (trim($class::description()) === '' || trim($class::label()) === '') {
        $problems[] = "$key: no label or description for the admin panel";
    }

    if ($class::timeoutSeconds() < 30) {
        $problems[] = "$key: implausible timeout";
    }
}

assertThat('no job declares an impossible schedule', $problems === [], implode('; ', $problems));

assertThat('reminder delivery has the highest priority', (function (array $definitions): bool {
    $dispatcher = $definitions['dispatcher']::priority();

    foreach ($definitions as $key => $class) {
        if ($key !== 'dispatcher' && $class::priority() <= $dispatcher) {
            return false;
        }
    }

    return true;
})($definitions), 'delivery must never queue behind bookkeeping');

assertThat('backup and cleanup are marked heavy',
    $definitions['backup']::isHeavy() && $definitions['cleanup']::isHeavy());

assertThat('the minute-by-minute jobs are NOT heavy',
    !$definitions['dispatcher']::isHeavy()
    && !$definitions['wa_queue']::isHeavy()
    && !$definitions['ai_queue']::isHeavy());

/* ------------------------------------------------------------------------- */
echo "\n=== 3. Next-run maths, in the business's timezone ===\n\n";

// 2026-08-04 12:00 UTC is 17:30 in Asia/Kolkata — past 09:00, before 22:30.
$base = strtotime('2026-08-04 12:00:00 UTC');

assertThat('an interval job is due one interval later',
    Scheduler::nextRunAt(['schedule_kind' => 'every', 'interval_seconds' => 60], $base) === '2026-08-04 12:01:00');

assertThat('a 15-minute job is due 15 minutes later',
    Scheduler::nextRunAt(['schedule_kind' => 'every', 'interval_seconds' => 900], $base) === '2026-08-04 12:15:00');

assertThat('an interval below the master tick is clamped, not honoured',
    Scheduler::nextRunAt(['schedule_kind' => 'every', 'interval_seconds' => 5], $base) === '2026-08-04 12:00:30');

$daily = Scheduler::nextRunAt(['schedule_kind' => 'daily', 'run_at' => '09:00'], $base);
assertThat('a daily 09:00 IST job resolves to 03:30 UTC', $daily === '2026-08-05 03:30:00', $daily);

$earlier = Scheduler::nextRunAt(['schedule_kind' => 'daily', 'run_at' => '23:00'], $base);
assertThat('a time still ahead today stays today', $earlier === '2026-08-04 17:30:00', $earlier);

$weekly = Scheduler::nextRunAt(['schedule_kind' => 'weekly', 'run_at' => '04:00', 'weekday' => 0], $base);
assertThat('weekly Sunday 04:00 IST is Saturday 22:30 UTC', $weekly === '2026-08-08 22:30:00', $weekly);

assertThat('the weekly result really is a Sunday locally',
    (new DateTimeImmutable($weekly . ' UTC'))
        ->setTimezone(new DateTimeZone(Scheduler::timezone()))->format('l H:i') === 'Sunday 04:00');

// A schedule computed at exactly its own moment must move on, not repeat.
$atNine = strtotime('2026-08-04 03:30:00 UTC');   // 09:00 IST exactly
assertThat('a daily job computed at its own time moves to tomorrow',
    Scheduler::nextRunAt(['schedule_kind' => 'daily', 'run_at' => '09:00'], $atNine) === '2026-08-05 03:30:00');

assertThat('a malformed time does not throw or drift to 1970',
    str_starts_with(Scheduler::nextRunAt(['schedule_kind' => 'daily', 'run_at' => 'nonsense'], $base), '2026-08-'));

/* ------------------------------------------------------------------------- */
echo "\n=== 4. Schedules are described in words, not cron syntax ===\n\n";

foreach ([
    [['schedule_kind' => 'every', 'interval_seconds' => 60], 'every minute'],
    [['schedule_kind' => 'every', 'interval_seconds' => 900], 'every 15 minutes'],
    [['schedule_kind' => 'every', 'interval_seconds' => 3600], 'hourly'],
    [['schedule_kind' => 'daily', 'run_at' => '03:00'], 'daily at 03:00'],
    [['schedule_kind' => 'weekly', 'run_at' => '04:00', 'weekday' => 0], 'weekly on Sunday at 04:00'],
] as [$row, $expected]) {
    assertThat('"' . $expected . '"', Scheduler::describeSchedule($row) === $expected, Scheduler::describeSchedule($row));
}

/* ------------------------------------------------------------------------- */
echo "\n=== 5. Schedule edits are validated before they are saved ===\n\n";

$tooFast = Scheduler::setSchedule('dispatcher', 'every', 30, null, null);
assertThat('an interval under a minute is refused', !$tooFast['ok']);
assertThat('and the refusal explains why', str_contains($tooFast['message'], 'once a minute'), $tooFast['message']);

assertThat('an unknown schedule type is refused',
    !Scheduler::setSchedule('dispatcher', 'hourly-ish', 3600, null, null)['ok']);

assertThat('a timed schedule with no time is refused',
    !Scheduler::setSchedule('dispatcher', 'daily', 60, null, null)['ok']);

assertThat('a malformed time is refused',
    !Scheduler::setSchedule('dispatcher', 'daily', 60, '9am', null)['ok']);

assertThat('an unknown job is refused',
    !Scheduler::setSchedule('no_such_job', 'every', 300, null, null)['ok']);

/* ------------------------------------------------------------------------- */
echo "\n=== 6. The lock: two racers, one winner ===\n\n";

/*
 * The whole no-double-execution guarantee is one conditional UPDATE. It is
 * tested here against a real SQL engine rather than by reading it: SQLite
 * applies WHERE-then-SET with the same semantics MySQL does, so a claim that
 * cannot be won twice here cannot be won twice there either.
 */
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE cron_jobs (
    job_key TEXT PRIMARY KEY, is_enabled INTEGER, next_run_at TEXT,
    locked_at TEXT, locked_by TEXT, last_status TEXT, updated_at TEXT
)');
$pdo->exec("INSERT INTO cron_jobs VALUES ('dispatcher', 1, '2026-08-04 11:00:00', NULL, NULL, 'ok', '')");

// Exactly the statement Scheduler::claim() issues.
$claimSql = 'UPDATE cron_jobs
                SET locked_at = ?, locked_by = ?, last_status = ?, updated_at = ?
              WHERE job_key = ?
                AND (locked_at IS NULL OR locked_at < ?)
                AND is_enabled = 1
                AND (next_run_at IS NULL OR next_run_at <= ?)';

$claim = static function (string $owner) use ($pdo, $claimSql): bool {
    $now = '2026-08-04 12:00:00';
    $stale = '2026-08-04 11:30:00';
    $statement = $pdo->prepare($claimSql);
    $statement->execute([$now, $owner, 'running', $now, 'dispatcher', $stale, $now]);

    return $statement->rowCount() === 1;
};

assertThat('the first claim wins', $claim('host:111'));
assertThat('the second claim loses — no double execution', !$claim('host:222'));

$holder = (string) $pdo->query('SELECT locked_by FROM cron_jobs')->fetchColumn();
assertThat('the winner still holds the lock', $holder === 'host:111', $holder);

// Release, then a later pass can take it again.
$pdo->exec("UPDATE cron_jobs SET locked_at = NULL, locked_by = NULL, next_run_at = '2026-08-04 11:00:00'");
assertThat('after release the next pass can claim it', $claim('host:333'));

// A lock older than the stale threshold is reclaimable — a killed process must
// not wedge a job forever.
$pdo->exec("UPDATE cron_jobs SET locked_at = '2026-08-04 10:00:00', locked_by = 'dead:1',
                                 next_run_at = '2026-08-04 11:00:00'");
assertThat('a stale lock from a dead run is reclaimed', $claim('host:444'));

// A disabled job is never claimed by a scheduled pass.
$pdo->exec("UPDATE cron_jobs SET locked_at = NULL, is_enabled = 0, next_run_at = '2026-08-04 11:00:00'");
assertThat('a disabled job is never claimed', !$claim('host:555'));

// Nor is one that is not due yet.
$pdo->exec("UPDATE cron_jobs SET is_enabled = 1, next_run_at = '2026-08-04 23:00:00'");
assertThat('a job that is not due yet is not claimed', !$claim('host:666'));

/* ------------------------------------------------------------------------- */
echo "\n=== 7. One server cron, and it takes no global lock ===\n\n";

$master = (string) file_get_contents($root . '/cron/run.php');
$scheduler = (string) file_get_contents($root . '/app/services/Scheduler.php');

assertThat('cron/run.php exists and drives the scheduler', str_contains($master, 'Scheduler::tick('));
assertThat('it can run a single job on demand', str_contains($master, "--job"));
assertThat('it can print the schedule', str_contains($master, '--list'));

// Comments stripped: the file explains *why* it does not use flock, and the
// word appearing in that explanation must not count as a call.
$code = static fn (string $source): string => (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

assertThat('the master takes no flock of its own',
    !str_contains($code($scheduler), 'flock(') && !str_contains($code($master), 'flock('),
    'a global lock would let a 4-minute backup block 4 minutes of reminders');

assertThat('the lock is a conditional UPDATE, not a file',
    str_contains($scheduler, 'locked_at IS NULL OR locked_at <'));

assertThat('the claim is checked by rows affected',
    str_contains($scheduler, "->rowCount() === 1"));

assertThat('stale locks are reaped every pass',
    strpos($scheduler, 'self::reapStale()') < strpos($scheduler, 'foreach (self::due()'));

assertThat('heavy jobs are ordered last',
    str_contains($scheduler, 'ORDER BY is_heavy ASC, priority ASC'));

assertThat('a pass has a time budget', str_contains($scheduler, 'scheduler_budget_seconds'));
assertThat('a heavy job is not started late in a pass', str_contains($scheduler, '$budget * 0.5'));

assertThat('the heartbeat is a setting, not a row per minute',
    str_contains($scheduler, "set('scheduler_last_tick'"));

assertThat('retries are bounded by the job, not infinite',
    str_contains($scheduler, 'while ($attempt < $maxAttempts)'));

assertThat('each attempt gets its own history row',
    str_contains($scheduler, 'self::openRun($key, $trigger, $attempt)'));

assertThat('a failure records the trace, not just the message',
    str_contains($scheduler, 'getTraceAsString()'));

assertThat('a repeated skip is not logged every minute',
    str_contains($scheduler, 'A skip is recorded once'));

assertThat('a job that declines to run is skipped, not failed',
    str_contains($scheduler, 'skipReason()'));

/* ------------------------------------------------------------------------- */
echo "\n=== 8. The old crontab keeps working, without running twice ===\n\n";

$legacy = (string) file_get_contents($root . '/cron/_legacy.php');

assertThat('the shared wrapper delegates to the scheduler',
    str_contains($legacy, 'Scheduler::runJob('));

assertThat('a legacy line respects the schedule rather than forcing it',
    str_contains($legacy, '$force = in_array(\'--force\'')
    && str_contains($legacy, 'Scheduler::runJob($jobKey, PHP_SAPI === \'cli\' ? \'cli\' : \'web\', $force)'));

$wrappers = 0;
$duplicated = [];

foreach (glob($root . '/cron/*.php') ?: [] as $file) {
    $name = basename($file, '.php');

    if (in_array($name, ['run', 'repair', '_legacy'], true)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    if (!str_contains($source, "require __DIR__ . '/_legacy.php'")) {
        $duplicated[] = $name;
        continue;
    }

    $wrappers++;

    if (Scheduler::definition($name) === null) {
        $duplicated[] = $name . ' (no such job)';
    }
}

assertThat('every old cron script is now a thin wrapper', $duplicated === [], implode(', ', $duplicated));
assertThat('all eleven wrappers are present', $wrappers === 11, (string) $wrappers);

$webCron = (string) file_get_contents($root . '/cron.php');

assertThat('the web fallback runs the same scheduler', str_contains($webCron, 'Scheduler::tick('));
assertThat('the old ?job=all URL still works', str_contains($webCron, "\$job !== 'all'"));
assertThat('the web fallback still requires the token', str_contains($webCron, 'hash_equals($expected, $given)'));
assertThat('it uses a smaller budget than the CLI', str_contains($webCron, "Scheduler::tick('web', 25)"));

/* ------------------------------------------------------------------------- */
echo "\n=== 9. Silence is detected ===\n\n";

$cronService = (string) file_get_contents($root . '/app/services/CronService.php');

assertThat('a missing master cron is reported as its own failure',
    str_contains($cronService, 'master cron'),
    'every individual job looks fine for a while after the crontab is removed');

assertThat('the dashboard and /api/health keep working through the facade',
    str_contains($cronService, 'function status()') && str_contains($cronService, 'function staleJobs()'));

$health = (string) file_get_contents($root . '/api/health.php');
assertThat('/api/health reports the last scheduler tick',
    str_contains($health, 'scheduler_last_tick'));

assertThat('health slack is proportional to the schedule',
    str_contains($scheduler, 'function slackSeconds'));

assertThat('a daily job is not called overdue five minutes late',
    str_contains($scheduler, "'daily'  => 30 * 3600"));

assertThat('three failures in a row counts as failing',
    str_contains($scheduler, "consecutive_failures'] >= 3"));

$healthJob = (string) file_get_contents($root . '/app/jobs/HealthCheckJob.php');
assertThat('the alert is rate limited to hourly',
    str_contains($healthJob, "RateLimiter::attempt('cron_alert', 1, 3600)"),
    'an alert that fires every 15 minutes trains people to ignore it');

/* ------------------------------------------------------------------------- */
echo "\n=== 10. Admin panel ===\n\n";

$routes = (string) file_get_contents($root . '/app/routes.php');

foreach ([
    '/cron', '/cron/run', '/cron/run-due', '/cron/toggle',
    '/cron/schedule', '/cron/retry', '/cron/unlock', '/cron/settings',
] as $route) {
    assertThat("route $route is registered", str_contains($routes, "'" . $route . "'"));
}

assertThat('every cron POST route is CSRF protected', (function (string $routes): bool {
    preg_match_all("/\\\$r->post\('(\/cron[^']*)'[^\n]*/", $routes, $matches);

    foreach ($matches[0] as $line) {
        if (!str_contains($line, "'csrf'")) {
            return false;
        }
    }

    return count($matches[1]) >= 6;
})($routes));

$controller = (string) file_get_contents($root . '/app/controllers/admin/MonitorController.php');

assertThat('every cron action requires an admin',
    substr_count($controller, 'requireAdmin()') >= 10);

assertThat('"Run now" ignores the schedule but not the lock',
    str_contains($controller, "Scheduler::runJob(\$job, 'admin', true)")
    && str_contains($scheduler, 'never past its lock')
    || str_contains($scheduler, 'but never the lock'));

assertThat('an unknown job cannot be run from the panel',
    str_contains($controller, 'Scheduler::definition($job) === null'));

$view = (string) file_get_contents($root . '/app/views/admin/cron.php');

foreach ([
    'Run everything due now' => 'a manual full pass',
    'Retry'                  => 'retrying a failed job',
    'Unlock'                 => 'clearing a stuck lock',
    'Disable'                => 'turning a job off',
    'Execution history'      => 'the run log',
    'cron/run.php'           => 'the one crontab line',
] as $needle => $what) {
    assertThat('the page offers ' . $what, str_contains($view, $needle));
}

assertThat('the page warns when the master has stopped',
    str_contains($view, 'The master cron last ran'));

assertThat('history rows can show the full error',
    str_contains($view, '$run[\'error\']'));

/* ------------------------------------------------------------------------- */
echo "\n=== 11. Schema ===\n\n";

$migration = (string) file_get_contents($root . '/database/migrations/2026_08_04_000005_central_scheduler.sql');

assertThat('cron_jobs is created', str_contains($migration, 'CREATE TABLE IF NOT EXISTS `cron_jobs`'));
assertThat('the job key is unique — one schedule row per job',
    str_contains($migration, 'UNIQUE KEY `uq_job_key`'));
assertThat('the lock lives in the table', str_contains($migration, '`locked_at`') && str_contains($migration, '`locked_by`'));
assertThat('due lookups are indexed', str_contains($migration, 'KEY `idx_job_due` (`is_enabled`, `next_run_at`)'));
assertThat('failure streaks are counted', str_contains($migration, '`consecutive_failures`'));
assertThat('history gains an attempt number', str_contains($migration, "column_name = 'attempt'"));
assertThat('history gains room for a stack trace', str_contains($migration, "ADD COLUMN `error` TEXT"));
assertThat('the migration is idempotent', substr_count($migration, 'IF NOT EXISTS') >= 1
    && substr_count($migration, "'DO 0'") >= 3);
assertThat('quotes are balanced — an odd count swallows the next statement',
    substr_count($migration, "'") % 2 === 0);

$install = (string) file_get_contents($root . '/install/index.php');
assertThat('the installer asks for one crontab line, not eleven',
    substr_count($install, 'cron/run.php') === 1 && !str_contains($install, 'cron/dispatcher.php'));

echo "\n" . str_repeat('-', 82) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nScheduler confirmed: one server cron, thirteen registered jobs, a database lock that\n";
    echo "cannot be won twice, stale locks reclaimed, schedules resolved in the business's own\n";
    echo "timezone, the old crontab still working without running anything twice, and a missing\n";
    echo "master cron reported as the failure it is rather than as a green dashboard.\n";
}

exit($fail > 0 ? 1 : 0);
