<?php

/**
 * cron/run.php — the master cron. The only line the server needs:
 *
 *     * * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/run.php >/dev/null 2>&1
 *
 * Every scheduled task in the application is registered in
 * App\Services\Scheduler::REGISTRY and executed from here when it is due.
 * Adding a background task never means adding a crontab entry again.
 *
 * Options:
 *   --job=<key>   run one job now, ignoring its schedule (but never its lock)
 *   --list        print the schedule and exit
 *   --budget=<n>  seconds this pass may spend starting new jobs
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\Scheduler;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

$cli = PHP_SAPI === 'cli';
$options = [];

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $argument, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

/* ------------------------------------------------------------------ --list */

if (isset($options['list'])) {
    Scheduler::sync(true);

    printf("%-16s %-28s %-22s %-20s %s\n", 'KEY', 'JOB', 'SCHEDULE', 'NEXT RUN (UTC)', 'STATUS');

    foreach (Scheduler::overview() as $job) {
        printf(
            "%-16s %-28s %-22s %-20s %s%s\n",
            $job['job_key'],
            mb_substr((string) $job['label'], 0, 28),
            $job['schedule_text'],
            (string) ($job['next_run_at'] ?? '—'),
            (int) $job['is_enabled'] === 1 ? $job['last_status'] : 'disabled',
            (int) $job['is_registered'] === 1 ? '' : ' (unregistered)'
        );
    }

    exit(0);
}

/* ------------------------------------------------------------- --job=<key> */

if (isset($options['job']) && $options['job'] !== '1') {
    $result = Scheduler::runJob((string) $options['job'], $cli ? 'cli' : 'web', true);

    if ($cli) {
        printf("[%s] %s — %s (%d ms)\n", $result['key'], $result['status'], $result['message'], $result['duration_ms']);
    }

    exit($result['status'] === 'error' ? 1 : 0);
}

/* --------------------------------------------------------------- One pass */

try {
    $budget = isset($options['budget']) ? max(5, (int) $options['budget']) : null;
    $result = Scheduler::tick($cli ? 'master' : 'web', $budget);
} catch (Throwable $e) {
    Logger::exception($e, 'cron');

    if ($cli) {
        fwrite(STDERR, '[scheduler] ' . $e->getMessage() . PHP_EOL);
    }

    exit(1);
}

if ($cli && ($result['ran'] > 0 || $result['failed'] > 0)) {
    printf(
        "[scheduler] ran=%d failed=%d skipped=%d in %.2fs\n",
        $result['ran'],
        $result['failed'],
        $result['skipped'],
        $result['seconds']
    );

    foreach ($result['jobs'] as $job) {
        if ($job['status'] !== 'skipped') {
            printf("  %-16s %-8s %s\n", $job['key'], $job['status'], mb_substr((string) $job['message'], 0, 90));
        }
    }
}

exit($result['failed'] > 0 ? 1 : 0);
