<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * WhatsApp Cloud API (Meta Graph) provider.
 *
 *   POST https://graph.facebook.com/{version}/{phone_number_id}/messages
 *   Authorization: Bearer {access_token}
 *
 * This sits beside the bulk.akdwk.in gateway rather than replacing it —
 * WhatsAppService picks whichever is configured as primary and can fail over
 * to the other. See docs/WHATSAPP-CLOUD-API.md.
 *
 * The one rule that matters more than any other here: Meta only accepts a
 * free-form message inside the 24-hour customer service window, i.e. within 24
 * hours of that person's last inbound message. Outside it, every free-form send
 * is rejected with error 131047 and the reminder never arrives. A reminder app
 * is proactive by nature, so most sends are outside the window — which is why
 * this class tracks the window itself and switches to an approved template
 * automatically instead of letting the message silently fail.
 */
class MetaCloudService
{
    private const GRAPH_HOST = 'https://graph.facebook.com';
    private const DEFAULT_VERSION = 'v23.0';

    /** Meta's code for "you are outside the 24-hour window". */
    public const ERROR_REENGAGEMENT = 131047;

    /* --------------------------------------------------------- Configuration */

    /**
     * @return array{ok: bool, level: string, message: string, hint: string}
     */
    public static function status(): array
    {
        $settings = App::i()->settings();

        $token = trim((string) $settings->get('wa_cloud_token', ''));
        $phoneId = trim((string) $settings->get('wa_cloud_phone_id', ''));

        if ($token === '' && $phoneId === '') {
            return [
                'ok'      => false,
                'level'   => 'info',
                'message' => 'WhatsApp Cloud API is not configured.',
                'hint'    => 'Add the permanent access token and the phone number ID from Meta → WhatsApp → API Setup.',
            ];
        }

        if ($token === '') {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'The Cloud API access token is missing.',
                'hint'    => 'Meta → Business Settings → System users → Generate new token, with whatsapp_business_messaging.',
            ];
        }

