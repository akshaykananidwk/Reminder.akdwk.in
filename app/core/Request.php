<?php

namespace App\Core;

/**
 * Request accessors with sane defaults and trimming.
 */
final class Request
{
    private static ?array $jsonBody = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return '/' . trim($path, '/');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            $value = $_POST[$key];

            return is_string($value) ? trim($value) : $value;
        }

        $json = self::json();

        if (is_array($json) && array_key_exists($key, $json)) {
            $value = $json[$key];

            return is_string($value) ? trim($value) : $value;
        }

        return $default;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $value = self::post($key, null);

        return $value === null ? self::get($key, $default) : $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::input($key, null);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::input($key, null);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) (is_bool($value) ? ($value ? '1' : '0') : $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function arr(string $key, array $default = []): array
    {
        $value = self::input($key, null);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
        }

        return $default;
    }

    public static function json(): ?array
    {
        if (self::$jsonBody !== null) {
            return self::$jsonBody;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        if (!str_contains(strtolower($contentType), 'json')) {
            self::$jsonBody = [];

            return self::$jsonBody;
        }

        $raw = self::rawBody();
        $decoded = json_decode($raw, true);
        self::$jsonBody = is_array($decoded) ? $decoded : [];

        return self::$jsonBody;
    }

    public static function rawBody(): string
    {
        static $raw = null;

        if ($raw === null) {
            $raw = (string) file_get_contents('php://input');
        }

        return $raw;
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $_SERVER[$key] ?? $default;
    }

    /**
     * The bearer token, from wherever this server happens to put it.
     *
     * Apache does not pass Authorization to CGI/FastCGI — which is how PHP-FPM
     * runs on aaPanel and most shared hosts — so $_SERVER['HTTP_AUTHORIZATION']
     * is simply absent. The .htaccess rewrite puts it back, but under a
     * different key, and only if mod_rewrite is enabled. Every place it can
     * legitimately arrive is therefore checked, rather than assuming one.
     *
     * Getting this wrong is invisible in the obvious way: signing in still
     * works, because that is a POST body, and then every authenticated request
     * answers 401.
     */
    public static function bearerToken(): ?string
    {
        $header = null;

        foreach ([
            'HTTP_AUTHORIZATION',           // mod_php, or the SetEnvIf above
            'REDIRECT_HTTP_AUTHORIZATION',  // what the .htaccess rewrite produces
            'PHP_AUTH_DIGEST',
        ] as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $header = $_SERVER[$key];
                break;
            }
        }

        // Some SAPIs expose it only through this call.
        if ($header === null) {
            foreach (['apache_request_headers', 'getallheaders'] as $fn) {
                if (!function_exists($fn)) {
                    continue;
                }

                $headers = $fn();

                if (!is_array($headers)) {
                    continue;
                }

                foreach ($headers as $k => $v) {
                    if (strcasecmp((string) $k, 'Authorization') === 0 && is_string($v) && $v !== '') {
                        $header = $v;
                        break 2;
                    }
                }
            }
        }

        // Last resort: PHP splits Basic credentials out into their own keys.
        if ($header === null && !empty($_SERVER['PHP_AUTH_USER'])) {
            $header = 'Basic ' . base64_encode(
                $_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? '')
            );
        }

        if (is_string($header) && preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_contains($accept, 'application/json')
            || strtolower((string) self::header('X-Requested-With')) === 'xmlhttprequest'
            || str_starts_with(self::path(), '/api/');
    }

    public static function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
