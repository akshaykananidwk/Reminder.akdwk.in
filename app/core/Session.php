<?php

namespace App\Core;

/**
 * Hardened PHP session handling plus flash messages.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = (bool) App::i()->config('security.cookie_secure', true)
            && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_name('KRSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', '86400');

        session_start();
        self::$started = true;

        // Rotate the session id periodically to limit fixation windows.
        //
        // Only while the response headers can still be changed. session_
        // regenerate_id(true) deletes the old session immediately, so if the
        // new id cannot reach the browser as a Set-Cookie the user is left
        // holding an id that no longer exists — i.e. silently signed out. That
        // is exactly what happens when a page has already started sending
        // output and then fails.
        $now = time();

        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = $now;
        } elseif ($now - (int) $_SESSION['_created'] > 1800 && !headers_sent()) {
            session_regenerate_id(true);
            $_SESSION['_created'] = $now;
        }
    }

    /**
     * Write the session and release its lock, without ending it.
     *
     * PHP holds an exclusive lock on the session file for the whole request, so
     * a job that takes minutes — an update, a backup — freezes every other
     * request from that same admin until it finishes. Long work should pause
     * the session and resume it only when it needs to write again.
     */
    public static function pause(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::$started = false;
    }

    /** Re-open a paused session so it can be written to again. */
    public static function resume(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::$started = false;
            self::start();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
        self::$started = false;
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function takeFlash(): array
    {
        self::start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flash;
    }

    /** Remember form input across a failed validation redirect. */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirm'], $input['csrf_token']);
        self::set('_old_input', $input);
    }

    public static function oldInput(string $key, mixed $default = ''): mixed
    {
        self::start();

        return $_SESSION['_old_input'][$key] ?? $default;
    }

    public static function clearOldInput(): void
    {
        self::forget('_old_input');
    }
}
