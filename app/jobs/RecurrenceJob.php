<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Logger;
use App\Services\RecurrenceService;

/**
 * Materialises repeating reminders into concrete occurrences, thirty days
 * ahead.
 *
 * A repeating reminder is a rule, not a row — the dispatcher can only fire
 * something that exists. If this stops, everything keeps working until the
 * materialised horizon runs out, and then every repeating reminder silently
 * stops at once. That delayed failure is exactly why it is monitored.
 */
class RecurrenceJob extends Job
{
    public static function key(): string
    {
        return 'recurrence';
    }

    public static function label(): string
    {
        return 'Repeating reminders';
    }

    public static function description(): string
    {
        return 'Creates the next 30 days of occurrences for every repeating reminder.';
    }

    public static function group(): string
    {
        return 'reminders';
    }

    public static function priority(): int
    {
        return 20;
    }

    public static function intervalSeconds(): int
    {
        return 3600;
    }

    public static function timeoutSeconds(): int
    {
        return 300;
    }

    public function handle(): array
    {
        $db = App::i()->db();
        $created = 0;
        $scanned = 0;

        $horizon = date('Y-m-d H:i:s', time() + (25 * 86400));

        $reminders = $db->all(
            'SELECT r.*, u.timezone
               FROM reminders r
               JOIN users u ON u.id = r.user_id
              WHERE r.status = "active"
                AND r.deleted_at IS NULL
                AND u.is_active = 1
                AND u.deleted_at IS NULL
                AND r.recurrence IS NOT NULL
                AND JSON_UNQUOTE(JSON_EXTRACT(r.recurrence, "$.freq")) <> "none"
                AND (r.materialised_until IS NULL OR r.materialised_until < ?)
              ORDER BY r.id ASC
              LIMIT 500',
            [$horizon]
        );

        foreach ($reminders as $reminder) {
            $scanned++;

            try {
                $created += RecurrenceService::materialise($reminder, 30);
            } catch (\Throwable $e) {
                Logger::warn('Materialise failed', [
                    'reminder_id' => $reminder['id'],
                    'error'       => $e->getMessage(),
                ], 'recurrence');
            }
        }

        // Also catch one-off reminders whose occurrence row went missing.
        $orphans = $db->all(
            'SELECT r.*, u.timezone
               FROM reminders r
               JOIN users u ON u.id = r.user_id
               LEFT JOIN reminder_occurrences o ON o.reminder_id = r.id
              WHERE r.status = "active" AND r.deleted_at IS NULL AND o.id IS NULL AND r.start_at > ?
              LIMIT 200',
            [now_utc()]
        );

        foreach ($orphans as $reminder) {
            $created += RecurrenceService::materialise($reminder, 30);
            $scanned++;
        }

        return [
            'processed' => $created,
            'message'   => sprintf('scanned=%d created=%d', $scanned, $created),
        ];
    }
}
