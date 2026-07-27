<?php

namespace App\Core;

/**
 * Translation loader. Every user-facing string lives in /lang/{gu,hi,en}.php
 * and is looked up with dot notation, falling back to English then to the key.
 */
final class Lang
{
    public const SUPPORTED = ['en', 'gu', 'hi'];

    private static string $locale = 'en';
    private static ?string $forced = null;
    private static array $loaded = [];

    /**
     * Pin the whole site to one language, ignoring each user's own choice.
     *
     * Set from Admin → Settings. Without it, a per-user `language` column wins
     * over the site default, so changing the default alone leaves every
     * existing account exactly where it was — which looks like the setting did
     * nothing at all.
     */
    public static function forceLocale(?string $locale): void
    {
        self::$forced = in_array((string) $locale, self::SUPPORTED, true) ? (string) $locale : null;

        if (self::$forced !== null) {
            self::$locale = self::$forced;
        }
    }

    public static function isForced(): bool
    {
        return self::$forced !== null;
    }

    public static function setLocale(string $locale): void
    {
        // A forced language must survive every later call — logging in, the API
        // switching to the user's language, anything.
        if (self::$forced !== null) {
            self::$locale = self::$forced;

            return;
        }

        self::$locale = in_array($locale, self::SUPPORTED, true) ? $locale : 'en';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?: self::$locale;
        $value = self::lookup($key, $locale);

        if ($value === null && $locale !== 'en') {
            $value = self::lookup($key, 'en');
        }

        if ($value === null) {
            $value = $key;
        }

        foreach ($replace as $search => $replacement) {
            $value = str_replace(['{' . $search . '}', ':' . $search], (string) $replacement, $value);
        }

        return $value;
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return self::lookup($key, $locale ?: self::$locale) !== null;
    }

    /**
     * Whole translation map for a locale — used to bootstrap the JS layer.
     */
    public static function all(?string $locale = null): array
    {
        $locale = $locale ?: self::$locale;
        self::load($locale);

        return self::$loaded[$locale] ?? [];
    }

    public static function nativeName(string $locale): string
    {
        return match ($locale) {
            'gu' => 'ગુજરાતી',
            'hi' => 'हिन्दी',
            default => 'English',
        };
    }

    private static function lookup(string $key, string $locale): ?string
    {
        self::load($locale);

        $value = self::$loaded[$locale] ?? [];

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    private static function load(string $locale): void
    {
        if (isset(self::$loaded[$locale])) {
            return;
        }

        $file = App::i()->root() . '/lang/' . $locale . '.php';
        self::$loaded[$locale] = is_file($file) ? (array) require $file : [];
    }
}
