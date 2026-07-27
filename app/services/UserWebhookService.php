<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;

/**
 * Fires the user's own webhooks (Section 13.14) with an HMAC signature so the
 * receiving app can verify authenticity.
 */
class UserWebhookService
{
    public static function dispatch(int $userId, string $event, array $payload): void
    {
        if (!PlanService::can($userId, 'api_access')) {
            return;
        }

        try {
            $hooks = App::i()->db()->all(
                'SELECT * FROM user_webhooks WHERE user_id = ? AND is_active = 1',
                [$userId]
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($hooks as $hook) {
            $events = array_map('trim', explode(',', (string) $hook['events']));

            if (!in_array($event, $events, true) && !in_array('*', $events, true)) {
                continue;
            }

            $body = [
                'event'      => $event,
                'data'       => $payload,
                'sent_at'    => now_utc(),
            ];

            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $signature = Crypto::hmac($encoded, (string) ($hook['secret'] ?? ''));

            $result = HttpClient::request('POST', (string) $hook['url'], [
                'body'    => $encoded,
                'headers' => [
                    'Content-Type'        => 'application/json',
                    'X-Krishna-Event'     => $event,
                    'X-Krishna-Signature' => $signature,
                ],
                'timeout' => 8,
            ]);

            try {
                App::i()->db()->update('user_webhooks', [
                    'last_status'    => $result['status'],
                    'last_called_at' => now_utc(),
                ], 'id = :id', ['id' => (int) $hook['id']]);
            } catch (\Throwable) {
                // Ignore.
            }
        }
    }
}
