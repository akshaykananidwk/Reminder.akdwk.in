<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * Outbound messaging: the queue, the worker, and the choice of channel.
 *
 * WhatsApp now goes through the official Meta Cloud API — MetaMessageService
 * does the sending, this class decides what to send, to whom, and when to give
 * up. The bulk.akdwk.in gateway remains only as a disabled-by-default escape
 * hatch (`meta_only_mode`), for an install whose Meta number is not registered
 * yet; nothing reaches it while Meta-only mode is on, which is the default.
 *
 * Everything the product sends goes through `wa_outbound_queue` so no request
 * path ever waits on a network call, and every attempt is retried and logged.
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
            'channel'      => 'whatsapp',
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
     * Queue a Telegram message on the same queue, so it inherits the worker,
     * the retry policy and the history rather than growing a parallel one.
     */
    public static function queueTelegram(
        int $userId,
        string $message,
        ?string $mediaUrl = null,
        int $priority = 5,
        ?string $templateKey = null,
        ?string $scheduledAt = null,
        ?int $refId = null
    ): int {
        if (trim($message) === '' || !TelegramService::isConfigured()) {
            return 0;
        }

        $chatId = App::i()->db()->value('SELECT telegram_chat_id FROM users WHERE id = ?', [$userId]);

        if (!is_string($chatId) || $chatId === '') {
            return 0;
        }

        return App::i()->db()->insert('wa_outbound_queue', [
            'user_id'      => $userId,
            // The chat id lives in to_number: it is the address for this
            // channel, exactly as the phone number is for WhatsApp.
            'to_number'    => mb_substr($chatId, 0, 20),
            'channel'      => 'telegram',
            // Lets the worker attach Done / Snooze buttons to this message.
            'ref_id'       => $refId,
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
        ?string $number = null,
        ?int $occurrenceId = null
    ): int {
        $lang = (string) ($user['language'] ?? 'en');
        $body = TemplateService::render($templateKey, $lang, $vars);

        if ($body === '') {
            Logger::warn('Template missing', ['key' => $templateKey, 'lang' => $lang], 'whatsapp');

            return 0;
        }

        $userId = isset($user['id']) ? (int) $user['id'] : null;

        $queued = self::queue(
            $number ?: (string) ($user['phone'] ?? ''),
            $body,
            $userId,
            null,
            $priority,
            $templateKey
        );

        // Mirror to Telegram when the user has linked it. Deliberately a copy
        // rather than a replacement: the point of a reminder is that it
        // arrives, and two cheap channels beat one that may be down.
        if ($userId !== null && App::i()->settings()->bool('tg_send_reminders', true)) {
            self::queueTelegram($userId, $body, null, $priority, $templateKey, null, $occurrenceId);
        }

        return $queued;
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
                ? self::sendViaMeta($number, $message, $mediaUrl, $userId, $queueId)
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
     * Which providers to try, in order.
     *
     * `meta_only_mode` is the migration switch and defaults to on: the official
     * Cloud API is the only path, and the bulk.akdwk.in gateway is not tried
     * even if its credentials are still lying around in settings. Turning it off
     * restores the old two-provider behaviour, which exists solely so an install
     * mid-migration is not stranded if its Meta number is not registered yet.
     *
     * @return array<int, string>
     */
    public static function providerOrder(): array
    {
        $settings = App::i()->settings();

        if ($settings->bool('meta_only_mode', true)) {
            return self::providerConfigured('cloud') ? ['cloud'] : [];
        }

        $primary = strtolower(trim((string) $settings->get('wa_provider', 'cloud')));
        $primary = in_array($primary, ['bulk', 'cloud'], true) ? $primary : 'cloud';
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
            // A connected account is the real answer; the legacy settings check
            // keeps an install working in the window between deploying this code
            // and running the migration that creates waba_accounts.
            return WabaAccountService::isUsable(WabaAccountService::platform())
                || MetaCloudService::isConfigured();
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

    /**
     * Deliver one queued Telegram message. Same return shape as sendNow(), so
     * the worker's success, retry and backoff handling needs no special case.
     */
    public static function sendTelegramNow(string $chatId, string $message, ?string $mediaUrl = null, ?int $userId = null, ?int $queueId = null, ?int $refId = null): array
    {
        if (!TelegramService::isConfigured()) {
            return ['ok' => false, 'status' => 0, 'response' => 'Telegram is not configured', 'latency_ms' => 0, 'provider' => 'telegram', 'fatal' => true];
        }

        $requestId = Crypto::randomToken(8);

        // A message about a specific occurrence gets Done / Snooze / Cancel
        // buttons, in the user's own language.
        $keyboard = null;

        if ($refId !== null && $refId > 0) {
            $lang = 'en';

            if ($userId !== null) {
                $lang = (string) (App::i()->db()->value('SELECT language FROM users WHERE id = ?', [$userId]) ?: 'en');
            }

            $keyboard = TelegramService::reminderKeyboard($refId, $lang);
        }

        $result = TelegramService::send($chatId, $message, $mediaUrl, $keyboard);

        self::log(
            $queueId,
            $userId,
            $chatId,
            $message,
            $requestId,
            ['status' => $result['status'], 'body' => $result['response'], 'error' => null, 'latency_ms' => $result['latency_ms']],
            $result['ok'],
            'telegram',
            'telegram'
        );

        return [
            'ok'         => $result['ok'],
            'status'     => $result['status'],
            'response'   => $result['response'],
            'latency_ms' => $result['latency_ms'],
            'provider'   => 'telegram',
        ];
    }

    /**
     * The official Meta Cloud API.
     *
     * The 24-hour rule governs everything here. Meta accepts free-form text
     * only within 24 hours of that person's last inbound message; outside it,
     * only an approved template is delivered and anything else answers 131047.
     * A reminder app is proactive by nature, so most sends are outside the
     * window — which is why the window is checked here and the message is
     * carried by a template automatically rather than failing silently.
     */
    private static function sendViaMeta(string $number, string $message, ?string $mediaUrl, ?int $userId, ?int $queueId): array
    {
        $started = microtime(true);
        $requestId = Crypto::randomToken(8);
        $account = WabaAccountService::forUser($userId);

        if ($account === null || !WabaAccountService::isUsable($account)) {
            $detail = $account === null
                ? 'No WhatsApp Business account is connected. Connect one in Admin → WhatsApp.'
                : 'The connected WhatsApp account has no usable token or registered number.';

            return self::metaFailure($queueId, $userId, $number, $message, $requestId, $detail, $started, true);
        }

        $inWindow = MetaMessageService::withinServiceWindow($account, $number);
        $mode = 'text';

        if ($inWindow) {
            $result = ($mediaUrl !== null && $mediaUrl !== '')
                ? MetaMessageService::sendMedia($account, $number, 'image', $mediaUrl, $message, null, $userId)
                : MetaMessageService::sendText($account, $number, $message, false, null, $userId);

            $mode = ($mediaUrl !== null && $mediaUrl !== '') ? 'image' : 'text';
        } else {
            $template = self::outboundTemplate($account, $userId);

            if ($template === null) {
                return self::metaFailure(
                    $queueId,
                    $userId,
                    $number,
                    $message,
                    $requestId,
                    'Outside the 24-hour window and no approved template is available. '
                    . 'Create and get one approved in Admin → WhatsApp → Templates.',
                    $started,
                    true
                );
            }

            $mode = 'template:' . $template['name'];

            $result = MetaMessageService::sendTemplate(
                $account,
                $number,
                (string) $template['name'],
                (string) $template['language'],
                WaTemplateService::parameters([$message]),
                $userId
            );
        }

        $detail = $result['ok'] ? ('sent ' . (string) $result['wamid']) : (string) $result['error'];

        self::log(
            $queueId,
            $userId,
            $number,
            $message,
            $requestId,
            [
                'status'     => $result['status'],
                'body'       => $detail,
                'error'      => $result['error'],
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            $result['ok'],
            'cloud:' . $mode
        );

        return [
            'ok'         => $result['ok'],
            'status'     => $result['status'],
            'response'   => mb_substr($detail, 0, 2000),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'provider'   => 'cloud',
            // A rejection on the message's own merits will be rejected again on
            // the next attempt too, so the queue should stop rather than burn
            // three retries and an hour arriving at the same answer.
            'fatal'      => in_array($result['code'], [131047, 131026, 131030, 132001, 132000], true),
        ];
    }

    /**
     * The approved template that carries a reminder outside the 24-hour window.
     *
     * The configured name wins if it is genuinely approved; otherwise any
     * approved utility template with a single variable will do, because a
     * reminder that arrives in an unexpected wrapper still beats one that never
     * arrives at all.
     */
    public static function outboundTemplate(array $account, ?int $userId = null): ?array
    {
        $settings = App::i()->settings();
        $accountId = (int) $account['id'];

        $language = trim((string) $settings->get('wa_cloud_template_lang', '')) ?: 'en';

        if ($userId !== null) {
            $userLang = App::i()->db()->value('SELECT language FROM users WHERE id = ?', [$userId]);

            if (is_string($userLang) && $userLang !== '') {
                $language = $userLang;
            }
        }

        $name = trim((string) $settings->get('wa_cloud_template_name', ''));

        if ($name !== '') {
            // Prefer the user's language, then any approved language of it.
            $template = WaTemplateService::approved($accountId, $name, $language)
                ?? WaTemplateService::approved($accountId, $name);

            if ($template !== null) {
                return $template;
            }
        }

        try {
            return App::i()->db()->one(
                "SELECT * FROM wa_templates
                  WHERE waba_account_id = ? AND status = 'APPROVED' AND deleted_at IS NULL
                    AND category = 'UTILITY' AND variable_count = 1
                  ORDER BY (language = ?) DESC, id
                  LIMIT 1",
                [$accountId, $language]
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private static function metaFailure(
        ?int $queueId,
        ?int $userId,
        string $number,
        string $message,
        string $requestId,
        string $detail,
        float $started,
        bool $fatal
    ): array {
        $latency = (int) round((microtime(true) - $started) * 1000);

        self::log(
            $queueId,
            $userId,
            $number,
            $message,
            $requestId,
            ['status' => 0, 'body' => $detail, 'error' => $detail, 'latency_ms' => $latency],
            false,
            'cloud'
        );

        return [
            'ok'         => false,
            'status'     => 0,
            'response'   => $detail,
            'latency_ms' => $latency,
            'provider'   => 'cloud',
            'fatal'      => $fatal,
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

            $result = ((string) ($row['channel'] ?? 'whatsapp')) === 'telegram'
                ? self::sendTelegramNow(
                    (string) $row['to_number'],
                    (string) $row['message'],
                    $row['media_url'] ?: null,
                    $row['user_id'] !== null ? (int) $row['user_id'] : null,
                    $id,
                    isset($row['ref_id']) && $row['ref_id'] !== null ? (int) $row['ref_id'] : null
                )
                : self::sendNow(
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

        $lang = detect_language($body, (string) $settings->get('default_language', 'en'));
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

    private static function log(?int $queueId, ?int $userId, string $number, string $message, string $requestId, array $result, bool $ok, string $provider = 'bulk', string $channel = 'whatsapp'): void
    {
        try {
            App::i()->db()->insert('wa_outbound_log', [
                'queue_id'   => $queueId,
                'user_id'    => $userId,
                'to_number'  => $number,
                'channel'    => $channel,
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
