<?php

/**
 * cron/google_sync.php — every 15 minutes.
 * Two-way Google Calendar sync for connected users only.
 *
 *   *\/15 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/google_sync.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\GoogleService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('google_sync', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$status = 'ok';
$message = '';
$totals = ['pushed' => 0, 'pulled' => 0, 'deleted' => 0, 'errors' => 0];

try {
    if (!GoogleService::isEnabled()) {
        CronService::finish(0, 'skipped', 'Google integration is disabled');

        return;
    }

    $accounts = App::i()->db()->all(
        'SELECT g.user_id
           FROM google_accounts g
           JOIN users u ON u.id = g.user_id
           JOIN user_settings s ON s.user_id = g.user_id
          WHERE g.is_active = 1 AND u.is_active = 1 AND u.deleted_at IS NULL AND s.google_sync_enabled = 1
          ORDER BY COALESCE(g.last_sync_at, "1970-01-01") ASC
          LIMIT 100'
    );

    foreach ($accounts as $account) {
        try {
            $result = GoogleService::syncUser((int) $account['user_id']);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($result[$key] ?? 0);
            }
        } catch (Throwable $e) {
            $totals['errors']++;
            Logger::warn('Google sync failed for user', ['user_id' => $account['user_id'], 'error' => $e->getMessage()], 'google');
        }
    }

    $message = sprintf('accounts=%d pushed=%d pulled=%d deleted=%d errors=%d',
        count($accounts), $totals['pushed'], $totals['pulled'], $totals['deleted'], $totals['errors']);
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'google');
}

CronService::finish($totals['pushed'] + $totals['pulled'], $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[google_sync] ' . $message . PHP_EOL;
}
