<?php

namespace App\Core;

/**
 * Authentication for three audiences:
 *  - web users   (PHP session + optional 90-day remember cookie)
 *  - admins      (separate `admins` table, own session key)
 *  - mobile/API  (bearer access token + long-lived refresh token in `sessions`)
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $adminUser = null;
    private static ?array $apiSession = null;
    private static bool $resolved = false;

    public const ACCESS_TOKEN_TTL  = 2592000;   // 30 days
    public const REFRESH_TOKEN_TTL = 63072000;  // 2 years — "stay logged in forever"

    /* ---------------------------------------------------------------- Users */

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;

        $userId = (int) Session::get('user_id', 0);

        if ($userId > 0) {
            self::$user = self::loadUser($userId);

            if (self::$user === null) {
                Session::forget('user_id');
            }

            return self::$user;
        }

        self::$user = self::fromRememberCookie();

        return self::$user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(int $userId, bool $remember = false): void
    {
        Session::regenerate();
        Session::set('user_id', $userId);
        self::$resolved = false;
        self::$user = null;

        $db = App::i()->db();
        $db->update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => Request::ip()], 'id = :id', ['id' => $userId]);

        if ($remember) {
            self::issueRememberCookie($userId);
        }

        self::recordSession($userId, 'web');
    }

    public static function logout(): void
    {
        $token = $_COOKIE['kr_remember'] ?? null;

        if (is_string($token) && $token !== '') {
            try {
                App::i()->db()->query('UPDATE sessions SET revoked = 1 WHERE token_hash = ?', [hash('sha256', $token)]);
            } catch (\Throwable) {
                // Session row already gone.
            }
            setcookie('kr_remember', '', time() - 3600, '/', '', Request::isSecure(), true);
        }

        Session::destroy();
        self::$user = null;
        self::$resolved = true;
    }

    public static function requireUser(): array
    {
        $user = self::user();

        if ($user === null) {
            if (Request::wantsJson()) {
                Response::error('Authentication required', 401, 'UNAUTHENTICATED');
            }

            Session::set('_intended', Request::path());
            Response::redirect(App::i()->url('/login'));
        }

        if ((int) $user['is_active'] !== 1) {
            self::logout();
            Session::flash('error', Lang::get('auth.account_suspended'));
            Response::redirect(App::i()->url('/login'));
        }

        return $user;
    }

    /* --------------------------------------------------------------- Admins */

    public static function admin(): ?array
    {
        if (self::$adminUser !== null) {
            return self::$adminUser;
        }

        $adminId = (int) Session::get('admin_id', 0);

        if ($adminId <= 0) {
            return null;
        }

        $row = App::i()->db()->one('SELECT * FROM admins WHERE id = ? AND is_active = 1 AND deleted_at IS NULL', [$adminId]);
        self::$adminUser = $row;

        return self::$adminUser;
    }

    public static function loginAdmin(int $adminId): void
    {
        Session::regenerate();
        Session::set('admin_id', $adminId);
        self::$adminUser = null;

        App::i()->db()->update('admins', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => Request::ip(),
        ], 'id = :id', ['id' => $adminId]);
    }

    public static function logoutAdmin(): void
    {
        Session::forget('admin_id');
        Session::forget('impersonating_user_id');
        self::$adminUser = null;
    }

    public static function requireAdmin(): array
    {
        $admin = self::admin();

        if ($admin === null) {
            if (Request::wantsJson()) {
                Response::error('Admin authentication required', 401, 'UNAUTHENTICATED');
            }

            Response::redirect(App::i()->url('/admin/login'));
        }

        return $admin;
    }

    public static function isImpersonating(): bool
    {
        return (int) Session::get('impersonating_user_id', 0) > 0;
    }

    /* ------------------------------------------------------------ API tokens */

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: string, session_id: int}
     */
    public static function issueApiTokens(int $userId, ?int $deviceId = null): array
    {
        $access = Crypto::randomToken(32);
        $refresh = Crypto::randomToken(32);
        $now = time();

        $sessionId = App::i()->db()->insert('sessions', [
            'user_id'            => $userId,
            'type'               => 'api',
            'token_hash'         => hash('sha256', $access),
            'refresh_token_hash' => hash('sha256', $refresh),
            'device_id'          => $deviceId,
            'ip'                 => Request::ip(),
            'user_agent'         => Request::userAgent(),
            'expires_at'         => date('Y-m-d H:i:s', $now + self::ACCESS_TOKEN_TTL),
            'refresh_expires_at' => date('Y-m-d H:i:s', $now + self::REFRESH_TOKEN_TTL),
            'last_used_at'       => date('Y-m-d H:i:s', $now),
            'created_at'         => date('Y-m-d H:i:s', $now),
        ]);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_at'    => date('c', $now + self::ACCESS_TOKEN_TTL),
            'session_id'    => $sessionId,
        ];
    }

    /**
     * Rotate an access token using a valid refresh token.
     */
    public static function refreshApiTokens(string $refreshToken): ?array
    {
        $db = App::i()->db();

        $row = $db->one(
            'SELECT * FROM sessions WHERE refresh_token_hash = ? AND type = "api" AND revoked = 0 LIMIT 1',
            [hash('sha256', $refreshToken)]
        );

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['refresh_expires_at']) < time()) {
            $db->update('sessions', ['revoked' => 1], 'id = :id', ['id' => (int) $row['id']]);

            return null;
        }

        $access = Crypto::randomToken(32);
        $newRefresh = Crypto::randomToken(32);
        $now = time();

        $db->update('sessions', [
            'token_hash'         => hash('sha256', $access),
            'refresh_token_hash' => hash('sha256', $newRefresh),
            'expires_at'         => date('Y-m-d H:i:s', $now + self::ACCESS_TOKEN_TTL),
            'refresh_expires_at' => date('Y-m-d H:i:s', $now + self::REFRESH_TOKEN_TTL),
            'last_used_at'       => date('Y-m-d H:i:s', $now),
            'ip'                 => Request::ip(),
        ], 'id = :id', ['id' => (int) $row['id']]);

        return [
            'access_token'  => $access,
            'refresh_token' => $newRefresh,
            'expires_at'    => date('c', $now + self::ACCESS_TOKEN_TTL),
            'session_id'    => (int) $row['id'],
            'user_id'       => (int) $row['user_id'],
        ];
    }

    /**
     * Resolve the bearer token on API requests. Returns the user row or null.
     */
    public static function apiUser(): ?array
    {
        if (self::$apiSession !== null) {
            return self::$user;
        }

        $token = Request::bearerToken();

        if ($token === null) {
            return null;
        }

        $db = App::i()->db();

        $session = $db->one(
            'SELECT * FROM sessions WHERE token_hash = ? AND type = "api" AND revoked = 0 LIMIT 1',
            [hash('sha256', $token)]
        );

        if ($session === null || strtotime((string) $session['expires_at']) < time()) {
            return null;
        }

        $user = self::loadUser((int) $session['user_id']);

        if ($user === null) {
            return null;
        }

        self::$apiSession = $session;
        self::$user = $user;
        self::$resolved = true;

        // Throttle last_used writes to at most once a minute per session.
        if (strtotime((string) $session['last_used_at']) < time() - 60) {
            $db->update('sessions', ['last_used_at' => date('Y-m-d H:i:s'), 'ip' => Request::ip()], 'id = :id', ['id' => (int) $session['id']]);
        }

        return $user;
    }

    public static function apiSession(): ?array
    {
        return self::$apiSession;
    }

    public static function requireApiUser(): array
    {
        $user = self::apiUser();

        if ($user === null) {
            Response::error(Lang::get('api.unauthenticated'), 401, 'UNAUTHENTICATED');
        }

        if ((int) $user['is_active'] !== 1) {
            Response::error(Lang::get('auth.account_suspended'), 403, 'ACCOUNT_SUSPENDED');
        }

        return $user;
    }

    public static function revokeSession(int $sessionId, int $userId): bool
    {
        return App::i()->db()->update('sessions', ['revoked' => 1], 'id = :id AND user_id = :uid', [
            'id'  => $sessionId,
            'uid' => $userId,
        ]) > 0;
    }

    /* -------------------------------------------------------------- Helpers */

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, ?string $hash): bool
    {
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    private static function loadUser(int $userId): ?array
    {
        return App::i()->db()->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
    }

    private static function issueRememberCookie(int $userId): void
    {
        $token = Crypto::randomToken(32);
        $expires = time() + 7776000; // 90 days

        App::i()->db()->insert('sessions', [
            'user_id'    => $userId,
            'type'       => 'web',
            'token_hash' => hash('sha256', $token),
            'ip'         => Request::ip(),
            'user_agent' => Request::userAgent(),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'last_used_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        setcookie('kr_remember', $token, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => Request::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function fromRememberCookie(): ?array
    {
        $token = $_COOKIE['kr_remember'] ?? null;

        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            $row = App::i()->db()->one(
                'SELECT * FROM sessions WHERE token_hash = ? AND type = "web" AND revoked = 0 LIMIT 1',
                [hash('sha256', $token)]
            );
        } catch (\Throwable) {
            return null;
        }

        if ($row === null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $user = self::loadUser((int) $row['user_id']);

        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }

        Session::set('user_id', (int) $user['id']);
        App::i()->db()->update('sessions', ['last_used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $row['id']]);

        return $user;
    }

    private static function recordSession(int $userId, string $type): void
    {
        try {
            App::i()->db()->insert('sessions', [
                'user_id'      => $userId,
                'type'         => $type,
                'token_hash'   => hash('sha256', session_id() ?: Crypto::randomToken(16)),
                'ip'           => Request::ip(),
                'user_agent'   => Request::userAgent(),
                'expires_at'   => date('Y-m-d H:i:s', time() + 86400),
                'last_used_at' => date('Y-m-d H:i:s'),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Non-fatal: the device list simply misses this row.
        }
    }

    /** Reset cached state (used by the impersonation flow). */
    public static function flush(): void
    {
        self::$user = null;
        self::$adminUser = null;
        self::$apiSession = null;
        self::$resolved = false;
    }
}
