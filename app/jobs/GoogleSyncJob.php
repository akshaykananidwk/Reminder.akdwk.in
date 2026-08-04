<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Logger;
use App\Services\GoogleService;

/**
 * Two-way Google Calendar sync for the users who have connected it.
 */
class GoogleSyncJob extends Job
{
    public static function key(): string
    {
        return 'google_sync';
    }

    public static function label(): string
    {
        return 'Google Calendar sync';
    }

    public static function description(): string
    {
        return 'Pushes reminders to, and pulls events from, each connected Google Calendar.';
    }

    public static function group(): string
    {
        return 'integrations';
    }

    public static function priority(): int
    {
        return 40;
    }

    public static function intervalSeconds(): int
    {
        return 900;
    }

    public static function timeoutSeconds(): int
    {
        return 600;
    }

    public function skipReason(): ?string
    {
        return GoogleService::isEnabled() ? null : 'Google integration is disabled';
    }

    public function handle(): array
    {
        $totals = ['pushed' => 0, 'pulled' => 0, 'deleted' => 0, 'errors' => 0];

        $accounts = App::i()->db()->all(
            'SELECT g.user_id
               FROM google_accounts g
               JOIN users u ON u.id = g.user_id
               JOIN user_settings s ON s.user_id = g.user_id
              WHERE g.is_active = 1 AND u.is_active = 1 AND u.deleted_at IS NULL AND s.google_sync_enabled = 1
              ORDER BY COALESCE(g.last_sync_at, "1970-01-01") ASC
              LIMIT 100'
        );

        foreach ($accounts as $account) {
            try {
                $result = GoogleService::syncUser((int) $account['user_id']);

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + ($result[$key] ?? 0);
                }
            } catch (\Throwable $e) {
                $totals['errors']++;
                Logger::warn('Google sync failed for user', [
                    'user_id' => $account['user_id'],
                    'error'   => $e->getMessage(),
                ], 'google');
            }
        }

        return [
            'processed' => $totals['pushed'] + $totals['pulled'],
            'message'   => sprintf(
                'accounts=%d pushed=%d pulled=%d deleted=%d errors=%d',
                count($accounts),
                $totals['pushed'],
                $totals['pulled'],
                $totals['deleted'],
                $totals['errors']
            ),
        ];
    }
}
