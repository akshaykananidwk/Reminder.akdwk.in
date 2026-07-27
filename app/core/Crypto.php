<?php

namespace App\Core;

/**
 * Authenticated symmetric encryption (AES-256-GCM) for API keys, OAuth refresh
 * tokens and anything else that must not sit in the database in clear text.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function key(): string
    {
        $raw = (string) App::i()->config('app.key', '');

        if ($raw === '') {
            // Deterministic fallback so a half-installed system never fatals;
            // the installer always writes a real random key.
            return hash('sha256', 'krishna-reminder-fallback-' . App::i()->root(), true);
        }

        $decoded = base64_decode($raw, true);

        return ($decoded !== false && strlen($decoded) === 32) ? $decoded : hash('sha256', $raw, true);
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $cipherText = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipherText === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return 'v1.' . base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, 'v1.')) {
            return null;
        }

        $raw = base64_decode(substr($payload, 3), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);

        $plain = openssl_decrypt($cipherText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Short, human-friendly, unambiguous code (used for reminder short codes).
     */
    public static function shortCode(int $length = 3): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public static function hmac(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret);
    }

    public static function hashEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }
}
