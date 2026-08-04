<?php

/**
 * Shared body for the old per-job cron scripts.
 *
 * Every scheduled task now lives in App\Services\Scheduler and runs from
 * cron/run.php. These wrappers stay because a server somewhere still has the
 * old ten crontab lines, and a deploy that silently stops the reminders is not
 * an acceptable way to announce an architecture change.
 *
 * They delegate rather than duplicate, and they respect the schedule rather
 * than forcing it — so a machine running both the old lines and the new master
 * gets each job at its configured cadence, executed once, never twice. The
 * database lock makes the "never twice" part true even if both fire in the
 * same second.
 *
 * Pass --force to run the job regardless of when it is next due.
 *
 * The including script sets $jobKey.
 */

if (!isset($jobKey) || !is_string($jobKey)) {
    fwrite(STDERR, "This file is not meant to be run directly.\n");
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';

if (!\App\Core\App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$result = \App\Services\Scheduler::runJob($jobKey, PHP_SAPI === 'cli' ? 'cli' : 'web', $force);

if (PHP_SAPI === 'cli' && $result['status'] !== 'skipped') {
    printf("[%s] %s — %s\n", $result['key'], $result['status'], $result['message']);
}

exit($result['status'] === 'error' ? 1 : 0);
