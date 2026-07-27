<?php

/**
 * cron/backup.php — daily at 03:00.
 * Full database + files backup stored outside the web root, with rotation.
 *
 *   0 3 * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/backup.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\BackupService;
use App\Services\CronService;
use App\Services\WhatsAppService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('backup', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$status = 'ok';
$message = '';

try {
    $result = BackupService::run('full', 'cron');

    if ($result['ok']) {
        $message = basename($result['path']) . ' (' . round($result['size'] / 1048576, 1) . ' MB)';
    } else {
        $status = 'error';
        $message = (string) $result['error'];

        $adminNumber = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($adminNumber !== '') {
            WhatsAppService::queue(
                $adminNumber,
                "⚠️ *Krishna Reminder* — nightly backup failed:\n" . mb_substr($message, 0, 300),
                null,
                null,
                1,
                'system_alert'
            );
        }
    }

    // Safety net: nothing downloadable must sit in the document root.
    $issues = BackupService::auditWebroot();

    if ($issues !== []) {
        Logger::warn('Web root exposure detected', ['files' => $issues], 'backup');
        $message .= ' | webroot warnings: ' . implode(', ', $issues);
    }
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'backup');
}

CronService::finish(1, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[backup] ' . $message . PHP_EOL;
}
