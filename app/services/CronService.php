<?php

namespace App\Services;

use App\Jobs\HealthCheckJob;

/**
 * Compatibility facade over the centralised Scheduler.
 *
 * This class used to be the cron plumbing: an flock mutex plus run accounting,
 * called by hand from eleven separate scripts. All of that now lives in
 * App\Services\Scheduler, which owns the schedule, the database lock, retries,
 * history and health.
 *
 * What remains here are the three questions other parts of the application ask
 * about background work — the admin dashboard, the monitor page and
 * /api/health.php — answered from the new tables. Keeping the facade means
 * those callers did not have to change, and neither does anything a future
 * integration writes against them.
 */
class CronService
{
    /**
     * One row per registered job, for the dashboard widget.
     *
     * @return array<int, array{job: string, label: string, last_run: string|null,
     *                          duration_ms: int|null, rows: int, status: string, message: string}>
     */
    public static function status(): array
    {
        $out = [];

        foreach (Scheduler::overview() as $job) {
            if ((int) $job['is_registered'] !== 1) {
                continue;
            }

            $out[] = [
                'job'         => (string) $job['job_key'],
                'label'       => (string) $job['label'],
                'last_run'    => $job['last_run_at'],
                'duration_ms' => $job['last_duration_ms'],
                'rows'        => 0,
                'status'      => (int) $job['is_enabled'] === 1 ? (string) $job['last_status'] : 'disabled',
                'message'     => (string) ($job['last_message'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Jobs that should have run by now and have not.
     *
     * @return array<int, array{job: string, last_run: string|null, minutes: int|null}>
     */
    public static function staleJobs(): array
    {
        $health = Scheduler::health();
        $stale = [];

        // The master itself not running is the single most important failure,
        // and it is invisible if you only look at individual jobs: every one of
        // them is "not overdue yet" for a while after the crontab is removed.
        if ($health['master_minutes'] === null || $health['master_minutes'] > 5) {
            $stale[] = [
                'job'      => 'master cron (cron/run.php)',
                'last_run' => Scheduler::lastTick(),
                'minutes'  => $health['master_minutes'],
            ];
        }

        foreach ($health['overdue'] as $job) {
            $stale[] = [
                'job'      => (string) $job['job_key'],
                'last_run' => $job['last_run_at'],
                'minutes'  => $job['minutes_ago'],
            ];
        }

        foreach ($health['failing'] as $job) {
            $stale[] = [
                'job'      => (string) $job['job_key'] . ' (failing)',
                'last_run' => null,
                'minutes'  => null,
            ];
        }

        return $stale;
    }

    /**
     * Alert the owner when something has stopped.
     *
     * The logic lives in HealthCheckJob, which the scheduler runs every fifteen
     * minutes. This entry point stays for anything that wants to force a check.
     */
    public static function alertIfStale(): void
    {
        try {
            (new HealthCheckJob())->handle();
        } catch (\Throwable) {
            // An alert that cannot be sent must never break the caller.
        }
    }
}
