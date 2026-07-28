<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Cron plumbing: an flock-based mutex so runs never overlap, plus run
 * accounting in `cron_runs` for the admin monitor.
 */
class CronService
{
    /** @var resource|null */
    private static $lockHandle = null;

    private static ?int $runId = null;
    private static float $startedAt = 0.0;

    /**
     * Acquire the lock for a job. Returns false when another run holds it.
     */
    public static function begin(string $job, string $triggeredBy = 'cli'): bool
    {
        $dir = App::i()->root() . '/storage/temp';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lockFile = $dir . '/cron-' . preg_replace('/[^a-z0-9_-]/i', '', $job) . '.lock';
        $handle = @fopen($lockFile, 'c+');

        if ($handle === false) {
            Logger::warn('Could not open cron lock file', ['job' => $job], 'cron');

            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            // A stale lock older than 30 minutes means the previous run died.
            if (is_file($lockFile) && filemtime($lockFile) < time() - 1800) {
                Logger::warn('Stale cron lock detected', ['job' => $job], 'cron');
                @unlink($lockFile);
            }

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        self::$lockHandle = $handle;
        self::$startedAt = microtime(true);

        try {
            self::$runId = App::i()->db()->insert('cron_runs', [
                'job'          => $job,
                'started_at'   => now_utc(),
                'status'       => 'running',
                'triggered_by' => $triggeredBy,
            ]);
        } catch (\Throwable) {
            self::$runId = null;
        }

        return true;
    }

    public static function finish(int $rowsProcessed = 0, string $status = 'ok', string $message = ''): void
    {
        if (self::$runId !== null) {
            try {
                App::i()->db()->update('cron_runs', [
                    'finished_at'    => now_utc(),
                    'duration_ms'    => (int) round((microtime(true) - self::$startedAt) * 1000),
                    'rows_processed' => $rowsProcessed,
                    'status'         => $status,
                    'message'        => mb_substr($message, 0, 500),
                ], 'id = :id', ['id' => self::$runId]);
            } catch (\Throwable) {
                // Ignore.
            }
        }

        if (is_resource(self::$lockHandle)) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
        }

        self::$runId = null;
    }

    /**
     * Jobs that have not reported in for longer than the configured window.
     */
    public static function staleJobs(): array
    {
        $minutes = max(5, App::i()->settings()->int('cron_alert_minutes', 15));

        $expected = [
            'dispatcher'     => 5,
            'ai_queue'       => 10,
            'wa_queue'       => 10,
            'recurrence'     => 120,
            'morning_brief'  => 30,
            'daily_summary'  => 30,
            'subscriptions'  => 1500,
            'meta_sync'      => 60,
            'backup'         => 1500,
            'cleanup'        => 10080,
        ];

        $stale = [];

        foreach ($expected as $job => $maxGapMinutes) {
            $gap = max($minutes, $maxGapMinutes);

            $last = App::i()->db()->one(
                'SELECT started_at FROM cron_runs WHERE job = ? ORDER BY id DESC LIMIT 1',
                [$job]
            );

            if ($last === null) {
                $stale[] = ['job' => $job, 'last_run' => null, 'minutes' => null];
                continue;
            }

            $ago = (int) round((time() - strtotime((string) $last['started_at'] . ' UTC')) / 60);

            if ($ago > $gap) {
                $stale[] = ['job' => $job, 'last_run' => $last['started_at'], 'minutes' => $ago];
            }
        }

        return $stale;
    }

    /**
     * Alert the owner on WhatsApp when a job stops running (at most hourly).
     */
    public static function alertIfStale(): void
    {
        $stale = self::staleJobs();

        if ($stale === []) {
            return;
        }

        $number = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($number === '' || !\App\Core\RateLimiter::attempt('cron_alert', 1, 3600)) {
            return;
        }

        $lines = ['⚠️ *Krishna Reminder — cron alert*', ''];

        foreach ($stale as $item) {
            $lines[] = '• ' . $item['job'] . ' — ' . ($item['minutes'] === null ? 'never run' : $item['minutes'] . ' min ago');
        }

        WhatsAppService::queue($number, implode("\n", $lines), null, null, 1, 'system_alert');
    }

    /**
     * Summary rows for the admin cron monitor.
     */
    public static function status(): array
    {
        $jobs = ['dispatcher', 'ai_queue', 'wa_queue', 'recurrence', 'google_sync', 'meta_sync', 'morning_brief', 'daily_summary', 'subscriptions', 'backup', 'cleanup'];
        $out = [];

        foreach ($jobs as $job) {
            $row = App::i()->db()->one(
                'SELECT * FROM cron_runs WHERE job = ? ORDER BY id DESC LIMIT 1',
                [$job]
            );

            $out[] = [
                'job'         => $job,
                'last_run'    => $row['started_at'] ?? null,
                'duration_ms' => $row['duration_ms'] ?? null,
                'rows'        => $row['rows_processed'] ?? 0,
                'status'      => $row['status'] ?? 'never',
                'message'     => $row['message'] ?? '',
            ];
        }

        return $out;
    }
}
