<?php

namespace App\Jobs;

use App\Services\SchedulerService;

/**
 * The reminder engine. Finds due occurrences and fires the call / push /
 * WhatsApp delivery chain, handles escalation retries and advance alerts, and
 * marks unanswered reminders missed.
 *
 * This is the product. If it stops for ten minutes, ten minutes of reminders
 * did not arrive — which is why it has the lowest priority number of any job
 * and never waits behind a backup.
 */
class DispatchRemindersJob extends Job
{
    public static function key(): string
    {
        return 'dispatcher';
    }

    public static function label(): string
    {
        return 'Reminder dispatch';
    }

    public static function description(): string
    {
        return 'Sends due reminders, escalation retries and advance alerts, and marks unanswered ones missed.';
    }

    public static function group(): string
    {
        return 'reminders';
    }

    public static function priority(): int
    {
        return 1;
    }

    public static function intervalSeconds(): int
    {
        return 60;
    }

    public static function timeoutSeconds(): int
    {
        return 120;
    }

    public function handle(): array
    {
        $result = SchedulerService::tick(300);

        return [
            'processed' => array_sum($result),
            'message'   => sprintf(
                'due=%d escalated=%d advance=%d missed=%d',
                $result['due'],
                $result['escalated'],
                $result['advance'],
                $result['missed']
            ),
        ];
    }
}
