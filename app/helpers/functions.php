<?php

use App\Core\App;
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Session;

if (!function_exists('e')) {
    /** HTML-escape for output. Use on every dynamic value in a view. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        return Lang::get($key, $replace, $locale);
    }
}

if (!function_exists('t')) {
    /** Escaped translation — the common case inside views. */
    function t(string $key, array $replace = [], ?string $locale = null): string
    {
        return e(Lang::get($key, $replace, $locale));
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return App::i()->url($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $version = App::i()->settings()->get('asset_version', App::i()->config('app.version', '1.0.0'));

        return App::i()->url('/assets/' . ltrim($path, '/')) . '?v=' . rawurlencode((string) $version);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return App::i()->settings()->get($key, $default);
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        return e(Session::oldInput($key, $default));
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return App::i()->config($key, $default);
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Normalise any WhatsApp-ish phone representation to bare digits with a
     * country code: "+91 98765-43210", "09876543210", "919876543210@c.us" all
     * become "919876543210".
     */
    function normalize_phone(?string $raw, string $defaultCountry = '91'): string
    {
        if ($raw === null) {
            return '';
        }

        $value = strtolower(trim($raw));
        $value = str_replace(['whatsapp:', '@c.us', '@s.whatsapp.net', '@g.us'], '', $value);
        $value = preg_replace('/\D+/', '', $value) ?? '';

        if ($value === '') {
            return '';
        }

        // Strip Indian trunk prefix / 00 international prefix.
        if (str_starts_with($value, '00')) {
            $value = substr($value, 2);
        }

        if (strlen($value) === 11 && str_starts_with($value, '0')) {
            $value = substr($value, 1);
        }

        if (strlen($value) === 10) {
            $value = $defaultCountry . $value;
        }

        return $value;
    }
}

if (!function_exists('display_phone')) {
    function display_phone(?string $number): string
    {
        $n = normalize_phone($number);

        if (strlen($n) === 12 && str_starts_with($n, '91')) {
            return '+91 ' . substr($n, 2, 5) . ' ' . substr($n, 7);
        }

        return $n === '' ? '' : '+' . $n;
    }
}

if (!function_exists('money')) {
    function money(float|int|string|null $amount, string $currency = 'INR'): string
    {
        $amount = (float) ($amount ?? 0);
        $symbol = match (strtoupper($currency)) {
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($currency) . ' ',
        };

        return $symbol . number_format($amount, ($amount == (int) $amount) ? 0 : 2);
    }
}

if (!function_exists('user_tz')) {
    function user_tz(?array $user = null): string
    {
        $user ??= Auth::user();

        return (string) ($user['timezone'] ?? App::i()->config('app.timezone', 'Asia/Kolkata'));
    }
}

if (!function_exists('to_user_time')) {
    /** Convert a UTC datetime string to the user's local time. */
    function to_user_time(?string $utc, string $format = 'd M Y, h:i A', ?string $tz = null): string
    {
        if ($utc === null || $utc === '' || $utc === '0000-00-00 00:00:00') {
            return '';
        }

        try {
            $dt = new DateTime($utc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($tz ?: user_tz()));

            return $dt->format($format);
        } catch (Throwable) {
            return (string) $utc;
        }
    }
}

if (!function_exists('to_utc')) {
    /** Convert a local datetime string in $tz to a UTC 'Y-m-d H:i:s' string. */
    function to_utc(string $local, ?string $tz = null): string
    {
        try {
            $dt = new DateTime($local, new DateTimeZone($tz ?: user_tz()));
            $dt->setTimezone(new DateTimeZone('UTC'));

            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('now_utc')) {
    function now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('human_diff')) {
    /** "in 2 hours" / "3 days ago", translated. */
    function human_diff(?string $utc, ?string $locale = null): string
    {
        if (!$utc) {
            return '';
        }

        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return '';
        }

        $diff = $ts - time();
        $future = $diff > 0;
        $abs = abs($diff);

        $unit = match (true) {
            $abs < 60      => ['just_now', 0],
            $abs < 3600    => ['minute', (int) floor($abs / 60)],
            $abs < 86400   => ['hour', (int) floor($abs / 3600)],
            $abs < 2592000 => ['day', (int) floor($abs / 86400)],
            $abs < 31536000 => ['month', (int) floor($abs / 2592000)],
            default        => ['year', (int) floor($abs / 31536000)],
        };

        if ($unit[0] === 'just_now') {
            return Lang::get('time.just_now', [], $locale);
        }

        $label = Lang::get('time.' . $unit[0] . ($unit[1] > 1 ? 's' : ''), ['n' => $unit[1]], $locale);

        return Lang::get($future ? 'time.in' : 'time.ago', ['time' => $label], $locale);
    }
}

if (!function_exists('array_get')) {
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('json_field')) {
    /** Decode a JSON column safely. */
    function json_field(mixed $value, array $default = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $limit = 100, string $end = '…'): string
    {
        $value = (string) $value;

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit) . $end;
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\pN]+~u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = mb_strtolower($text);

        return $text === '' ? 'n-a' : $text;
    }
}

if (!function_exists('is_gujarati')) {
    function is_gujarati(string $text): bool
    {
        return (bool) preg_match('/[\x{0A80}-\x{0AFF}]/u', $text);
    }
}

if (!function_exists('is_devanagari')) {
    function is_devanagari(string $text): bool
    {
        return (bool) preg_match('/[\x{0900}-\x{097F}]/u', $text);
    }
}

if (!function_exists('detect_language')) {
    function detect_language(string $text, string $default = 'en'): string
    {
        if (is_gujarati($text)) {
            return 'gu';
        }

        if (is_devanagari($text)) {
            return 'hi';
        }

        return $default;
    }
}

if (!function_exists('status_badge')) {
    function status_badge(string $status): string
    {
        return match ($status) {
            'done', 'paid', 'completed', 'active' => 'success',
            'pending', 'notified', 'partial'      => 'warning',
            'missed', 'overdue', 'failed', 'expired' => 'danger',
            'snoozed', 'processing'               => 'info',
            default                               => 'secondary',
        };
    }
}
