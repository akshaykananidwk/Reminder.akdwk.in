<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Firebase Cloud Messaging sender.
 *
 * Prefers the modern HTTP v1 API (service-account JSON + RS256 JWT, signed with
 * openssl — no composer packages needed) and falls back to the legacy server
 * key when only that is configured.
 *
 * Reminder pushes are always `data` messages with high priority so the Android
 * app can start its foreground service and show the full-screen call UI even
 * when the device is dozing.
 */
class FcmService
{
    private const V1_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const LEGACY_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * Push a payload to every active device of a user.
     *
     * @return array{sent: int, failed: int, tokens: int}
     */
    public static function sendToUser(int $userId, array $data, array $options = []): array
    {
        $devices = App::i()->db()->all(
            'SELECT * FROM devices WHERE user_id = ? AND is_active = 1 AND fcm_token IS NOT NULL AND fcm_token <> ""',
            [$userId]
        );

        $sent = 0;
        $failed = 0;

        foreach ($devices as $device) {
            $result = self::sendToToken((string) $device['fcm_token'], $data, $options);

            if ($result['ok']) {
                $sent++;
                App::i()->db()->update('devices', ['push_ok' => 1], 'id = :id', ['id' => (int) $device['id']]);
            } else {
                $failed++;
                self::handleFailure((int) $device['id'], $result);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'tokens' => count($devices)];
    }

    /**
     * @return array{ok: bool, status: int, response: string}
     */
    public static function sendToToken(string $token, array $data, array $options = []): array
    {
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'response' => 'Empty token'];
        }

        $settings = App::i()->settings();
        $serviceAccount = trim((string) $settings->get('fcm_service_account', ''));

        if ($serviceAccount !== '') {
            return self::sendV1($token, $data, $options, $serviceAccount);
        }

        $legacyKey = trim((string) $settings->get('fcm_server_key', ''));

        if ($legacyKey !== '') {
            return self::sendLegacy($token, $data, $options, $legacyKey);
        }

