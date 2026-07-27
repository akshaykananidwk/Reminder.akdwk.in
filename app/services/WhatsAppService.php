<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * WhatsApp gateway client for bulk.akdwk.in.
 *
 * Contract (Section 6 of the specification):
 *   POST {endpoint}/api.php  {api_key, session_id, number, message[, media_url]}
 *
 * Everything the product sends goes through the queue so the gateway is never
 * hammered and every message is retried and logged.
 */
class WhatsAppService
{
    /* ------------------------------------------------------------- Queueing */

    /**
     * Queue a raw message. Returns the queue row id.
     */
    public static function queue(
        string $number,
        string $message,
        ?int $userId = null,
        ?string $mediaUrl = null,
        int $priority = 5,
        ?string $templateKey = null,
        ?string $scheduledAt = null
    ): int {
        $number = normalize_phone($number);

        if ($number === '' || trim($message) === '') {
            return 0;
        }

        if (self::isBlocked($number)) {
            Logger::info('Outbound skipped, number blocked', ['number' => $number], 'whatsapp');

            return 0;
        }

        return App::i()->db()->insert('wa_outbound_queue', [
            'user_id'      => $userId,
            'to_number'    => $number,
            'message'      => $message,
            'media_url'    => $mediaUrl,
            'template_key' => $templateKey,
            'priority'     => $priority,
            'status'       => 'queued',
            'scheduled_at' => $scheduledAt,
            'created_at'   => now_utc(),
        ]);
    }

    /**
     * Queue an admin-editable template rendered in the user's language.
     */
    public static function queueTemplate(
        string $templateKey,
        array $user,
        array $vars = [],
        int $priority = 5,
        ?string $number = null
    ): int {
        $lang = (string) ($user['language'] ?? 'gu');
        $body = TemplateService::render($templateKey, $lang, $vars);

        if ($body === '') {
            Logger::warn('Template missing', ['key' => $templateKey, 'lang' => $lang], 'whatsapp');

            return 0;
        }

        return self::queue(
            $number ?: (string) ($user['phone'] ?? ''),
            $body,
            isset($user['id']) ? (int) $user['id'] : null,
            null,
            $priority,
            $templateKey
        );
    }

    /* ------------------------------------------------------------- Sending */

    /**
     * Send immediately (used by the queue worker, the installer test and the
     * admin test tool). Never call this in a request path — queue instead.
     *
     * @return array{ok: bool, status: int, response: string, latency_ms: int}
     */
    public static function sendNow(string $number, string $message, ?string $mediaUrl = null, ?int $userId = null, ?int $queueId = null): array
    {
        $settings = App::i()->settings();
        $number = normalize_phone($number);

        if (!$settings->bool('wa_enabled', true)) {
            return ['ok' => false, 'status' => 0, 'response' => 'WhatsApp sending is disabled in admin settings', 'latency_ms' => 0, 'provider' => 'none'];
        }

        $order = self::providerOrder();

        if ($order === []) {
            return ['ok' => false, 'status' => 0, 'response' => 'No WhatsApp provider is configured', 'latency_ms' => 0, 'provider' => 'none'];
        }

        $last = null;

        foreach ($order as $index => $provider) {
            $result = $provider === 'cloud'
                ? self::sendViaCloud($number, $message, $mediaUrl, $userId, $queueId)
                : self::sendViaBulk($number, $message, $mediaUrl, $userId, $queueId);

            if ($result['ok']) {
                if ($index > 0) {
                    Logger::info('Sent via the fallback provider', [
                        'provider' => $provider,
                        'number'   => $number,
                    ], 'whatsapp');
                }

                return $result;
            }

            $last = $result;

            // A message rejected on its merits (unknown number, no template,
            // blocked recipient) will be rejected by the other provider too —
            // retrying it just burns quota and delays the failure.
            if (!empty($result['fatal'])) {
                break;
            }
        }

        return $last ?? ['ok' => false, 'status' => 0, 'response' => 'No provider attempted', 'latency_ms' => 0, 'provider' => 'none'];
    }

