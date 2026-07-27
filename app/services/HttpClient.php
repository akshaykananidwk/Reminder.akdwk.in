<?php

namespace App\Services;

use App\Core\Logger;

/**
 * Pure cURL HTTP client — the project deliberately avoids composer/Guzzle so it
 * runs on any shared host with only the curl extension enabled.
 */
class HttpClient
{
    /**
     * @return array{ok: bool, status: int, body: string, json: array|null, error: string|null, latency_ms: int}
     */
    public static function request(
        string $method,
        string $url,
        array $options = []
    ): array {
        $started = microtime(true);

        $headers = $options['headers'] ?? [];
        $timeout = (int) ($options['timeout'] ?? 20);
        $body = $options['body'] ?? null;
        $json = $options['json'] ?? null;
        $form = $options['form'] ?? null;

        $ch = curl_init();

        $finalUrl = $url;

        if (($options['query'] ?? null) && is_array($options['query'])) {
            $finalUrl .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        } elseif ($form !== null) {
            $body = http_build_query($form);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $finalUrl,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_USERAGENT      => 'KrishnaReminder/1.0 (+https://reminder.akdwk.in)',
            CURLOPT_ENCODING       => '',
        ]);

        if ($body !== null && strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if (!empty($options['save_to'])) {
            $fp = fopen($options['save_to'], 'wb');
            if ($fp !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($ch, CURLOPT_FILE, $fp);
            }
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
            $response = '';
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $raw = is_string($response) ? $response : '';
        $decoded = null;

        if ($raw !== '' && (str_starts_with(ltrim($raw), '{') || str_starts_with(ltrim($raw), '['))) {
            $tmp = json_decode($raw, true);
            $decoded = is_array($tmp) ? $tmp : null;
        }

        if ($error !== null) {
            Logger::warn('HTTP request failed', ['url' => $finalUrl, 'error' => $error], 'http');
        }

        return [
            'ok'         => $error === null && $status >= 200 && $status < 300,
            'status'     => $status,
            'body'       => $raw,
            'json'       => $decoded,
            'error'      => $error,
            'latency_ms' => $latency,
        ];
    }

    public static function get(string $url, array $options = []): array
    {
        return self::request('GET', $url, $options);
    }

    public static function post(string $url, array $options = []): array
    {
        return self::request('POST', $url, $options);
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): array
    {
        return self::request('POST', $url, ['json' => $payload, 'headers' => $headers, 'timeout' => $timeout]);
    }
}
