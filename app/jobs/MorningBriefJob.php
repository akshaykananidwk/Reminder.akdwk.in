<?php

namespace App\Jobs;

use App\Core\Logger;
use App\Services\SummaryService;
use App\Services\WhatsAppService;

/**
 * Each user's morning brief, sent at their own local time.
 *
 * Runs every five minutes rather than once a day because "their own local
 * time" means there is no single hour at which this is due — a user in Dwarka
 * and one in Dubai want it at different moments.
 */
class MorningBriefJob extends Job
{
    public static function key(): string
    {
        return 'morning_brief';
    }

    public static function label(): string
    {
        return 'Morning brief';
    }

    public static function description(): string
    {
        return "Sends each user's list of the day, at the local time they chose.";
    }

    public static function group(): string
    {
        return 'summaries';
    }

    public static function priority(): int
    {
        return 30;
    }

    public static function intervalSeconds(): int
    {
        return 300;
    }

    public function handle(): array
    {
        $sent = 0;

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
            } catch (\Throwable $e) {
                Logger::warn('Morning brief failed', [
                    'user_id' => $user['id'],
                    'error'   => $e->getMessage(),
                ], 'summary');
            }
        }

        return ['processed' => $sent, 'message' => 'sent=' . $sent];
    }
}
