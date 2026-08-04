<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;
use App\Jobs\AiQueueJob;
use App\Jobs\BackupJob;
use App\Jobs\CleanupJob;
use App\Jobs\DailySummaryJob;
use App\Jobs\DispatchRemindersJob;
use App\Jobs\GoogleSyncJob;
use App\Jobs\HealthCheckJob;
use App\Jobs\Job;
use App\Jobs\MessageQueueJob;
use App\Jobs\MetaSyncJob;
use App\Jobs\MorningBriefJob;
use App\Jobs\PaymentDueJob;
use App\Jobs\RecurrenceJob;
use App\Jobs\SubscriptionsJob;

/**
 * The centralised scheduler.
 *
 * One line in the server's crontab, running every minute:
 *
 *     * * * * * /usr/bin/php /path/to/cron/run.php >/dev/null 2>&1
 *
 * Everything else lives here. Adding a background task means writing a Job
 * class and adding one line to REGISTRY — no new script, no new crontab entry,
 * no call to the hosting provider.
 *
 * Three decisions worth stating, because they are the ones that go wrong in
 * home-made schedulers:
 *
 *   1. **The lock is in the database, not in a file.** flock() is per-machine
 *      and per-filesystem; two web servers behind a load balancer, or a CLI and
 *      an admin "Run now" that disagree about whose /tmp is whose, will both
 *      think they hold it. A conditional UPDATE is atomic everywhere MySQL is.
 *   2. **The master pass takes no lock of its own.** If it did, a backup
 *      running for four minutes would block four minutes of reminder dispatch.
 *      Per-job locks already make double execution impossible, and a pass where
 *      everything is locked costs one query per job and exits in milliseconds.
 *   3. **Time is UTC, schedules are local.** Every stored timestamp is UTC via
 *      now_utc(); "run at 09:00" means 09:00 where the business is, computed
 *      through the site timezone, so it survives daylight saving and a server
 *      that thinks it is in London.
 */
class Scheduler
{
    /**
     * Every scheduled task in the application.
     *
     * This list is the whole registration mechanism. A new job is one line.
     *
     * @var array<int, class-string<Job>>
     */
    public const REGISTRY = [
        DispatchRemindersJob::class,
        MessageQueueJob::class,
        AiQueueJob::class,
        RecurrenceJob::class,
        MorningBriefJob::class,
        DailySummaryJob::class,
        PaymentDueJob::class,
        GoogleSyncJob::class,
        MetaSyncJob::class,
        SubscriptionsJob::class,
        HealthCheckJob::class,
        BackupJob::class,
        CleanupJob::class,
    ];

    /** Set once the schedule rows have been reconciled with the registry. */
    private static bool $synced = false;

    /* -------------------------------------------------------------- Registry */

    /** @return array<string, class-string<Job>> */
    public static function definitions(): array
    {
        $out = [];

        foreach (self::REGISTRY as $class) {
            $out[$class::key()] = $class;
        }

        return $out;
    }

    /** @return class-string<Job>|null */
    public static function definition(string $key): ?string
    {
        return self::definitions()[$key] ?? null;
    }

