<?php

/**
 * cron/wa_queue.php — every 1 minute.
 * Drains the outbound WhatsApp queue at the configured rate with retries.
 *
 *   * * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/wa_queue.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\WhatsAppService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('wa_queue', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$status = 'ok';
$message = '';
$processed = 0;

try {
    // At the default 30/minute rate, 40 is comfortably more than one tick.
    $result = WhatsAppService::processQueue(40);
    $processed = $result['sent'] + $result['failed'];
    $message = sprintf('sent=%d failed=%d', $result['sent'], $result['failed']);
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'wa_queue');
}

CronService::finish($processed, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[wa_queue] ' . $message . PHP_EOL;
}
