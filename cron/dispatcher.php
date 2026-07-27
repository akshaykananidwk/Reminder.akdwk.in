<?php

/**
 * cron/dispatcher.php — every 1 minute.
 *
 * Finds due occurrences, fires the call/push/WhatsApp delivery chain, handles
 * escalation retries, advance alerts and marks unanswered reminders missed.
 *
 *   * * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/dispatcher.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\CronService;
use App\Services\SchedulerService;

if (!\App\Core\App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

$trigger = (PHP_SAPI === 'cli') ? 'cli' : 'web';

if (!CronService::begin('dispatcher', $trigger)) {
    return; // Previous run still in progress.
}

$processed = 0;
$status = 'ok';
$message = '';

try {
    $result = SchedulerService::tick(300);
    $processed = array_sum($result);
    $message = sprintf(
        'due=%d escalated=%d advance=%d missed=%d',
        $result['due'],
        $result['escalated'],
        $result['advance'],
        $result['missed']
    );
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    \App\Core\Logger::exception($e, 'dispatcher');
}

CronService::finish($processed, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[dispatcher] ' . $message . PHP_EOL;
}