        if ($phoneId === '') {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'The Cloud API phone number ID is missing.',
                'hint'    => 'Copy "Phone number ID" (a number, not the phone number itself) from Meta → WhatsApp → API Setup.',
            ];
        }

        if (!ctype_digit($phoneId)) {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'The phone number ID looks wrong — it must be digits only.',
                'hint'    => 'You have probably pasted the phone number (+91…) instead of the numeric Phone number ID.',
            ];
        }

        return ['ok' => true, 'level' => 'success', 'message' => 'WhatsApp Cloud API is configured.', 'hint' => ''];
    }

    public static function isConfigured(): bool
    {
        return self::status()['ok'];
    }

    private static function version(): string
    {
        $version = trim((string) App::i()->settings()->get('wa_cloud_api_version', ''));

        // Never let a malformed value build a URL that quietly 404s.
        return preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : self::DEFAULT_VERSION;
    }

    private static function endpoint(string $phoneId): string
    {
        return self::GRAPH_HOST . '/' . self::version() . '/' . rawurlencode($phoneId) . '/messages';
    }

    /* -------------------------------------------------------------- Sending */

    /**
     * @return array{ok: bool, status: int, body: string, json: array|null, error: string|null, latency_ms: int, mode: string, code: int|null}
     */
    public static function send(string $number, string $message, ?string $mediaUrl = null): array
    {
        $settings = App::i()->settings();
        $status = self::status();

        if (!$status['ok']) {
            return self::failure($status['message']);
        }

        $token = (string) $settings->get('wa_cloud_token', '');
        $phoneId = trim((string) $settings->get('wa_cloud_phone_id', ''));

        // Meta wants the number in international form with no + and no spaces.
        $to = ltrim(normalize_phone($number), '+');

        if ($to === '') {
            return self::failure('Invalid destination number.');
        }

        $inWindow = self::withinServiceWindow($to);
        $payload = $inWindow
            ? self::freeFormPayload($to, $message, $mediaUrl)
            : self::templatePayload($to, $message);

        if ($payload === null) {
            return self::failure(
                'Outside the 24-hour window and no approved template is configured. '
                . 'Set the template name in Admin → WhatsApp, or the message cannot be delivered.',
                self::ERROR_REENGAGEMENT
            );
        }

        $result = HttpClient::postJson(
            self::endpoint($phoneId),
            $payload,
            ['Authorization' => 'Bearer ' . $token],
            25
        );

        $code = self::errorCode($result['json']);
        $ok = $result['ok'] && $code === null && self::hasMessageId($result['json']);

        $out = $result + [
            'mode' => $inWindow ? 'text' : 'template',
            'code' => $code,
        ];
        $out['ok'] = $ok;

        if (!$ok) {
            Logger::warn('Cloud API send failed', [
                'status' => $result['status'],
                'code'   => $code,
                'mode'   => $out['mode'],
                'error'  => self::errorMessage($result['json']) ?? mb_substr($result['body'], 0, 300),
            ], 'whatsapp');
        }

        return $out;
    }

    /** Free-form text (or image) — only valid inside the 24-hour window. */
    private static function freeFormPayload(string $to, string $message, ?string $mediaUrl): array
    {
        if ($mediaUrl !== null && $mediaUrl !== '') {
            return [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'image',
                'image'             => [
                    'link'    => $mediaUrl,
                    'caption' => mb_substr($message, 0, 1024),
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => mb_substr($message, 0, 4096),
            ],
        ];
    }

    /**
     * An approved template — the only thing Meta accepts outside the window.
     * Returns null when no template has been configured, so the caller can say
     * so plainly instead of sending something that will be rejected.
     */
    private static function templatePayload(string $to, string $message): ?array
    {
        $settings = App::i()->settings();
        $name = trim((string) $settings->get('wa_cloud_template_name', ''));

        if ($name === '') {
            return null;
        }

        $language = trim((string) $settings->get('wa_cloud_template_lang', '')) ?: 'gu';

        // Meta rejects a body parameter containing a newline, a tab, or four or
        // more consecutive spaces, so the reminder text is flattened first.
        $parameter = trim((string) preg_replace('/\s+/u', ' ', $message));

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $name,
                'language'   => ['code' => $language],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [[
                        'type' => 'text',
                        'text' => mb_substr($parameter, 0, 1024),
                    ]],
                ]],
            ],
        ];
    }

    /**
     * Has this person messaged us within the last 24 hours?
     *
     * Meta measures the window from the user's last inbound message. We hold the
     * same fact in wa_inbound_raw, so it can be answered without an API call.
     */
    public static function withinServiceWindow(string $number): bool
    {
        $number = normalize_phone($number);

        if ($number === '') {
            return false;
        }

        try {
            $row = App::i()->db()->one(
                'SELECT received_at FROM wa_inbound_raw WHERE from_number = ? ORDER BY id DESC LIMIT 1',
                [$number]
            );
        } catch (\Throwable) {
            // If we cannot tell, assume the window is closed. Sending a template
            // when a text would have done is harmless; the reverse is a lost
            // reminder.
            return false;
        }

        if ($row === null || empty($row['received_at'])) {
            return false;
        }

        $last = strtotime((string) $row['received_at'] . ' UTC');

        if ($last === false) {
            return false;
        }

        // A minute of headroom, so a send that races the window boundary does
        // not get rejected by Meta's clock.
        return $last > (time() - 86400 + 60);
    }

    /* ------------------------------------------------------------- Response */

    private static function errorCode(?array $json): ?int
    {
        if (!is_array($json) || !isset($json['error'])) {
            return null;
        }

        $error = $json['error'];

        if (!is_array($error)) {
            return 0;
        }

        return isset($error['code']) ? (int) $error['code'] : 0;
    }

    public static function errorMessage(?array $json): ?string
    {
        if (!is_array($json) || !isset($json['error']) || !is_array($json['error'])) {
            return null;
        }

        $error = $json['error'];
        $parts = array_filter([
            (string) ($error['message'] ?? ''),
            (string) ($error['error_user_title'] ?? ''),
            (string) ($error['error_user_msg'] ?? ''),
        ], static fn (string $v): bool => $v !== '');

        return $parts === [] ? 'Unknown Graph API error' : implode(' — ', array_unique($parts));
    }

    private static function hasMessageId(?array $json): bool
    {
        return is_array($json)
            && isset($json['messages'][0]['id'])
            && is_string($json['messages'][0]['id'])
            && $json['messages'][0]['id'] !== '';
    }

    /**
     * Turn a Graph failure into something a shop owner in Dwarka can act on,
     * rather than a raw OAuthException.
     */
    public static function explain(int $status, ?array $json): string
    {
        $code = self::errorCode($json);
        $message = self::errorMessage($json);

        return match (true) {
            $code === self::ERROR_REENGAGEMENT =>
                'Outside the 24-hour window. Meta only allows an approved template here — '
                . 'set the template name in Admin → WhatsApp.',
            $code === 190, $status === 401 =>
                'The access token is invalid or has expired. A test token lasts 24 hours; '
                . 'create a permanent System User token in Meta → Business Settings.',
            $code === 100 =>
                'Meta rejected the request as malformed — usually a wrong phone number ID, '
                . 'or a template name/language that does not exist. ' . (string) $message,
            $code === 131030 =>
                'This number is not on the allowed recipient list. While the app is in '
                . 'development mode, add it under Meta → WhatsApp → API Setup.',
            $code === 131026 =>
                'The destination cannot receive the message — the number may not be on '
                . 'WhatsApp, or it is an unregistered WhatsApp Business number.',
            $code === 131049, $code === 131056 =>
                'Meta throttled this send to protect the user experience. It will be retried.',
            $code === 133010 =>
                'The phone number is not registered with the Cloud API. Complete registration '
                . 'in Meta → WhatsApp → API Setup.',
            $code === 10, $code === 200 =>
                'The token lacks permission. It needs whatsapp_business_messaging.',
            $status === 0 =>
                'Could not reach graph.facebook.com — check outbound HTTPS from the server.',
            $message !== null => $message,
            default => 'HTTP ' . $status,
        };
    }

    private static function failure(string $message, ?int $code = null): array
    {
        return [
            'ok'         => false,
            'status'     => 0,
            'body'       => $message,
            'json'       => null,
            'error'      => $message,
            'latency_ms' => 0,
            'mode'       => 'none',
            'code'       => $code,
        ];
    }

    /* ------------------------------------------------------------- Webhook */

    /**
     * Meta's subscription handshake: it GETs the webhook URL once with
     * hub.mode=subscribe and expects hub.challenge echoed back verbatim.
     *
     * @return string|null the challenge to echo, or null to reject
     */
    public static function verifySubscription(array $query): ?string
    {
        $expected = trim((string) App::i()->settings()->get('wa_cloud_verify_token', ''));

        if ($expected === '') {
            return null;
        }

        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode !== 'subscribe' || $challenge === '' || !hash_equals($expected, $token)) {
            return null;
        }

        return $challenge;
    }

    /**
     * Meta signs every webhook body with the *app secret* (not the verify
     * token) as sha256 HMAC in X-Hub-Signature-256.
     */
    public static function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = trim((string) App::i()->settings()->get('wa_cloud_app_secret', ''));

        if ($secret === '' || !is_string($header) || $header === '') {
            return false;
        }

        $provided = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;
        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $provided);
    }

    /** Does this payload look like a Cloud API webhook rather than the bulk gateway's? */
    public static function isCloudPayload(array $payload): bool
    {
        return ($payload['object'] ?? null) === 'whatsapp_business_account'
            || isset($payload['entry'][0]['changes'][0]['value']['messaging_product']);
    }

    /**
     * Pull the inbound message out of Meta's nested envelope.
     *
     * entry[].changes[].value.messages[] — status callbacks (delivered/read)
     * arrive on the same URL and carry no `messages` key; they are not errors,
     * they simply are not messages, so this returns null for them.
     *
     * @return array{from: string, body: string, message_id: string|null, type: string, media_url: string|null}|null
     */
    public static function parseInbound(array $payload): ?array
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? null;

                if (!is_array($value) || empty($value['messages']) || !is_array($value['messages'])) {
                    continue;
                }

                $message = $value['messages'][0];

                if (!is_array($message)) {
                    continue;
                }

                $from = normalize_phone((string) ($message['from'] ?? ''));

                if ($from === '') {
                    continue;
                }

                $type = (string) ($message['type'] ?? 'text');

                return [
                    'from'       => $from,
                    'body'       => self::extractText($message, $type),
                    'message_id' => isset($message['id']) && is_string($message['id'])
                        ? mb_substr($message['id'], 0, 190)
                        : null,
                    'type'       => $type,
                    // Media arrives as an id that has to be exchanged for a URL,
                    // and that URL is short-lived, so the id is resolved later
                    // rather than being mistaken for a link here.
                    'media_url'  => null,
                ];
            }
        }

        return null;
    }

    /** Text lives in a different key for each message type. */
    private static function extractText(array $message, string $type): string
    {
        $text = match ($type) {
            'text'        => $message['text']['body'] ?? '',
            'button'      => $message['button']['text'] ?? '',
            'image'       => $message['image']['caption'] ?? '',
            'video'       => $message['video']['caption'] ?? '',
            'document'    => $message['document']['caption'] ?? ($message['document']['filename'] ?? ''),
            'interactive' => $message['interactive']['button_reply']['title']
                             ?? $message['interactive']['list_reply']['title']
                             ?? '',
            default       => '',
        };

        return trim(is_string($text) ? $text : '');
    }

    /**
     * Exchange a media id for a temporary download URL.
     * Returns null rather than throwing — a missing image must not lose the message.
     */
    public static function mediaUrl(string $mediaId): ?string
    {
        $token = (string) App::i()->settings()->get('wa_cloud_token', '');

        if ($token === '' || $mediaId === '') {
            return null;
        }

        $result = HttpClient::get(
            self::GRAPH_HOST . '/' . self::version() . '/' . rawurlencode($mediaId),
            ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 15]
        );

        $url = $result['json']['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /* ---------------------------------------------------------------- Test */

    /**
     * Live check used by the installer and the admin panel.
     *
     * @return array{ok: bool, message: string, hint: string, detail: string}
     */
    public static function testConnection(?string $toNumber = null): array
    {
        $status = self::status();

        if (!$status['ok']) {
            return ['ok' => false, 'message' => $status['message'], 'hint' => $status['hint'], 'detail' => ''];
        }

        $settings = App::i()->settings();
        $token = (string) $settings->get('wa_cloud_token', '');
        $phoneId = trim((string) $settings->get('wa_cloud_phone_id', ''));

        // Read the number back rather than sending a message — a connectivity
        // test should not cost a conversation or spam anyone.
        $result = HttpClient::get(
            self::GRAPH_HOST . '/' . self::version() . '/' . rawurlencode($phoneId),
            [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['fields' => 'display_phone_number,verified_name,quality_rating,platform_type'],
                'timeout' => 20,
            ]
        );

        if (!$result['ok'] || !is_array($result['json']) || isset($result['json']['error'])) {
            return [
                'ok'      => false,
                'message' => 'Meta rejected the credentials.',
                'hint'    => self::explain($result['status'], $result['json']),
                'detail'  => mb_substr($result['body'], 0, 500),
            ];
        }

        $json = $result['json'];
        $display = (string) ($json['display_phone_number'] ?? '');
        $name = (string) ($json['verified_name'] ?? '');
        $quality = (string) ($json['quality_rating'] ?? '');

        return [
            'ok'      => true,
            'message' => trim($name . ' ' . $display) . ' is connected.',
            'hint'    => $quality !== '' ? 'Quality rating: ' . $quality : '',
            'detail'  => (string) json_encode($json, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** Suggest a verify token so nobody has to invent one. */
    public static function suggestVerifyToken(): string
    {
        return 'kr_' . Crypto::randomToken(16);
    }
}
