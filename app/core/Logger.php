<?php

namespace App\Core;

/**
 * File + database logging. File logging must never throw — it is the last line
 * of defence when the database itself is the thing that broke.
 */
final class Logger
{
    public static function write(string $channel, string $level, string $message, array $context = []): void
    {
        try {
            $dir = App::i()->root() . '/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $line = sprintf(
                "[%s] %s.%s: %s %s\n",
                date('Y-m-d H:i:s'),
                $channel,
                strtoupper($level),
                $message,
                $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
            );

            @file_put_contents($dir . '/' . $channel . '-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Swallow — logging must not cascade.
        }
    }

    public static function info(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write($channel, 'info', $message, $context);
    }

    public static function warn(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write($channel, 'warning', $message, $context);
    }

    public static function error(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write($channel, 'error', $message, $context);
        self::toDatabase('error', $message, $context);
    }

    public static function exception(\Throwable $e, string $channel = 'app'): void
    {
        $context = [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => substr($e->getTraceAsString(), 0, 4000),
        ];

        self::write($channel, 'exception', get_class($e) . ': ' . $e->getMessage(), $context);
        self::toDatabase('exception', get_class($e) . ': ' . $e->getMessage(), $context);
    }

    private static function toDatabase(string $level, string $message, array $context): void
    {
        try {
            $app = App::i();
            if (!$app->isInstalled()) {
                return;
            }

            $app->db()->insert('error_logs', [
                'level'      => $level,
                'message'    => mb_substr($message, 0, 1000),
                'file'       => mb_substr((string) ($context['file'] ?? ''), 0, 255),
                'line'       => (int) ($context['line'] ?? 0),
                'trace'      => mb_substr((string) ($context['trace'] ?? ''), 0, 60000),
                'url'        => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), 0, 255),
                'user_id'    => Auth::id(),
                'ip'         => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Ignore — file log already has it.
        }
    }
}
