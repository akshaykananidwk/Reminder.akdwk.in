<?php

/**
 * cron/cleanup.php — weekly.
 * Purges old logs, raw messages, expired caches and temp files, and alerts the
 * owner when any cron job has stopped running.
 *
 *   0 4 * * 0 /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/cleanup.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\CacheService;
use App\Services\CronService;
use App\Services\OtpService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('cleanup', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$db = App::i()->db();
$status = 'ok';
$message = '';
$removed = 0;

try {
    $purges = [
        ['wa_inbound_raw', 'received_at', 90],
        ['wa_outbound_log', 'created_at', 60],
        ['ai_logs', 'created_at', 180],
        ['ai_queue', 'created_at', 30],
        ['error_logs', 'created_at', 60],
        ['login_attempts', 'created_at', 30],
        ['cron_runs', 'started_at', 30],
        ['delivery_attempts', 'created_at', 90],
        ['notifications', 'created_at', 60],
        ['idempotency_keys', 'created_at', 7],
        ['ai_cache', 'expires_at', 2],
        ['wa_outbound_queue', 'created_at', 30],
        ['audit_logs', 'created_at', 365],
    ];

    foreach ($purges as [$table, $column, $days]) {
        try {
            $removed += $db->delete($table, "$column < ?", [date('Y-m-d H:i:s', time() - ($days * 86400))]);
        } catch (Throwable $e) {
            Logger::warn('Cleanup failed for table', ['table' => $table, 'error' => $e->getMessage()], 'cleanup');
        }
    }

    // Permanently remove items in the recycle bin after 30 days.
    $removed += $db->delete('reminders', 'deleted_at IS NOT NULL AND deleted_at < ?', [date('Y-m-d H:i:s', time() - (30 * 86400))]);
    $removed += $db->delete('notes', 'deleted_at IS NOT NULL AND deleted_at < ?', [date('Y-m-d H:i:s', time() - (30 * 86400))]);

    // Expired sessions and OTPs.
    $removed += $db->delete('sessions', 'expires_at < ? AND (refresh_expires_at IS NULL OR refresh_expires_at < ?)', [now_utc(), now_utc()]);
    $removed += OtpService::purgeExpired();
    $removed += RateLimiter::purgeExpired();
    $removed += CacheService::purgeExpired();

    // Stale temp files.
    foreach (glob(App::i()->root() . '/storage/temp/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 86400 && !str_ends_with($file, '.lock')) {
            @unlink($file);
            $removed++;
        }
    }

    // Old server-generated TTS audio.
    foreach (glob(App::i()->root() . '/uploads/tts/*.mp3') ?: [] as $file) {
        if (filemtime($file) < time() - (14 * 86400)) {
            @unlink($file);
            $removed++;
        }
    }

    CronService::alertIfStale();

    $message = 'removed=' . $removed;
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'cleanup');
}

CronService::finish($removed, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[cleanup] ' . $message . PHP_EOL;
}
