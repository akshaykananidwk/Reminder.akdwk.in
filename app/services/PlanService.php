<?php

namespace App\Services;

use App\Core\App;

/**
 * Plan limits and quota metering. A user with no plan (or an expired one past
 * the grace period) falls back to the default plan so the product degrades
 * gracefully instead of erroring.
 */
class PlanService
{
    private static array $planCache = [];

    public static function forUser(int $userId): array
    {
        if (isset(self::$planCache[$userId])) {
            return self::$planCache[$userId];
        }

        $db = App::i()->db();

        $row = $db->one(
            'SELECT p.* FROM users u JOIN plans p ON p.id = u.plan_id WHERE u.id = ?',
            [$userId]
        );

        if ($row === null) {
            $row = $db->one('SELECT * FROM plans WHERE is_default = 1 AND is_active = 1 ORDER BY sort_order LIMIT 1')
                ?? $db->one('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order LIMIT 1')
                ?? self::hardcodedFallback();
        }

        self::$planCache[$userId] = $row;

        return $row;
    }

    /**
     * A plan is only "live" while the subscription has not expired past the
     * configured grace period.
     */
    public static function isActive(int $userId): bool
    {
        $user = App::i()->db()->one('SELECT plan_expires_at FROM users WHERE id = ?', [$userId]);

        if ($user === null || $user['plan_expires_at'] === null) {
            return true;
        }

        $grace = App::i()->settings()->int('grace_days', 3) * 86400;

        return strtotime((string) $user['plan_expires_at']) + $grace >= time();
    }

    public static function limit(int $userId, string $key, int $default = 0): int
    {
        $plan = self::forUser($userId);

        return array_key_exists($key, $plan) ? (int) $plan[$key] : $default;
    }

    public static function can(int $userId, string $feature): bool
    {
        $plan = self::forUser($userId);

        return (int) ($plan[$feature] ?? 0) === 1 && self::isActive($userId);
    }

    /* ------------------------------------------------------------ Metering */

    public static function withinAiQuota(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!self::isActive($userId)) {
            return false;
        }

        $plan = self::forUser($userId);
        $monthStart = date('Y-m-01 00:00:00');

        $maxMessages = (int) ($plan['max_ai_messages_month'] ?? 200);

        if ($maxMessages >= 0) {
            $used = (int) App::i()->db()->value(
                'SELECT COUNT(*) FROM ai_logs WHERE user_id = ? AND cached = 0 AND created_at >= ?',
                [$userId, $monthStart],
                0
            );

            if ($used >= $maxMessages) {
                return false;
            }
        }

        $maxTokens = (int) ($plan['max_ai_tokens_month'] ?? 0);

        if ($maxTokens > 0) {
            $tokens = (int) App::i()->db()->value(
                'SELECT COALESCE(SUM(total_tokens), 0) FROM ai_logs WHERE user_id = ? AND created_at >= ?',
                [$userId, $monthStart],
                0
            );

            if ($tokens >= $maxTokens) {
                return false;
            }
        }

        return true;
    }

    public static function withinReminderQuota(int $userId): bool
    {
        $max = self::limit($userId, 'max_reminders_month', 200);

        if ($max < 0) {
            return true;
        }

        $used = (int) App::i()->db()->value(
            'SELECT COUNT(*) FROM reminders WHERE user_id = ? AND created_at >= ?',
            [$userId, date('Y-m-01 00:00:00')],
            0
        );

        return $used < $max;
    }

    public static function canAddDevice(int $userId): bool
    {
        $max = self::limit($userId, 'max_devices', 1);

        if ($max < 0) {
            return true;
        }

        $count = (int) App::i()->db()->value(
            'SELECT COUNT(*) FROM devices WHERE user_id = ? AND is_active = 1',
            [$userId],
            0
        );

        return $count < $max;
    }

    public static function canAssignStaff(int $userId): bool
    {
        return self::limit($userId, 'max_staff', 0) > 0 && self::isActive($userId);
    }

    /**
     * Usage snapshot for the billing screen and the admin user profile.
     */
    public static function usage(int $userId): array
    {
        $db = App::i()->db();
        $plan = self::forUser($userId);
        $monthStart = date('Y-m-01 00:00:00');

        return [
            'plan'          => $plan,
            'reminders'     => (int) $db->value('SELECT COUNT(*) FROM reminders WHERE user_id = ? AND created_at >= ?', [$userId, $monthStart], 0),
            'ai_messages'   => (int) $db->value('SELECT COUNT(*) FROM ai_logs WHERE user_id = ? AND cached = 0 AND created_at >= ?', [$userId, $monthStart], 0),
            'ai_tokens'     => (int) $db->value('SELECT COALESCE(SUM(total_tokens),0) FROM ai_logs WHERE user_id = ? AND created_at >= ?', [$userId, $monthStart], 0),
            'ai_cost'       => (float) $db->value('SELECT COALESCE(SUM(cost),0) FROM ai_logs WHERE user_id = ? AND created_at >= ?', [$userId, $monthStart], 0),
            'devices'       => (int) $db->value('SELECT COUNT(*) FROM devices WHERE user_id = ? AND is_active = 1', [$userId], 0),
            'calls'         => (int) $db->value('SELECT COUNT(*) FROM deliveries WHERE user_id = ? AND kind = "call" AND created_at >= ?', [$userId, $monthStart], 0),
            'wa_messages'   => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_log WHERE user_id = ? AND created_at >= ?', [$userId, $monthStart], 0),
        ];
    }

    /**
     * Apply a plan to a user (used by admin approval and the billing flow).
     */
    public static function assign(int $userId, int $planId, int $days, ?int $adminId = null): void
    {
        $db = App::i()->db();
        $plan = $db->one('SELECT * FROM plans WHERE id = ?', [$planId]);

        if ($plan === null) {
            return;
        }

        $days = $days > 0 ? $days : (int) $plan['duration_days'];

        $user = $db->one('SELECT plan_expires_at FROM users WHERE id = ?', [$userId]);
        $base = ($user && $user['plan_expires_at'] && strtotime((string) $user['plan_expires_at']) > time())
            ? strtotime((string) $user['plan_expires_at'])
            : time();

        $expires = date('Y-m-d H:i:s', $base + ($days * 86400));

        $db->update('users', ['plan_id' => $planId, 'plan_expires_at' => $expires], 'id = :id', ['id' => $userId]);

        unset(self::$planCache[$userId]);

        AuditService::log('plan.assigned', 'user', $userId, [
            'plan_id' => $planId,
            'days'    => $days,
            'until'   => $expires,
        ], $adminId === null ? 'system' : 'admin', $adminId);
    }

    private static function hardcodedFallback(): array
    {
        return [
            'id' => 0, 'code' => 'trial', 'name' => 'Trial',
            'max_reminders_month' => 30, 'max_ai_messages_month' => 30, 'max_ai_tokens_month' => 60000,
            'max_devices' => 1, 'max_staff' => 0, 'call_reminders' => 1,
            'google_sync' => 0, 'api_access' => 0, 'full_reports' => 0,
            'duration_days' => 7, 'price' => 0, 'currency' => 'INR',
        ];
    }

    public static function flush(): void
    {
        self::$planCache = [];
    }
}