    /**
     * Make the `cron_jobs` table match the registry.
     *
     * New jobs get a row with the defaults their class declares. Existing rows
     * keep whatever an operator has changed — a schedule edited in the admin
     * panel must survive a deploy, or the panel is decoration. A job whose
     * class has been deleted is marked unregistered rather than dropped, so its
     * history is still readable.
     */
    public static function sync(bool $force = false): void
    {
        if (self::$synced && !$force) {
            return;
        }

        self::$synced = true;

        try {
            $db = App::i()->db();

            if (!$db->tableExists('cron_jobs')) {
                return;
            }

            $existing = [];

            foreach ($db->all('SELECT job_key FROM cron_jobs') as $row) {
                $existing[(string) $row['job_key']] = true;
            }

            foreach (self::definitions() as $key => $class) {
                if (isset($existing[$key])) {
                    // Refresh only the fields that describe the code, never the
                    // ones an operator owns (schedule, enabled).
                    $db->update('cron_jobs', [
                        'label'         => $class::label(),
                        'job_group'     => $class::group(),
                        'is_heavy'      => $class::isHeavy() ? 1 : 0,
                        'priority'      => $class::priority(),
                        'is_registered' => 1,
                    ], 'job_key = :key', ['key' => $key]);

                    continue;
                }

                $db->insert('cron_jobs', [
                    'job_key'          => $key,
                    'label'            => $class::label(),
                    'job_group'        => $class::group(),
                    'schedule_kind'    => $class::scheduleKind(),
                    'interval_seconds' => $class::intervalSeconds(),
                    'run_at'           => $class::runAt(),
                    'weekday'          => $class::weekday(),
                    'is_enabled'       => $class::enabledByDefault() ? 1 : 0,
                    'is_heavy'         => $class::isHeavy() ? 1 : 0,
                    'priority'         => $class::priority(),
                    'max_attempts'     => $class::maxAttempts(),
                    'timeout_seconds'  => $class::timeoutSeconds(),
                    'next_run_at'      => self::firstRunAt($class),
                    'created_at'       => now_utc(),
                    'updated_at'       => now_utc(),
                ]);
            }

            $known = array_keys(self::definitions());
            $placeholders = implode(',', array_fill(0, count($known), '?'));

            $db->query(
                'UPDATE cron_jobs SET is_registered = 0, is_enabled = 0
                  WHERE job_key NOT IN (' . $placeholders . ')',
                $known
            );
        } catch (\Throwable $e) {
            Logger::warn('Scheduler sync failed', ['error' => $e->getMessage()], 'cron');
        }
    }

    /* ------------------------------------------------------------ The master */

    /**
     * One pass of the master cron. Runs everything that is due.
     *
     * @return array{ran: int, skipped: int, failed: int, jobs: array<int, array>, seconds: float}
     */
    public static function tick(string $trigger = 'master', ?int $budgetSeconds = null): array
    {
        $started = microtime(true);

        self::sync();
        self::recordTick();
        self::reapStale();

        $settings = App::i()->settings();

        if (!$settings->bool('scheduler_enabled', true)) {
            return ['ran' => 0, 'skipped' => 0, 'failed' => 0, 'jobs' => [], 'seconds' => 0.0];
        }

        $budget = $budgetSeconds ?? max(10, $settings->int('scheduler_budget_seconds', 50));

        $ran = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        foreach (self::due() as $row) {
            $elapsed = microtime(true) - $started;

            // A heavy job started with seconds left would run past the next
            // pass and hold its lock; the light jobs it would delay matter more.
            if ($elapsed >= $budget || ((int) $row['is_heavy'] === 1 && $elapsed >= $budget * 0.5)) {
                $skipped++;
                continue;
            }

            $result = self::runJob((string) $row['job_key'], $trigger, false);
            $results[] = $result;

            match ($result['status']) {
                'ok'      => $ran++,
                'error', 'timeout' => $failed++,
                default   => $skipped++,
            };
        }

        return [
            'ran'     => $ran,
            'skipped' => $skipped,
            'failed'  => $failed,
            'jobs'    => $results,
            'seconds' => round(microtime(true) - $started, 3),
        ];
    }

    /**
     * Jobs that are due now, quick ones first, heavy ones last.
     *
     * @return array<int, array>
     */
    public static function due(): array
    {
        try {
            return App::i()->db()->all(
                'SELECT * FROM cron_jobs
                  WHERE is_enabled = 1
                    AND is_registered = 1
                    AND (next_run_at IS NULL OR next_run_at <= ?)
                  ORDER BY is_heavy ASC, priority ASC, id ASC',
                [now_utc()]
            );
        } catch (\Throwable $e) {
            Logger::warn('Could not read the schedule', ['error' => $e->getMessage()], 'cron');

            return [];
        }
    }

    /* --------------------------------------------------------- Running a job */