    /**
     * Which providers to try, in order. The primary comes from `wa_provider`;
     * the other is appended only when failover is on and it is actually
     * configured, so a half-set-up second provider can never swallow a send.
     *
     * @return array<int, string>
     */
    public static function providerOrder(): array
    {
        $settings = App::i()->settings();

        $primary = strtolower(trim((string) $settings->get('wa_provider', 'bulk')));
        $primary = in_array($primary, ['bulk', 'cloud'], true) ? $primary : 'bulk';
        $secondary = $primary === 'bulk' ? 'cloud' : 'bulk';

        $order = [];

        if (self::providerConfigured($primary)) {
            $order[] = $primary;
        }

        if ($settings->bool('wa_failover', true) && self::providerConfigured($secondary)) {
            $order[] = $secondary;
        }

        // If the chosen primary is not set up but the other one is, use it
        // rather than failing — that is what the operator plainly wants.
        if ($order === [] && self::providerConfigured($secondary)) {
            $order[] = $secondary;
        }

        return $order;
    }

    public static function providerConfigured(string $provider): bool
    {
        if ($provider === 'cloud') {
            return MetaCloudService::isConfigured();
        }

        $settings = App::i()->settings();

        return rtrim((string) $settings->get('wa_endpoint', ''), '/') !== ''
            && (string) $settings->get('wa_api_key', '') !== ''
            && (string) $settings->get('wa_session_id', '') !== '';
    }

    /** The original bulk.akdwk.in gateway. */
    private static function sendViaBulk(string $number, string $message, ?string $mediaUrl, ?int $userId, ?int $queueId): array
    {
        $settings = App::i()->settings();

        $endpoint = rtrim((string) $settings->get('wa_endpoint', ''), '/');
        $apiKey = (string) $settings->get('wa_api_key', '');
        $sessionId = (string) $settings->get('wa_session_id', '');

        if ($endpoint === '' || $apiKey === '' || $sessionId === '') {
            return ['ok' => false, 'status' => 0, 'response' => 'WhatsApp gateway is not configured', 'latency_ms' => 0, 'provider' => 'bulk'];
        }

        $payload = [
            'api_key'    => $apiKey,
            'session_id' => $sessionId,
            'number'     => $number,
            'message'    => $message,
        ];

        if ($mediaUrl !== null && $mediaUrl !== '') {
            $payload['media_url'] = $mediaUrl;
        }

        $requestId = Crypto::randomToken(8);

        // POST + JSON only — the key must never appear in a URL or access log.
        $result = HttpClient::postJson($endpoint . '/api.php', $payload, ['X-Request-Id' => $requestId], 25);

        $ok = $result['ok'] && self::responseIndicatesSuccess($result);

        self::log($queueId, $userId, $number, $message, $requestId, $result, $ok, 'bulk');

        return [
            'ok'         => $ok,
            'status'     => $result['status'],
            'response'   => mb_substr($result['body'] !== '' ? $result['body'] : (string) $result['error'], 0, 2000),
            'latency_ms' => $result['latency_ms'],
            'provider'   => 'bulk',
        ];
    }

    /** Meta WhatsApp Cloud API. */
    private static function sendViaCloud(string $number, string $message, ?string $mediaUrl, ?int $userId, ?int $queueId): array
    {
        $requestId = Crypto::randomToken(8);
        $result = MetaCloudService::send($number, $message, $mediaUrl);

        $ok = $result['ok'];
        $detail = $ok
            ? mb_substr($result['body'], 0, 2000)
            : MetaCloudService::explain($result['status'], $result['json']);

        self::log(
            $queueId,
            $userId,
            $number,
            $message,
            $requestId,
            ['status' => $result['status'], 'body' => $detail, 'error' => $result['error'], 'latency_ms' => $result['latency_ms']],
            $ok,
            'cloud:' . $result['mode']
        );

        return [
            'ok'         => $ok,
            'status'     => $result['status'],
            'response'   => $detail,
            'latency_ms' => $result['latency_ms'],
            'provider'   => 'cloud',
            // These will fail identically on the other gateway, so do not retry.
            'fatal'      => in_array($result['code'], [MetaCloudService::ERROR_REENGAGEMENT, 131026, 131030], true),
        ];
    }

