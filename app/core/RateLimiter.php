<?php

namespace App\Core;

/**
 * Database-backed sliding-window rate limiter (no Redis available on target hosts).
 * Used for OTP requests, login attempts, API calls and the inbound webhook.
 */
final class RateLimiter
{
    /**
     * @return bool true when the action is allowed
     */
    public static function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $db = App::i()->db();
        $now = time();

        try {
            $row = $db->one('SELECT id, attempts, window_start FROM rate_limits WHERE rl_key = ?', [$key]);

            if ($row === null) {
                $db->insert('rate_limits', [
                    'rl_key'       => $key,
                    'attempts'     => 1,
                    'window_start' => date('Y-m-d H:i:s', $now),
                    'created_at'   => date('Y-m-d H:i:s', $now),
                ]);

                return true;
            }

            $windowStart = strtotime((string) $row['window_start']);

            if ($now - $windowStart >= $decaySeconds) {
                $db->update('rate_limits', [
                    'attempts'     => 1,
                    'window_start' => date('Y-m-d H:i:s', $now),
                ], 'id = :id', ['id' => (int) $row['id']]);

                return true;
            }

            if ((int) $row['attempts'] >= $maxAttempts) {
                return false;
            }

            $db->query('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);

            return true;
        } catch (\Throwable $e) {
            Logger::error('Rate limiter failure', ['error' => $e->getMessage()]);

            // Fail open rather than locking every user out of the product.
            return true;
        }
    }

    public static function remaining(string $key, int $maxAttempts, int $decaySeconds): int
    {
        try {
            $row = App::i()->db()->one('SELECT attempts, window_start FROM rate_limits WHERE rl_key = ?', [$key]);
        } catch (\Throwable) {
            return $maxAttempts;
        }

        if ($row === null) {
            return $maxAttempts;
        }

        if (time() - strtotime((string) $row['window_start']) >= $decaySeconds) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - (int) $row['attempts']);
    }

    public static function secondsUntilReset(string $key, int $decaySeconds): int
    {
        try {
            $row = App::i()->db()->one('SELECT window_start FROM rate_limits WHERE rl_key = ?', [$key]);
        } catch (\Throwable) {
            return 0;
        }

        if ($row === null) {
            return 0;
        }

        return max(0, $decaySeconds - (time() - strtotime((string) $row['window_start'])));
    }

    public static function clear(string $key): void
    {
        try {
            App::i()->db()->delete('rate_limits', 'rl_key = ?', [$key]);
        } catch (\Throwable) {
            // Nothing to do.
        }
    }

    public static function purgeExpired(int $olderThanSeconds = 86400): int
    {
        try {
            return App::i()->db()->delete('rate_limits', 'window_start < ?', [date('Y-m-d H:i:s', time() - $olderThanSeconds)]);
        } catch (\Throwable) {
            return 0;
        }
    }
}
