<?php

/**
 * cron/recurrence.php — hourly.
 * Materialises the next 30 days of occurrences for recurring reminders.
 *
 *   0 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/recurrence.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\RecurrenceService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('recurrence', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$db = App::i()->db();
$status = 'ok';
$created = 0;
$scanned = 0;
$message = '';

try {
    $horizon = date('Y-m-d H:i:s', time() + (25 * 86400));

    $reminders = $db->all(
        'SELECT r.*, u.timezone
           FROM reminders r
           JOIN users u ON u.id = r.user_id
          WHERE r.status = "active"
            AND r.deleted_at IS NULL
            AND u.is_active = 1
            AND u.deleted_at IS NULL
            AND r.recurrence IS NOT NULL
            AND JSON_UNQUOTE(JSON_EXTRACT(r.recurrence, "$.freq")) <> "none"
            AND (r.materialised_until IS NULL OR r.materialised_until < ?)
          ORDER BY r.id ASC
          LIMIT 500',
        [$horizon]
    );

    foreach ($reminders as $reminder) {
        $scanned++;

        try {
            $created += RecurrenceService::materialise($reminder, 30);
        } catch (Throwable $e) {
            Logger::warn('Materialise failed', ['reminder_id' => $reminder['id'], 'error' => $e->getMessage()], 'recurrence');
        }
    }

    // Also catch one-off reminders whose occurrence row went missing.
    $orphans = $db->all(
        'SELECT r.*, u.timezone
           FROM reminders r
           JOIN users u ON u.id = r.user_id
           LEFT JOIN reminder_occurrences o ON o.reminder_id = r.id
          WHERE r.status = "active" AND r.deleted_at IS NULL AND o.id IS NULL AND r.start_at > ?
          LIMIT 200',
        [now_utc()]
    );

    foreach ($orphans as $reminder) {
        $created += RecurrenceService::materialise($reminder, 30);
        $scanned++;
    }

    $message = sprintf('scanned=%d created=%d', $scanned, $created);
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'recurrence');
}

CronService::finish($created, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[recurrence] ' . $message . PHP_EOL;
}
