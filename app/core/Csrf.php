<?php

namespace App\Core;

/**
 * Per-session CSRF token, verified on every state-changing request.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');

        if (!is_string($token) || $token === '') {
            $token = Crypto::randomToken(24);
            Session::set('_csrf', $token);
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(?string $given): bool
    {
        $token = Session::get('_csrf');

        if (!is_string($token) || $token === '' || !is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($token, $given);
    }

    /**
     * Verify the request or abort. Accepts the token from the form field or the
     * X-CSRF-Token header (used by fetch() calls).
     */
    public static function verifyOrFail(): void
    {
        $given = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!self::check(is_string($given) ? $given : null)) {
            Logger::warn('CSRF token mismatch', ['uri' => $_SERVER['REQUEST_URI'] ?? '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);

            http_response_code(419);

            if (Request::wantsJson()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Session expired. Please reload the page.', 'code' => 'CSRF_INVALID']);
            } else {
                echo 'Session expired. Please go back, reload the page and try again.';
            }

            exit;
        }
    }
}
