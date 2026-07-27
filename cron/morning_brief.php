<?php

/**
 * cron/morning_brief.php — every 5 minutes.
 * Sends each user's morning brief at their configured local time.
 *
 *   *\/5 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/morning_brief.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\SummaryService;
use App\Services\WhatsAppService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('morning_brief', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$sent = 0;
$status = 'ok';
$message = '';

try {
    foreach (SummaryService::usersDueFor('morning', 5) as $user) {
        try {
            $body = SummaryService::buildMorningBrief((int) $user['id'], $user, (string) $user['local_date']);

            if ($body === '') {
                // Nothing scheduled — mark handled so we do not retry all day.
                SummaryService::markSent((int) $user['id'], (string) $user['local_date'], 'morning');
                continue;
            }

            WhatsAppService::queue((string) $user['phone'], $body, (int) $user['id'], null, 4, 'morning_brief');
            SummaryService::markSent((int) $user['id'], (string) $user['local_date'], 'morning');
            $sent++;
        } catch (Throwable $e) {
            Logger::warn('Morning brief failed', ['user_id' => $user['id'], 'error' => $e->getMessage()], 'summary');
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
    echo '[morning_brief] ' . $message . PHP_EOL;
}
