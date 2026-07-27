<?php

namespace App\Core;

/**
 * Response helpers. All API responses use the {success, data, message, code}
 * envelope required by the mobile client.
 */
final class Response
{
    public static function json(mixed $data = null, string $message = '', int $status = 200, string $code = 'OK'): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode([
            'success' => $status >= 200 && $status < 300,
            'data'    => $data,
            'message' => $message,
            'code'    => $code,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    public static function ok(mixed $data = null, string $message = ''): never
    {
        self::json($data, $message, 200, 'OK');
    }

    public static function error(string $message, int $status = 400, string $code = 'ERROR', mixed $data = null): never
    {
        self::json($data, $message, $status, $code);
    }

    public static function redirect(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }

        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';

        // Only follow same-host referers to avoid open redirects.
        if ($referer !== '' && $host !== '' && str_contains((string) parse_url($referer, PHP_URL_HOST), $host)) {
            self::redirect($referer);
        }

        self::redirect($fallback);
    }

    public static function notFound(string $message = 'Not found'): never
    {
        if (Request::wantsJson()) {
            self::error($message, 404, 'NOT_FOUND');
        }

        http_response_code(404);
        $view = App::i()->root() . '/app/views/errors/404.php';

        if (is_file($view)) {
            require $view;
        } else {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }

        exit;
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        if (Request::wantsJson()) {
            self::error($message, 403, 'FORBIDDEN');
        }

        http_response_code(403);
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        exit;
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('X-XSS-Protection: 0');

        if (Request::isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data: https:; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
            . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.googletagmanager.com; "
            . "connect-src 'self' https://www.google-analytics.com; "
            . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
        );
    }

    public static function download(string $filePath, string $downloadName, string $mime = 'application/octet-stream'): never
    {
        if (!is_file($filePath)) {
            self::notFound('File not found');
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    public static function stream(string $content, string $downloadName, string $mime): never
    {
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
        header('Content-Length: ' . strlen($content));
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }
}
