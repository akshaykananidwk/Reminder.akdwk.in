<?php

namespace App\Services;

use App\Core\App;

/**
 * Renders admin-editable message templates with {variable} substitution and a
 * language fallback chain (requested → gu → en → key).
 */
class TemplateService
{
    private static array $cache = [];

    public static function render(string $key, string $lang = 'gu', array $vars = [], string $channel = 'whatsapp'): string
    {
        $body = self::body($key, $lang, $channel);

        if ($body === null) {
            return '';
        }

        foreach ($vars as $name => $value) {
            $body = str_replace('{' . $name . '}', (string) $value, $body);
        }

        // Drop any placeholder that was not supplied so users never see {foo}.
        $body = preg_replace('/\{[a-z_]+\}/i', '', $body) ?? $body;

        return trim($body);
    }

    public static function body(string $key, string $lang, string $channel = 'whatsapp'): ?string
    {
        $cacheKey = $key . '|' . $lang . '|' . $channel;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $db = App::i()->db();

        $row = $db->one(
            'SELECT body FROM templates WHERE template_key = ? AND lang = ? AND channel = ? AND is_active = 1',
            [$key, $lang, $channel]
        );

        if ($row === null && $lang !== 'gu') {
            $row = $db->one(
                'SELECT body FROM templates WHERE template_key = ? AND lang = "gu" AND channel = ? AND is_active = 1',
                [$key, $channel]
            );
        }

        if ($row === null) {
            $row = $db->one(
                'SELECT body FROM templates WHERE template_key = ? AND lang = "en" AND channel = ? AND is_active = 1',
                [$key, $channel]
            );
        }

        self::$cache[$cacheKey] = $row['body'] ?? null;

        return self::$cache[$cacheKey];
    }

    public static function exists(string $key, string $lang = 'gu', string $channel = 'whatsapp'): bool
    {
        return self::body($key, $lang, $channel) !== null;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** All template keys the product ships with, in display order. */
    public static function keys(): array
    {
        return [
            'otp', 'welcome', 'reminder_created', 'reminder_updated', 'reminder_due',
            'reminder_missed', 'reminder_done', 'reminder_snoozed', 'morning_brief',
            'night_summary', 'payment_due', 'payment_received', 'weekly_report',
            'assignment_received', 'subscription_expiring', 'subscription_expired',
            'plan_upgraded', 'unknown_number_invite', 'quota_exceeded', 'help',
        ];
    }
}
