<?php

namespace App\Jobs;

use App\Core\Logger;
use App\Services\ReminderService;
use App\Services\SummaryService;
use App\Services\WhatsAppService;

/**
 * The night summary, and the daily streak roll-forward.
 *
 * The streak update lives here rather than in its own job because it has to
 * happen exactly once per user per local day, and this is already the thing
 * that runs exactly once per user per local day.
 */
class DailySummaryJob extends Job
{
    public static function key(): string
    {
        return 'daily_summary';
    }

    public static function label(): string
    {
        return 'Night summary';
    }

    public static function description(): string
    {
        return "Sends each user's end-of-day summary at their local time and rolls their streak forward.";
    }

    public static function group(): string
    {
        return 'summaries';
    }

    public static function priority(): int
    {
        return 31;
    }

    public static function intervalSeconds(): int
    {
        return 300;
    }

    public function handle(): array
    {
        $sent = 0;

        foreach (SummaryService::usersDueFor('night', 5) as $user) {
            try {
                ReminderService::updateStreak((int) $user['id']);

                $body = SummaryService::buildNightSummary((int) $user['id'], $user, (string) $user['local_date']);

                if ($body !== '') {
                    WhatsAppService::queue((string) $user['phone'], $body, (int) $user['id'], null, 5, 'night_summary');
                    $sent++;
                }

                SummaryService::markSent((int) $user['id'], (string) $user['local_date'], 'night');
            } catch (\Throwable $e) {
                Logger::warn('Night summary failed', [
                    'user_id' => $user['id'],
                    'error'   => $e->getMessage(),
                ], 'summary');
            }
        }

        return ['processed' => $sent, 'message' => 'sent=' . $sent];
    }
}
