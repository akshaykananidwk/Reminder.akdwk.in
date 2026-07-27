<?php

namespace App\Core;

/**
 * Key/value settings store backed by the `settings` table with an in-request cache.
 * Values flagged `is_encrypted` are transparently encrypted at rest.
 */
class Settings
{
    private array $cache = [];
    private bool $loaded = false;

    public function __construct(private Database $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->cache[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        return ($value === null || $value === '') ? $default : (float) $value;
    }

    public function json(string $key, array $default = []): array
    {
        $value = $this->get($key);

        if (!is_string($value) || $value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    public function set(string $key, mixed $value, bool $encrypted = false, string $group = 'general'): void
    {
        $stored = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        $raw = $stored;

        if ($encrypted && $stored !== '') {
            $stored = Crypto::encrypt($stored);
        }

        $this->db->upsert('settings', [
            'setting_key'  => $key,
            'setting_value'=> $stored,
            'setting_group'=> $group,
            'is_encrypted' => $encrypted ? 1 : 0,
            'updated_at'   => date('Y-m-d H:i:s'),
        ], ['setting_value', 'setting_group', 'is_encrypted', 'updated_at']);

        $this->cache[$key] = $raw;
    }

    public function setMany(array $pairs, array $encryptedKeys = [], string $group = 'general'): void
    {
        foreach ($pairs as $key => $value) {
            $this->set($key, $value, in_array($key, $encryptedKeys, true), $group);
        }
    }

    public function group(string $group): array
    {
        $this->load();
        $rows = $this->db->all('SELECT setting_key FROM settings WHERE setting_group = ?', [$group]);

        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $this->cache[$row['setting_key']] ?? null;
        }

        return $out;
    }

    public function refresh(): void
    {
        $this->loaded = false;
        $this->cache = [];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            $rows = $this->db->all('SELECT setting_key, setting_value, is_encrypted FROM settings');
        } catch (\Throwable) {
            return; // Table not yet installed.
        }

        foreach ($rows as $row) {
            $value = $row['setting_value'];

            if ((int) $row['is_encrypted'] === 1 && is_string($value) && $value !== '') {
                $value = Crypto::decrypt($value) ?? '';
            }

            $this->cache[$row['setting_key']] = $value;
        }
    }
}