    /**
     * Gateways differ: some return {"status":"success"}, some {"success":true},
     * some plain text. Treat an explicit failure marker as failure, else trust
     * the HTTP status.
     */
    private static function responseIndicatesSuccess(array $result): bool
    {
        $json = $result['json'];

        if (is_array($json)) {
            foreach (['success', 'status', 'result'] as $key) {
                if (!array_key_exists($key, $json)) {
                    continue;
                }

                $value = $json[$key];

                if (is_bool($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $normalised = strtolower($value);

                    if (in_array($normalised, ['error', 'failed', 'failure', 'false', '0'], true)) {
                        return false;
                    }

                    if (in_array($normalised, ['success', 'sent', 'ok', 'true', '1', 'queued'], true)) {
                        return true;
                    }
                }
            }
        }

        $body = strtolower($result['body']);

        if ($body !== '' && (str_contains($body, '"error"') || str_contains($body, 'invalid api'))) {
            return false;
        }

        return true;
    }

    /**
     * Drain the outbound queue, respecting the configured per-minute rate.
     *
     * @return array{sent: int, failed: int}
     */
    public static function processQueue(int $maxMessages = 50): array
    {
        $db = App::i()->db();
        $settings = App::i()->settings();

        $ratePerMinute = max(1, $settings->int('wa_rate_per_minute', 30));
        $delayMicroseconds = (int) round(60_000_000 / $ratePerMinute);
        $maxRetries = max(1, $settings->int('wa_max_retries', 3));

        $rows = $db->all(
            'SELECT * FROM wa_outbound_queue
              WHERE status = "queued"
                AND (scheduled_at IS NULL OR scheduled_at <= ?)
                AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
              ORDER BY priority ASC, id ASC
              LIMIT ' . (int) $maxMessages,
            [now_utc(), now_utc()]
        );

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            // Claim the row so a parallel run cannot double-send.
            $claimed = $db->query(
                'UPDATE wa_outbound_queue SET status = "sending", attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = "queued"',
                [now_utc(), $id]
            )->rowCount();

            if ($claimed === 0) {
                continue;
            }

            $result = self::sendNow(
                (string) $row['to_number'],
                (string) $row['message'],
                $row['media_url'] ?: null,
                $row['user_id'] !== null ? (int) $row['user_id'] : null,
                $id
            );

            if ($result['ok']) {
                $db->update('wa_outbound_queue', [
                    'status'  => 'sent',
                    'sent_at' => now_utc(),
                ], 'id = :id', ['id' => $id]);
                $sent++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $isFinal = $attempts >= $maxRetries;

                $db->update('wa_outbound_queue', [
                    'status'          => $isFinal ? 'failed' : 'queued',
                    'last_error'      => mb_substr($result['response'], 0, 500),
                    // Exponential backoff: 1, 4, 9 minutes.
                    'next_attempt_at' => $isFinal ? null : date('Y-m-d H:i:s', time() + (60 * $attempts * $attempts)),
                ], 'id = :id', ['id' => $id]);

                $failed++;
            }

            usleep($delayMicroseconds);
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /* ------------------------------------------------------------- Inbound */

    /**
     * Extract a normalised inbound message from whatever shape the gateway posts.
     *
     * @return array{from: string, body: string, message_id: string|null, type: string, media_url: string|null}|null
     */
    public static function parseInbound(array $payload): ?array
    {
        $from = self::pick($payload, ['from', 'sender', 'number', 'phone', 'from_number', 'wa_id', 'author', 'chatId', 'remoteJid']);
        $body = self::pick($payload, ['message', 'body', 'text', 'content', 'msg', 'caption']);
        $messageId = self::pick($payload, ['id', 'message_id', 'msg_id', 'messageId', 'key_id']);
        $type = self::pick($payload, ['type', 'message_type', 'messageType']) ?: 'text';
        $media = self::pick($payload, ['media_url', 'mediaUrl', 'file_url', 'attachment', 'url']);

        // Some gateways nest everything one level deep.
        if ($from === null || $body === null) {
            foreach (['data', 'message', 'payload', 'entry', 'result'] as $wrapper) {
                if (isset($payload[$wrapper]) && is_array($payload[$wrapper])) {
                    $inner = self::parseInbound($payload[$wrapper]);

                    if ($inner !== null) {
                        return $inner;
                    }
                }
            }
        }

        $from = normalize_phone(is_string($from) ? $from : '');

        if ($from === '') {
            return null;
        }

        return [
            'from'       => $from,
            'body'       => is_string($body) ? trim($body) : '',
            'message_id' => is_string($messageId) && $messageId !== '' ? mb_substr($messageId, 0, 190) : null,
            'type'       => is_string($type) ? strtolower($type) : 'text',
            'media_url'  => is_string($media) && $media !== '' ? $media : null,
        ];
    }

    private static function pick(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && (is_string($data[$key]) || is_numeric($data[$key]))) {
                $value = trim((string) $data[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Whitelist check: only verified, active numbers of active accounts pass.
     *
     * @return array{user: array, number: array}|null
     */
    public static function resolveSender(string $number): ?array
    {
        $number = normalize_phone($number);

        if ($number === '') {
            return null;
        }

        $row = App::i()->db()->one(
            'SELECT w.*, u.id AS u_id
               FROM whatsapp_numbers w
               JOIN users u ON u.id = w.user_id
              WHERE w.number = ?
                AND w.is_verified = 1
                AND w.is_active = 1
                AND u.is_active = 1
                AND u.deleted_at IS NULL
              LIMIT 1',
            [$number]
        );

        if ($row === null) {
            return null;
        }

        $user = App::i()->db()->one('SELECT * FROM users WHERE id = ?', [(int) $row['u_id']]);

        if ($user === null) {
            return null;
        }

        return ['user' => $user, 'number' => $row];
    }

    /**
     * Record an unknown sender and send at most one invite per cooldown window.
     */
    public static function handleUnknown(string $number, string $body): void
    {
        $db = App::i()->db();
        $settings = App::i()->settings();
        $number = normalize_phone($number);

        if ($number === '') {
            return;
        }

        $existing = $db->one('SELECT * FROM unknown_inbound WHERE from_number = ?', [$number]);

        if ($existing === null) {
            $db->insert('unknown_inbound', [
                'from_number'   => $number,
                'body'          => mb_substr($body, 0, 2000),
                'hits'          => 1,
                'first_seen_at' => now_utc(),
                'last_seen_at'  => now_utc(),
            ]);
            $existing = $db->one('SELECT * FROM unknown_inbound WHERE from_number = ?', [$number]);
        } else {
            $db->query(
                'UPDATE unknown_inbound SET hits = hits + 1, body = ?, last_seen_at = ? WHERE id = ?',
                [mb_substr($body, 0, 2000), now_utc(), (int) $existing['id']]
            );
        }

        if (!$settings->bool('wa_invite_unknown', true) || $existing === null) {
            return;
        }

        $cooldownDays = max(1, $settings->int('wa_invite_cooldown_days', 7));
        $lastInvite = $existing['invite_sent_at'];

        if ($lastInvite !== null && strtotime((string) $lastInvite) > time() - ($cooldownDays * 86400)) {
            return;
        }

        $lang = detect_language($body, (string) $settings->get('default_language', 'gu'));
        $message = TemplateService::render('unknown_number_invite', $lang, ['url' => App::i()->url('/register')]);

        if ($message !== '') {
            self::queue($number, $message, null, null, 8, 'unknown_number_invite');
            $db->update('unknown_inbound', ['invite_sent_at' => now_utc()], 'id = :id', ['id' => (int) $existing['id']]);
        }
    }

    /* ------------------------------------------------------------- Helpers */

    public static function isBlocked(string $number): bool
    {
        try {
            $row = App::i()->db()->one(
                'SELECT id FROM blocklist WHERE kind = "number" AND value = ? AND (expires_at IS NULL OR expires_at > ?)',
                [normalize_phone($number), now_utc()]
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function log(?int $queueId, ?int $userId, string $number, string $message, string $requestId, array $result, bool $ok, string $provider = 'bulk'): void
    {
        try {
            App::i()->db()->insert('wa_outbound_log', [
                'queue_id'   => $queueId,
                'user_id'    => $userId,
                'to_number'  => $number,
                'message'    => mb_substr($message, 0, 4000),
                'provider'   => $provider,
                'request_id' => $requestId,
                'http_code'  => $result['status'],
                'response'   => mb_substr($result['body'] !== '' ? $result['body'] : (string) $result['error'], 0, 2000),
                'success'    => $ok ? 1 : 0,
                'latency_ms' => $result['latency_ms'],
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Failed to write WhatsApp log', ['error' => $e->getMessage()], 'whatsapp');
        }
    }

    /** Health probe used by the admin dashboard and the health endpoint. */
    public static function gatewayHealthy(): bool
    {
        try {
            $row = App::i()->db()->one(
                'SELECT success FROM wa_outbound_log ORDER BY id DESC LIMIT 1'
            );

            return $row === null || (int) $row['success'] === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
