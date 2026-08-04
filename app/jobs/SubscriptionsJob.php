<?php

namespace App\Jobs;

use App\Core\App;
use App\Services\WhatsAppService;

/**
 * Plan expiry: warnings at 7, 3 and 1 day, then downgrade past the grace
 * period.
 *
 * Daily rather than hourly, because a customer warned three times in one day
 * unsubscribes, and because "7 days left" is a fact about a date, not about a
 * moment.
 */
class SubscriptionsJob extends Job
{
    public static function key(): string
    {
        return 'subscriptions';
    }

    public static function label(): string
    {
        return 'Subscription & plan expiry';
    }

    public static function description(): string
    {
        return 'Warns customers before their plan expires and downgrades them once the grace period has passed.';
    }

    public static function group(): string
    {
        return 'billing';
    }

    public static function priority(): int
    {
        return 60;
    }

    public static function scheduleKind(): string
    {
        return 'daily';
    }

    public static function runAt(): ?string
    {
        return '09:00';
    }

    public static function timeoutSeconds(): int
    {
        return 600;
    }

    public function handle(): array
    {
        $db = App::i()->db();
        $settings = App::i()->settings();
        $warned = 0;
        $expired = 0;

        /* ---- Expiry warnings at 7, 3 and 1 day ---- */

        foreach ([7, 3, 1] as $days) {
            $from = date('Y-m-d 00:00:00', strtotime("+$days days"));
            $to = date('Y-m-d 23:59:59', strtotime("+$days days"));

            $users = $db->all(
                'SELECT u.*, p.name AS plan_name
                   FROM users u
                   LEFT JOIN plans p ON p.id = u.plan_id
                  WHERE u.is_active = 1 AND u.deleted_at IS NULL
                    AND u.plan_expires_at BETWEEN ? AND ?',
                [$from, $to]
            );

            foreach ($users as $user) {
                WhatsAppService::queueTemplate('subscription_expiring', $user, [
                    'name' => (string) $user['name'],
                    'plan' => (string) ($user['plan_name'] ?? ''),
                    'days' => $days,
                    'url'  => App::i()->url('/client/billing'),
                ], 6);

                $warned++;
            }
        }

        /* ---- Expire past the grace period ---- */

        $graceDays = max(0, $settings->int('grace_days', 3));
        $cutoff = date('Y-m-d H:i:s', time() - ($graceDays * 86400));

        $defaultPlan = $db->one('SELECT id FROM plans WHERE is_default = 1 AND is_active = 1 LIMIT 1');

        $lapsed = $db->all(
            'SELECT u.*, p.name AS plan_name
               FROM users u
               LEFT JOIN plans p ON p.id = u.plan_id
              WHERE u.is_active = 1 AND u.deleted_at IS NULL
                AND u.plan_expires_at IS NOT NULL
                AND u.plan_expires_at < ?
                AND (u.plan_id IS NULL OR u.plan_id <> ?)',
            [$cutoff, (int) ($defaultPlan['id'] ?? 0)]
        );

        foreach ($lapsed as $user) {
            WhatsAppService::queueTemplate('subscription_expired', $user, [
                'name' => (string) $user['name'],
                'plan' => (string) ($user['plan_name'] ?? ''),
                'url'  => App::i()->url('/client/billing'),
            ], 5);

            if ($defaultPlan !== null) {
                $db->update('users', ['plan_id' => (int) $defaultPlan['id']], 'id = :id', ['id' => (int) $user['id']]);
            }

            $db->query(
                'UPDATE subscriptions SET status = "expired" WHERE user_id = ? AND status = "active" AND ends_at < ?',
                [(int) $user['id'], now_utc()]
            );

            $expired++;
        }

        return [
            'processed' => $warned + $expired,
            'message'   => sprintf('warned=%d expired=%d', $warned, $expired),
        ];
    }
}
