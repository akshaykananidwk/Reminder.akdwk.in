<?php

/**
 * cron/daily_summary.php — every 5 minutes.
 * Sends each user's night summary at their configured local time and rolls the
 * daily streak forward.
 *
 *   *\/5 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/daily_summary.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\ReminderService;
use App\Services\SummaryService;
use App\Services\WhatsAppService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('daily_summary', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$sent = 0;
$status = 'ok';
$message = '';

try {
    foreach (SummaryService::usersDueFor('night', 5) as $user) {
        try {
            ReminderService::updateStreak((int) $user['id']);

            $body = SummaryService::buildNightSummary((int) $user['id'], $user, (string) $user['local_date']);

            if ($body !== '') {
                WhatsAppService::queue((string) $user['phone'], $body, (int) $user['id'], null, 5, 'night_summary');
                $sent++;
            }

            SummaryService::markSent((int) $user['id'], (string) $user['local_date'], 'night');
        } catch (Throwable $e) {
            Logger::warn('Night summary failed', ['user_id' => $user['id'], 'error' => $e->getMessage()], 'summary');
        }
    }

    $message = 'sent=' . $sent;
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'summary');
}

CronService::finish($sent, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[daily_summary] ' . $message . PHP_EOL;
}