    /**
     * Run one job: claim the lock, execute with retries, record everything.
     *
     * @param bool $force ignore the schedule (admin "Run now"), but never the lock
     *
     * @return array{key: string, status: string, message: string, processed: int, duration_ms: int}
     */
    public static function runJob(string $key, string $trigger = 'cli', bool $force = false): array
    {
        self::sync();

        $class = self::definition($key);

        if ($class === null) {
            return self::outcome($key, 'error', 'No such job: ' . $key, 0, 0);
        }

        $db = App::i()->db();
        $row = self::find($key);

        if ($row === null) {
            return self::outcome($key, 'error', 'The schedule row is missing. Run the migration.', 0, 0);
        }

        if (!$force && (int) $row['is_enabled'] !== 1) {
            return self::outcome($key, 'skipped', 'Disabled', 0, 0);
        }

        if (!self::claim($key, $force, (int) $row['timeout_seconds'])) {
            // Another process holds it. This is the normal, expected outcome of
            // two passes overlapping — not an error, and not worth logging.
            return self::outcome($key, 'skipped', 'Already running', 0, 0);
        }

        $started = microtime(true);
        $maxAttempts = max(1, (int) $row['max_attempts']);
        $runId = null;
        $attempt = 0;
        $status = 'error';
        $message = '';
        $error = null;
        $processed = 0;

        // A CLI process has no time limit by default and a web one has 30
        // seconds; neither is what the job asked for.
        @set_time_limit((int) $row['timeout_seconds'] + 30);

        try {
            /** @var Job $job */
            $job = new $class();

            $skip = $job->skipReason();

            if ($skip !== null) {
                $status = 'skipped';
                $message = $skip;
            } else {
                while ($attempt < $maxAttempts) {
                    $attempt++;
                    $runId = self::openRun($key, $trigger, $attempt);

                    try {
                        $result = $job->handle();
                        $processed = (int) ($result['processed'] ?? 0);
                        $message = (string) ($result['message'] ?? '');
                        $status = 'ok';
                        $error = null;
                        break;
                    } catch (\Throwable $e) {
                        $status = 'error';
                        $message = $e->getMessage();
                        $error = $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n"
                               . mb_substr($e->getTraceAsString(), 0, 4000);

                        Logger::exception($e, 'cron');

                        self::closeRun($runId, 'error', 0, $message, $error, $started);
                        $runId = null;

                        if ($attempt < $maxAttempts) {
                            // Short, growing pause: a transient database hiccup
                            // or a rate-limited API is usually gone by the
                            // second try, and hammering it is not help.
                            sleep(min(5, $attempt));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Constructing the job itself failed — a fatal misconfiguration.
            $status = 'error';
            $message = $e->getMessage();
            $error = $e->getTraceAsString();
            Logger::exception($e, 'cron');
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        if ($runId !== null) {
            self::closeRun($runId, $status, $processed, $message, $error, $started);
        } elseif ($status === 'skipped') {
            self::recordSkip($key, $trigger, $message);
        }

        self::release($key, $status, $message, $duration, $row);

        if ($status === 'error') {
            Logger::warn('Scheduled job failed', [
                'job'      => $key,
                'attempts' => $attempt,
                'error'    => mb_substr($message, 0, 300),
            ], 'cron');
        }

        return self::outcome($key, $status, $message, $processed, $duration);
    }

    /**
     * Take the lock, atomically.
     *
     * The whole guarantee lives in this one statement: MySQL applies the WHERE
     * and the SET as a unit, so exactly one caller can see the row unlocked and
     * change it. Two processes racing produce one claim and one refusal, with
     * no window in between.
     */
    private static function claim(string $key, bool $force, int $timeoutSeconds): bool
    {
        $now = now_utc();
        $staleBefore = gmdate('Y-m-d H:i:s', time() - max(60, $timeoutSeconds));

        $sql = 'UPDATE cron_jobs
                   SET locked_at = ?, locked_by = ?, last_run_at = ?, last_status = ?, updated_at = ?
                 WHERE job_key = ?
                   AND (locked_at IS NULL OR locked_at < ?)';

        $params = [$now, self::owner(), $now, 'running', $now, $key, $staleBefore];

        if (!$force) {
            $sql .= ' AND is_enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?)';
            $params[] = $now;
        }

        try {
            return App::i()->db()->query($sql, $params)->rowCount() === 1;
        } catch (\Throwable $e) {
            Logger::warn('Could not claim job lock', ['job' => $key, 'error' => $e->getMessage()], 'cron');

            return false;
        }
    }

    /** Release the lock and write the outcome and the next due time. */
    private static function release(string $key, string $status, string $message, int $durationMs, array $row): void
    {
        try {
            $db = App::i()->db();

            $data = [
                'locked_at'        => null,
                'locked_by'        => null,
                'last_finished_at' => now_utc(),
                'last_status'      => $status,
                'last_message'     => mb_substr($message, 0, 500),
                'last_duration_ms' => $durationMs,
                'next_run_at'      => self::nextRunAt($row),
                'updated_at'       => now_utc(),
            ];

            $db->update('cron_jobs', $data, 'job_key = :key', ['key' => $key]);

            // Counters, incremented in SQL so two runs cannot lose one another's.
            $db->query(
                'UPDATE cron_jobs
                    SET total_runs = total_runs + 1,
                        total_failures = total_failures + ?,
                        consecutive_failures = IF(? = 1, 0, consecutive_failures + 1)
                  WHERE job_key = ?',
                [$status === 'error' ? 1 : 0, $status === 'error' ? 0 : 1, $key]
            );
        } catch (\Throwable $e) {
            Logger::warn('Could not release job lock', ['job' => $key, 'error' => $e->getMessage()], 'cron');
        }
    }

    /**
     * Clear locks held by a run that died — a killed process, an OOM, a deploy
     * mid-job. Without this the job is stuck forever and nothing says why.
     */
    public static function reapStale(): int
    {
        try {
            $db = App::i()->db();

            $stuck = $db->all(
                'SELECT job_key, locked_at, locked_by, timeout_seconds FROM cron_jobs WHERE locked_at IS NOT NULL'
            );

            $reaped = 0;
            $floor = max(60, App::i()->settings()->int('scheduler_lock_stale_minutes', 30) * 60);

            foreach ($stuck as $row) {
                $lockedAt = strtotime((string) $row['locked_at'] . ' UTC');
                $limit = max($floor, (int) $row['timeout_seconds'] * 2);

                if ($lockedAt === false || $lockedAt > time() - $limit) {
                    continue;
                }

                $db->update('cron_jobs', [
                    'locked_at'   => null,
                    'locked_by'   => null,
                    'last_status' => 'timeout',
                    'last_message'=> 'The previous run did not finish (lock held since '
                                     . (string) $row['locked_at'] . ' by ' . (string) $row['locked_by'] . ').',
                    'updated_at'  => now_utc(),
                ], 'job_key = :key', ['key' => (string) $row['job_key']]);

                $db->query(
                    "UPDATE cron_runs SET status = 'timeout', finished_at = ?,
                            message = 'Run did not finish; lock reaped by the scheduler.'
                      WHERE job = ? AND status = 'running'",
                    [now_utc(), (string) $row['job_key']]
                );

                Logger::warn('Reaped a stale job lock', [
                    'job'       => $row['job_key'],
                    'locked_at' => $row['locked_at'],
                    'locked_by' => $row['locked_by'],
                ], 'cron');

                $reaped++;
            }

            return $reaped;
        } catch (\Throwable $e) {
            Logger::warn('Could not reap stale locks', ['error' => $e->getMessage()], 'cron');

            return 0;
        }
    }

    /* ------------------------------------------------------------- Scheduling */

    /**
     * When should this job next run?
     *
     * Local wall-clock in, UTC out. A daily job at 09:00 stays at 09:00 through
     * a daylight-saving change, because the calculation is done in the site's
     * timezone and only then converted.
     */
    public static function nextRunAt(array $row, ?int $fromTs = null): string
    {
        $fromTs ??= time();
        $kind = (string) ($row['schedule_kind'] ?? 'every');

        if ($kind === 'every') {
            $interval = max(30, (int) ($row['interval_seconds'] ?? 60));

            return gmdate('Y-m-d H:i:s', $fromTs + $interval);
        }

        $tz = new \DateTimeZone(self::timezone());
        $now = (new \DateTimeImmutable('@' . $fromTs))->setTimezone($tz);

        [$hour, $minute] = self::parseTime((string) ($row['run_at'] ?? '00:00'));
        $candidate = $now->setTime($hour, $minute, 0);

        if ($kind === 'weekly') {
            $weekday = (int) ($row['weekday'] ?? 0);
            $weekday = ($weekday >= 0 && $weekday <= 6) ? $weekday : 0;

            // Walk forward to the right weekday; if that is today but the time
            // has passed, go round again.
            $daysAhead = ($weekday - (int) $candidate->format('w') + 7) % 7;
            $candidate = $candidate->modify('+' . $daysAhead . ' days');

            if ($candidate <= $now) {
                $candidate = $candidate->modify('+7 days');
            }
        } elseif ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The first due time for a newly registered job.
     *
     * Interval jobs run on the very next pass — a queue drainer that waits an
     * hour after a deploy is a queue that backs up for an hour. Timed jobs wait
     * for their hour, because "the nightly backup" running at 2pm because
     * somebody deployed is a surprise, not a feature.
     *
     * @param class-string<Job> $class
     */
    private static function firstRunAt(string $class): string
    {
        if ($class::scheduleKind() === 'every') {
            return now_utc();
        }

        return self::nextRunAt([
            'schedule_kind' => $class::scheduleKind(),
            'run_at'        => $class::runAt(),
            'weekday'       => $class::weekday(),
        ]);
    }

    /** @return array{0: int, 1: int} */
    private static function parseTime(string $value): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return [0, 0];
        }

        return [min(23, (int) $m[1]), min(59, (int) $m[2])];
    }

    public static function timezone(): string
    {
        try {
            $tz = (string) App::i()->settings()->get('default_timezone', '');

            if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                return $tz;
            }
        } catch (\Throwable) {
        }

        return (string) App::i()->config('app.timezone', 'Asia/Kolkata');
    }

    /* --------------------------------------------------------------- History */

    private static function openRun(string $key, string $trigger, int $attempt): ?int
    {
        try {
            return App::i()->db()->insert('cron_runs', [
                'job'          => $key,
                'started_at'   => now_utc(),
                'status'       => 'running',
                'attempt'      => $attempt,
                'triggered_by' => self::triggerValue($trigger),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function closeRun(
        ?int $runId,
        string $status,
        int $processed,
        string $message,
        ?string $error,
        float $started
    ): void {
        if ($runId === null) {
            return;
        }

        try {
            App::i()->db()->update('cron_runs', [
                'finished_at'    => now_utc(),
                'duration_ms'    => (int) round((microtime(true) - $started) * 1000),
                'rows_processed' => max(0, $processed),
                'status'         => $status === 'skipped' ? 'skipped' : $status,
                'message'        => mb_substr($message, 0, 500),
                'error'          => $error,
            ], 'id = :id', ['id' => $runId]);
        } catch (\Throwable) {
        }
    }

    /**
     * A skip is recorded once, not every minute.
     *
     * Google sync with the integration switched off would otherwise write 96
     * identical rows a day and bury the runs that matter.
     */
    private static function recordSkip(string $key, string $trigger, string $reason): void
    {
        try {
            $db = App::i()->db();

            $last = $db->one('SELECT status, message FROM cron_runs WHERE job = ? ORDER BY id DESC LIMIT 1', [$key]);

            if ($last !== null && (string) $last['status'] === 'skipped' && (string) $last['message'] === mb_substr($reason, 0, 500)) {
                return;
            }

            $db->insert('cron_runs', [
                'job'          => $key,
                'started_at'   => now_utc(),
                'finished_at'  => now_utc(),
                'duration_ms'  => 0,
                'status'       => 'skipped',
                'message'      => mb_substr($reason, 0, 500),
                'triggered_by' => self::triggerValue($trigger),
            ]);
        } catch (\Throwable) {
        }
    }

    private static function triggerValue(string $trigger): string
    {
        return in_array($trigger, ['cli', 'web', 'admin', 'master', 'retry'], true) ? $trigger : 'cli';
    }

    /**
     * The master's heartbeat.
     *
     * Stored as a setting rather than a history row: it is written every minute
     * and read only to answer "is the server cron alive?". A row a minute would
     * be 43,000 rows a month of pure noise in the log everyone actually reads.
     */
    private static function recordTick(): void
    {
        try {
            App::i()->settings()->set('scheduler_last_tick', now_utc(), false, 'cron');
        } catch (\Throwable) {
        }
    }

    public static function lastTick(): ?string
    {
        try {
            $value = (string) App::i()->settings()->get('scheduler_last_tick', '');

            return $value === '' ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Minutes since the master last ran, or null if it never has. */
    public static function minutesSinceTick(): ?int
    {
        $tick = self::lastTick();

        if ($tick === null) {
            return null;
        }

        $ts = strtotime($tick . ' UTC');

        return $ts === false ? null : (int) floor((time() - $ts) / 60);
    }

    /* ---------------------------------------------------------------- Health */

    /**
     * What is wrong, if anything.
     *
     * Overdue is measured against the job's own schedule with generous slack —
     * a job that runs every minute is not in trouble at 90 seconds — so the
     * alert means something when it fires.
     *
     * @return array{
     *   total: int, enabled: int, overdue: array<int, array>, failing: array<int, array>,
     *   locked: array<int, array>, master_minutes: int|null, healthy: bool
     * }
     */
    public static function health(): array
    {
        self::sync();

        $out = [
            'total'          => 0,
            'enabled'        => 0,
            'overdue'        => [],
            'failing'        => [],
            'locked'         => [],
            'master_minutes' => self::minutesSinceTick(),
            'healthy'        => true,
        ];

        try {
            $rows = App::i()->db()->all('SELECT * FROM cron_jobs WHERE is_registered = 1 ORDER BY priority, id');
        } catch (\Throwable) {
            return $out;
        }

        $out['total'] = count($rows);

        foreach ($rows as $row) {
            if ((int) $row['is_enabled'] !== 1) {
                continue;
            }

            $out['enabled']++;

            $slack = self::slackSeconds($row);
            $lastRun = $row['last_run_at'] === null ? null : strtotime((string) $row['last_run_at'] . ' UTC');
            $ago = $lastRun === null ? null : (int) floor((time() - $lastRun) / 60);

            if ($lastRun === null || (time() - $lastRun) > $slack) {
                $out['overdue'][] = [
                    'job_key'     => (string) $row['job_key'],
                    'label'       => (string) $row['label'],
                    'last_run_at' => $row['last_run_at'],
                    'minutes_ago' => $ago,
                ];
            }

            if ((int) $row['consecutive_failures'] >= 3) {
                $out['failing'][] = [
                    'job_key'              => (string) $row['job_key'],
                    'label'                => (string) $row['label'],
                    'consecutive_failures' => (int) $row['consecutive_failures'],
                    'last_message'         => (string) ($row['last_message'] ?? ''),
                ];
            }

            if ($row['locked_at'] !== null) {
                $out['locked'][] = [
                    'job_key'   => (string) $row['job_key'],
                    'label'     => (string) $row['label'],
                    'locked_at' => $row['locked_at'],
                    'locked_by' => $row['locked_by'],
                ];
            }
        }

        $out['healthy'] = $out['overdue'] === []
            && $out['failing'] === []
            && ($out['master_minutes'] !== null && $out['master_minutes'] <= 5);

        return $out;
    }

    /** How late may this job be before anyone should worry? */
    private static function slackSeconds(array $row): int
    {
        $kind = (string) $row['schedule_kind'];

        // Three missed runs for a frequent job, and a quarter-day of grace for
        // a daily one — a nightly backup at 03:00 is not "missing" at 03:05.
        return match ($kind) {
            'daily'  => 30 * 3600,
            'weekly' => 8 * 86400,
            default  => max(600, (int) $row['interval_seconds'] * 3),
        };
    }

    /* ------------------------------------------------------- Admin operations */

    /** @return array<int, array> every job with its definition merged in */
    public static function overview(): array
    {
        self::sync();

        try {
            $rows = App::i()->db()->all('SELECT * FROM cron_jobs ORDER BY is_heavy, priority, id');
        } catch (\Throwable) {
            return [];
        }

        $definitions = self::definitions();

        foreach ($rows as $index => $row) {
            $class = $definitions[(string) $row['job_key']] ?? null;

            $rows[$index]['description'] = $class === null
                ? 'The code for this job is no longer present.'
                : $class::description();

            $rows[$index]['schedule_text'] = self::describeSchedule($row);
        }

        return $rows;
    }

    public static function describeSchedule(array $row): string
    {
        $kind = (string) ($row['schedule_kind'] ?? 'every');

        if ($kind === 'every') {
            $seconds = (int) $row['interval_seconds'];

            return match (true) {
                $seconds < 60    => 'every ' . $seconds . ' seconds',
                $seconds === 60  => 'every minute',
                $seconds < 3600  => 'every ' . (int) round($seconds / 60) . ' minutes',
                $seconds === 3600 => 'hourly',
                default          => 'every ' . round($seconds / 3600, 1) . ' hours',
            };
        }

        $time = mb_substr((string) ($row['run_at'] ?? '00:00'), 0, 5);

        if ($kind === 'daily') {
            return 'daily at ' . $time;
        }

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return 'weekly on ' . ($days[(int) ($row['weekday'] ?? 0)] ?? 'Sunday') . ' at ' . $time;
    }

    public static function find(string $key): ?array
    {
        try {
            return App::i()->db()->one('SELECT * FROM cron_jobs WHERE job_key = ?', [$key]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function setEnabled(string $key, bool $enabled): bool
    {
        if (self::definition($key) === null) {
            return false;
        }

        try {
            $data = ['is_enabled' => $enabled ? 1 : 0, 'updated_at' => now_utc()];

            if ($enabled) {
                // Re-enabling should not immediately fire a job that has been
                // off for a month; give it its normal next slot.
                $row = self::find($key);

                if ($row !== null) {
                    $data['next_run_at'] = self::nextRunAt($row);
                    $data['consecutive_failures'] = 0;
                }
            }

            App::i()->db()->update('cron_jobs', $data, 'job_key = :key', ['key' => $key]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Change a job's schedule from the admin panel.
     *
     * @return array{ok: bool, message: string}
     */
    public static function setSchedule(string $key, string $kind, int $intervalSeconds, ?string $runAt, ?int $weekday): array
    {
        if (self::definition($key) === null) {
            return ['ok' => false, 'message' => 'No such job.'];
        }

        if (!in_array($kind, ['every', 'daily', 'weekly'], true)) {
            return ['ok' => false, 'message' => 'Unknown schedule type.'];
        }

        if ($kind === 'every' && $intervalSeconds < 60) {
            // The master only wakes once a minute; a shorter interval is a
            // promise the architecture cannot keep.
            return ['ok' => false, 'message' => 'The shortest interval is 60 seconds — the master cron runs once a minute.'];
        }

        if ($kind !== 'every' && ($runAt === null || preg_match('/^\d{1,2}:\d{2}$/', $runAt) !== 1)) {
            return ['ok' => false, 'message' => 'Enter a time as HH:MM.'];
        }

        try {
            $data = [
                'schedule_kind'    => $kind,
                'interval_seconds' => max(60, $intervalSeconds),
                'run_at'           => $kind === 'every' ? null : $runAt,
                'weekday'          => $kind === 'weekly' ? max(0, min(6, (int) $weekday)) : null,
                'updated_at'       => now_utc(),
            ];

            $data['next_run_at'] = self::nextRunAt($data);

            App::i()->db()->update('cron_jobs', $data, 'job_key = :key', ['key' => $key]);

            return ['ok' => true, 'message' => 'Schedule updated — next run ' . $data['next_run_at'] . ' UTC.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Queue a failed job to run again on the next pass, and clear its failure
     * streak so the health alert stops shouting about an issue being dealt with.
     */
    public static function retry(string $key): bool
    {
        if (self::definition($key) === null) {
            return false;
        }

        try {
            App::i()->db()->update('cron_jobs', [
                'next_run_at'          => now_utc(),
                'consecutive_failures' => 0,
                'locked_at'            => null,
                'locked_by'            => null,
                'updated_at'           => now_utc(),
            ], 'job_key = :key', ['key' => $key]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Force-release a lock an operator believes is stuck. */
    public static function unlock(string $key): bool
    {
        try {
            App::i()->db()->update('cron_jobs', [
                'locked_at'  => null,
                'locked_by'  => null,
                'updated_at' => now_utc(),
            ], 'job_key = :key', ['key' => $key]);

            App::i()->db()->query(
                "UPDATE cron_runs SET status = 'timeout', finished_at = ?, message = 'Lock cleared by an administrator.'
                  WHERE job = ? AND status = 'running'",
                [now_utc(), $key]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /* ---------------------------------------------------------------- Helpers */

    private static function owner(): string
    {
        $host = gethostname();

        return mb_substr(($host === false ? 'host' : $host) . ':' . getmypid(), 0, 64);
    }

    private static function outcome(string $key, string $status, string $message, int $processed, int $duration): array
    {
        return [
            'key'         => $key,
            'status'      => $status,
            'message'     => $message,
            'processed'   => $processed,
            'duration_ms' => $duration,
        ];
    }
}
