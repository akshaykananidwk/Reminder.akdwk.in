<?php

namespace App\Services;

use App\Core\App;

/**
 * File-based cache. No Redis on the target hosting, and APCu is not guaranteed,
 * so a plain serialised-file store keeps things dependency free.
 */
class CacheService
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $file = self::path($key);

        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return $default;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($payload) || !isset($payload['expires'], $payload['value'])) {
            return $default;
        }

        if ($payload['expires'] > 0 && $payload['expires'] < time()) {
            @unlink($file);

            return $default;
        }

        return $payload['value'];
    }

    public static function put(string $key, mixed $value, int $ttlSeconds = 900): bool
    {
        $dir = self::dir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $payload = serialize([
            'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
            'value'   => $value,
        ]);

        return @file_put_contents(self::path($key), $payload, LOCK_EX) !== false;
    }

    public static function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $cached = self::get($key, null);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        self::put($key, $value, $ttlSeconds);

        return $value;
    }

    public static function forget(string $key): void
    {
        @unlink(self::path($key));
    }

    public static function flush(): int
    {
        $count = 0;

        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /** Remove only entries whose TTL has passed (used by cron/cleanup.php). */
    public static function purgeExpired(): int
    {
        $count = 0;

        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);

            if ($raw === false) {
                continue;
            }

            $payload = @unserialize($raw, ['allowed_classes' => false]);

            if (!is_array($payload) || (($payload['expires'] ?? 0) > 0 && $payload['expires'] < time())) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function dir(): string
    {
        return App::i()->root() . '/storage/cache';
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.cache';
    }
}
