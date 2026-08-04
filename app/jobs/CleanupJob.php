<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\CacheService;
use App\Services\OtpService;

/**
 * Retention. Old logs, expired sessions, spent OTPs, emptied recycle bins and
 * stale temporary files.
 *
 * Every table here grows with traffic and is read by nobody after a month or
 * two. Left alone, a busy install fills the disk and then everything fails at
 * once, in a way that looks like a hundred unrelated bugs.
 */
class CleanupJob extends Job
{
    public static function key(): string
    {
        return 'cleanup';
    }

    public static function label(): string
    {
        return 'Retention & cleanup';
    }

    public static function description(): string
    {
        return 'Purges old logs, expired sessions and OTPs, emptied recycle bins and stale temporary files.';
    }

    public static function group(): string
    {
        return 'maintenance';
    }

    public static function priority(): int
    {
        return 90;
    }

    public static function scheduleKind(): string
    {
        return 'weekly';
    }

    public static function runAt(): ?string
    {
        return '04:00';
    }

    public static function weekday(): ?int
    {
        return 0;   // Sunday
    }

    public static function isHeavy(): bool
    {
        return true;
    }

    public static function timeoutSeconds(): int
    {
        return 1800;
    }

    public function handle(): array
    {
        $db = App::i()->db();
        $removed = 0;

        $purges = [
            ['wa_inbound_raw', 'received_at', 90],
            ['wa_outbound_log', 'created_at', 60],
            ['ai_logs', 'created_at', 180],
            ['ai_queue', 'created_at', 30],
            ['error_logs', 'created_at', 60],
            ['login_attempts', 'created_at', 30],
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
            } catch (\Throwable $e) {
                Logger::warn('Cleanup failed for table', ['table' => $table, 'error' => $e->getMessage()], 'cleanup');
            }
        }

        // Cron history has its own retention setting, because it is the first
        // thing anyone looks at when a job misbehaves.
        $historyDays = max(3, App::i()->settings()->int('scheduler_history_days', 30));
        $removed += $db->delete('cron_runs', 'started_at < ?', [date('Y-m-d H:i:s', time() - ($historyDays * 86400))]);

        // Permanently remove items in the recycle bin after 30 days.
        $binCutoff = date('Y-m-d H:i:s', time() - (30 * 86400));
        $removed += $db->delete('reminders', 'deleted_at IS NOT NULL AND deleted_at < ?', [$binCutoff]);
        $removed += $db->delete('notes', 'deleted_at IS NOT NULL AND deleted_at < ?', [$binCutoff]);

        // Expired sessions and OTPs.
        $removed += $db->delete(
            'sessions',
            'expires_at < ? AND (refresh_expires_at IS NULL OR refresh_expires_at < ?)',
            [now_utc(), now_utc()]
        );
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

        return ['processed' => $removed, 'message' => 'removed=' . $removed];
    }
}