        return ['ok' => false, 'status' => 0, 'response' => 'FCM is not configured'];
    }

    /**
     * Build the CALL payload the Android app expects at trigger time.
     */
    public static function callPayload(array $occurrence, array $reminder, array $user, int $attemptNo = 1): array
    {
        $settings = ReminderService::userSettings((int) $user['id']);

        return [
            'type'            => 'call',
            'occurrence_id'   => (string) $occurrence['id'],
            'reminder_id'     => (string) $reminder['id'],
            'short_code'      => (string) $reminder['short_code'],
            'title'           => (string) $reminder['title'],
            'description'     => (string) ($reminder['description'] ?? ''),
            'reminder_type'   => (string) $reminder['type'],
            'priority'        => (string) $reminder['priority'],
            'due_at'          => (string) $occurrence['due_at'],
            'amount'          => $reminder['amount'] === null ? '' : (string) $reminder['amount'],
            'currency'        => (string) $reminder['currency'],
            'person'          => (string) ($reminder['person_name'] ?? ''),
            'language'        => (string) $user['language'],
            'speech'          => TtsService::speech($reminder, $user, $occurrence),
            'ringtone'        => (string) ($settings['ringtone'] ?? 'flute'),
            'ring_seconds'    => (string) ($settings['ring_seconds'] ?? 45),
            'snooze_minutes'  => (string) ($reminder['snooze_default_min'] ?? 5),
            'tts_enabled'     => (string) ((int) ($settings['tts_enabled'] ?? 1)),
            'tts_speed'       => (string) ($settings['tts_speed'] ?? 1.0),
            'attempt'         => (string) $attemptNo,
            'ignore_dnd'      => (string) ((int) ($reminder['priority'] === 'urgent')),
            'sent_at'         => now_utc(),
        ];
    }

    /* --------------------------------------------------------------- HTTP v1 */

    private static function sendV1(string $token, array $data, array $options, string $serviceAccountJson): array
    {
        $account = json_decode($serviceAccountJson, true);

        if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            return ['ok' => false, 'status' => 0, 'response' => 'Invalid FCM service account JSON'];
        }

        $accessToken = self::accessToken($account);

        if ($accessToken === null) {
            return ['ok' => false, 'status' => 0, 'response' => 'Could not obtain a Google access token'];
        }

        $projectId = (string) ($account['project_id'] ?? App::i()->settings()->get('fcm_project_id', ''));

        if ($projectId === '') {
            return ['ok' => false, 'status' => 0, 'response' => 'FCM project id missing'];
        }

        $message = [
            'message' => [
                'token' => $token,
                // Data-only so the app always gets control, even in the background.
                'data'  => array_map(static fn ($v) => (string) $v, $data),
                'android' => [
                    'priority' => 'high',
                    'ttl'      => ($options['ttl'] ?? 300) . 's',
                ],
            ],
        ];

        if (!empty($options['notification'])) {
            $message['message']['notification'] = $options['notification'];
        }

        $response = HttpClient::postJson(
            sprintf(self::V1_ENDPOINT, rawurlencode($projectId)),
            $message,
            ['Authorization' => 'Bearer ' . $accessToken],
            15
        );

        return [
            'ok'       => $response['ok'],
            'status'   => $response['status'],
            'response' => mb_substr($response['body'] ?: (string) $response['error'], 0, 1000),
        ];
    }

    /**
     * Build the signed RS256 JWT assertion Google exchanges for an access token.
     *
     * Kept public and side-effect free so it can be verified without touching
     * the network — see tests/verify_fcm.php.
     */
    public static function buildAssertion(array $account, ?int $now = null): ?string
    {
        if (empty($account['client_email']) || empty($account['private_key'])) {
            return null;
        }

        $now ??= time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = self::base64Url((string) json_encode($header))
            . '.' . self::base64Url((string) json_encode($claims));

        $signature = '';

        // RS256 with the service-account private key — no JWT library needed.
        if (!openssl_sign($signingInput, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256)) {
            Logger::error('Failed to sign FCM JWT', ['openssl' => openssl_error_string()], 'push');

            return null;
        }

        return $signingInput . '.' . self::base64Url($signature);
    }

    /**
     * Service-account JWT -> OAuth access token, cached for its lifetime.
     */
    private static function accessToken(array $account): ?string
    {
        $cacheKey = 'fcm_access_token_' . md5((string) $account['client_email']);
        $cached = CacheService::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $jwt = self::buildAssertion($account);

        if ($jwt === null) {
            return null;
        }

        $response = HttpClient::request('POST', self::TOKEN_ENDPOINT, [
            'form' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 15,
        ]);

        $token = $response['json']['access_token'] ?? null;

        if (!is_string($token)) {
            Logger::error('FCM token exchange failed', ['body' => mb_substr($response['body'], 0, 300)], 'push');

            return null;
        }

        CacheService::put($cacheKey, $token, (int) ($response['json']['expires_in'] ?? 3600) - 60);

        return $token;
    }

    /* ---------------------------------------------------------------- Legacy */

    /**
     * DECOMMISSIONED by Google in June 2024.
     *
     * The endpoint now answers 404 for every request, so this exists only to
     * produce an unmistakable error instead of a silent no-op. `isConfigured()`
     * deliberately does not count a legacy key as a working configuration.
     */
    private static function sendLegacy(string $token, array $data, array $options, string $serverKey): array
    {
        $payload = [
            'to'           => $token,
            'priority'     => 'high',
            'data'         => $data,
            'time_to_live' => (int) ($options['ttl'] ?? 300),
        ];

        $response = HttpClient::postJson(self::LEGACY_ENDPOINT, $payload, [
            'Authorization' => 'key=' . $serverKey,
        ], 15);

        $ok = $response['ok'] && (int) ($response['json']['success'] ?? 0) >= 1;

        return [
            'ok'       => $ok,
            'status'   => $response['status'],
            'response' => mb_substr($response['body'] ?: (string) $response['error'], 0, 1000),
        ];
    }

    /* --------------------------------------------------------------- Helpers */

    private static function handleFailure(int $deviceId, array $result): void
    {
        $body = strtolower($result['response']);

        $tokenDead = str_contains($body, 'unregistered')
            || str_contains($body, 'invalid_argument')
            || str_contains($body, 'notregistered')
            || str_contains($body, 'invalidregistration');

        if ($tokenDead) {
            App::i()->db()->update('devices', ['fcm_token' => null, 'push_ok' => 0], 'id = :id', ['id' => $deviceId]);
            Logger::info('Cleared dead FCM token', ['device_id' => $deviceId], 'push');

            return;
        }

        App::i()->db()->update('devices', ['push_ok' => 0], 'id = :id', ['id' => $deviceId]);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * True only when push can actually be delivered.
     *
     * A legacy server key does NOT count: Google decommissioned that API in
     * June 2024, so treating it as configured would mean the phone silently
     * never rings — the exact failure this product cannot afford.
     */
    public static function isConfigured(): bool
    {
        return self::status()['ok'];
    }

    /**
     * Human-readable push configuration state, used by the admin panel, the
     * health endpoint and the installer.
     *
     * @return array{ok: bool, level: string, message: string, hint: string}
     */
    public static function status(): array
    {
        $settings = App::i()->settings();
        $raw = trim((string) $settings->get('fcm_service_account', ''));
        $legacy = trim((string) $settings->get('fcm_server_key', ''));

        if ($raw === '') {
            return [
                'ok'      => false,
                'level'   => $legacy === '' ? 'error' : 'error',
                'message' => $legacy === ''
                    ? 'Push is not configured — phones will not ring.'
                    : 'Only a legacy FCM server key is set, and Google shut that API down in June 2024.',
                'hint'    => 'Paste the Firebase service-account JSON under Admin → Settings → Push. '
                    . 'Google Cloud → IAM → Service Accounts → Keys → Add key (JSON).',
            ];
        }

        $account = json_decode($raw, true);

        if (!is_array($account)) {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'The FCM service-account value is not valid JSON.',
                'hint'    => 'Paste the whole downloaded file, including the outer { } braces.',
            ];
        }

        foreach (['client_email', 'private_key', 'project_id'] as $field) {
            if (empty($account[$field])) {
                return [
                    'ok'      => false,
                    'level'   => 'error',
                    'message' => 'The service-account JSON is missing "' . $field . '".',
                    'hint'    => 'Download a fresh JSON key for the service account and paste it again.',
                ];
            }
        }

        // Prove the key actually signs before claiming push works.
        if (self::buildAssertion($account) === null) {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'The private key in the service-account JSON could not sign a token.',
                'hint'    => 'The key is probably truncated — make sure the \\n escapes survived the copy/paste.',
            ];
        }

        return [
            'ok'      => true,
            'level'   => $legacy === '' ? 'ok' : 'warning',
            'message' => 'FCM HTTP v1 is configured for project ' . (string) $account['project_id'] . '.',
            'hint'    => $legacy === ''
                ? ''
                : 'A legacy server key is also stored but is never used; you can clear it.',
        ];
    }
}
