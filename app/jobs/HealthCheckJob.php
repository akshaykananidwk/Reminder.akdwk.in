<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\RateLimiter;
use App\Services\Scheduler;
use App\Services\WhatsAppService;

/**
 * Watches the other jobs, and says something when one stops.
 *
 * A scheduler that fails silently is worse than no scheduler: everything looks
 * fine, the dashboard is green, and reminders quietly stop arriving. This runs
 * on the same master pass as everything else, so if it is not reporting then
 * the master itself is down — which is exactly the condition the server-side
 * "cron has not run" banner catches.
 */
class HealthCheckJob extends Job
{
    public static function key(): string
    {
        return 'health_check';
    }

    public static function label(): string
    {
        return 'Scheduler health check';
    }

    public static function description(): string
    {
        return 'Detects jobs that have stopped running or keep failing, and alerts the owner on WhatsApp.';
    }

    public static function group(): string
    {
        return 'maintenance';
    }

    public static function priority(): int
    {
        return 70;
    }

    public static function intervalSeconds(): int
    {
        return 900;
    }

    public function handle(): array
    {
        $health = Scheduler::health();
        $problems = [];

        foreach ($health['overdue'] as $job) {
            $problems[] = $job['label'] . ' — ' . (
                $job['last_run_at'] === null
                    ? 'never run'
                    : 'last ran ' . $job['minutes_ago'] . ' min ago'
            );
        }

        foreach ($health['failing'] as $job) {
            $problems[] = $job['label'] . ' — failed ' . $job['consecutive_failures'] . ' time(s) in a row: '
                        . mb_substr((string) $job['last_message'], 0, 80);
        }

        if ($problems === []) {
            return ['processed' => 0, 'message' => 'all ' . $health['total'] . ' job(s) healthy'];
        }

        $this->alert($problems);

        return [
            'processed' => count($problems),
            'message'   => count($problems) . ' problem(s): ' . mb_substr(implode('; ', $problems), 0, 300),
        ];
    }

    /**
     * At most one alert an hour. A scheduler in trouble can produce the same
     * complaint every fifteen minutes, and an owner who mutes the alert channel
     * is worse off than one who was never told.
     */
    private function alert(array $problems): void
    {
        $number = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($number === '' || !RateLimiter::attempt('cron_alert', 1, 3600)) {
            return;
        }

        $lines = ['⚠️ *Krishna Reminder — scheduler alert*', ''];

        foreach ($problems as $problem) {
            $lines[] = '• ' . $problem;
        }

        $lines[] = '';
        $lines[] = App::i()->url('/admin/cron');

        WhatsAppService::queue($number, implode("\n", $lines), null, null, 1, 'system_alert');
    }
}
