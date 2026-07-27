<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * Google OAuth 2.0 + two-way Calendar/Tasks sync, implemented with plain cURL.
 *
 * Everything here is optional: when a user has not connected Google, the local
 * database remains the single source of truth and no feature is blocked.
 */
class GoogleService
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const TASKS_API = 'https://tasks.googleapis.com/tasks/v1';
    private const USERINFO = 'https://www.googleapis.com/oauth2/v3/userinfo';

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/tasks',
        'openid',
        'email',
        'profile',
    ];

    public static function isEnabled(): bool
    {
        $settings = App::i()->settings();

        return $settings->bool('google_enabled', false)
            && (string) $settings->get('google_client_id', '') !== ''
            && (string) $settings->get('google_client_secret', '') !== '';
    }

    public static function authUrl(string $state, bool $loginOnly = false): string
    {
        $params = [
            'client_id'     => (string) App::i()->settings()->get('google_client_id', ''),
            'redirect_uri'  => App::i()->url('/auth/google/callback'),
            'response_type' => 'code',
            'scope'         => implode(' ', $loginOnly ? ['openid', 'email', 'profile'] : self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * @return array{ok: bool, tokens: array|null, error: string|null}
     */
    public static function exchangeCode(string $code): array
    {
        $settings = App::i()->settings();

        $response = HttpClient::request('POST', self::TOKEN_URL, [
            'form' => [
                'code'          => $code,
                'client_id'     => (string) $settings->get('google_client_id', ''),
                'client_secret' => (string) $settings->get('google_client_secret', ''),
                'redirect_uri'  => App::i()->url('/auth/google/callback'),
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 20,
        ]);

        if (!$response['ok'] || !is_array($response['json'])) {
            return ['ok' => false, 'tokens' => null, 'error' => mb_substr($response['body'] ?: (string) $response['error'], 0, 300)];
        }

        return ['ok' => true, 'tokens' => $response['json'], 'error' => null];
    }

    public static function userInfo(string $accessToken): ?array
    {
        $response = HttpClient::get(self::USERINFO, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 15,
        ]);

        return $response['ok'] && is_array($response['json']) ? $response['json'] : null;
    }

    public static function storeAccount(int $userId, array $tokens, ?array $profile = null): void
    {
        $db = App::i()->db();

        $data = [
            'user_id'          => $userId,
            'google_user_id'   => $profile['sub'] ?? null,
            'email'            => $profile['email'] ?? null,
            'access_token'     => Crypto::encrypt((string) ($tokens['access_token'] ?? '')),
            'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
            'scopes'           => (string) ($tokens['scope'] ?? ''),
            'is_active'        => 1,
            'created_at'       => now_utc(),
        ];

        // Google only returns the refresh token on first consent.
        if (!empty($tokens['refresh_token'])) {
            $data['refresh_token'] = Crypto::encrypt((string) $tokens['refresh_token']);
        }

        $db->upsert('google_accounts', $data, array_values(array_diff(array_keys($data), ['user_id', 'created_at'])));
        $db->update('user_settings', ['google_sync_enabled' => 1], 'user_id = :uid', ['uid' => $userId]);
    }

    public static function account(int $userId): ?array
    {
        return App::i()->db()->one('SELECT * FROM google_accounts WHERE user_id = ? AND is_active = 1', [$userId]);
    }

    /**
     * Return a valid access token, refreshing it when necessary.
     */
    public static function accessToken(int $userId): ?string
    {
        $account = self::account($userId);

        if ($account === null) {
            return null;
        }

        $expires = strtotime((string) $account['token_expires_at']);

        if ($expires > time() + 60) {
            return Crypto::decrypt((string) $account['access_token']);
        }

        $refresh = Crypto::decrypt((string) ($account['refresh_token'] ?? ''));

        if ($refresh === null || $refresh === '') {
            return null;
        }

        $settings = App::i()->settings();

        $response = HttpClient::request('POST', self::TOKEN_URL, [
            'form' => [
                'refresh_token' => $refresh,
                'client_id'     => (string) $settings->get('google_client_id', ''),
                'client_secret' => (string) $settings->get('google_client_secret', ''),
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 20,
        ]);

        $token = $response['json']['access_token'] ?? null;

        if (!is_string($token)) {
            Logger::warn('Google token refresh failed', ['user_id' => $userId], 'google');

            return null;
        }

        App::i()->db()->update('google_accounts', [
            'access_token'     => Crypto::encrypt($token),
            'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($response['json']['expires_in'] ?? 3600)),
        ], 'user_id = :uid', ['uid' => $userId]);

        return $token;
    }

    public static function disconnect(int $userId, bool $deleteRemoteEvents = false): bool
    {
        $account = self::account($userId);

        if ($account === null) {
            return false;
        }

        if ($deleteRemoteEvents) {
            $token = self::accessToken($userId);

            if ($token !== null) {
                $maps = App::i()->db()->all('SELECT * FROM google_sync_map WHERE user_id = ? AND google_kind = "event"', [$userId]);

                foreach ($maps as $map) {
                    self::deleteEvent($token, (string) $account['calendar_id'], (string) $map['google_id']);
                }
            }
        }

        $refresh = Crypto::decrypt((string) ($account['refresh_token'] ?? ''));

        if ($refresh !== null && $refresh !== '') {
            HttpClient::request('POST', self::REVOKE_URL, ['form' => ['token' => $refresh], 'timeout' => 10]);
        }

        $db = App::i()->db();
        $db->delete('google_sync_map', 'user_id = ?', [$userId]);
        $db->delete('google_accounts', 'user_id = ?', [$userId]);
        $db->update('user_settings', ['google_sync_enabled' => 0, 'google_tasks_enabled' => 0], 'user_id = :uid', ['uid' => $userId]);

        return true;
    }

    /* ------------------------------------------------------------- Syncing */

    /**
     * Mark a reminder as needing a push on the next sync run.
     */
    public static function queuePush(int $userId, int $reminderId): void
    {
        try {
            App::i()->db()->query(
                'UPDATE google_sync_map SET local_updated_at = NULL WHERE user_id = ? AND reminder_id = ?',
                [$userId, $reminderId]
            );
        } catch (\Throwable) {
            // No mapping yet — the sync run will create the event.
        }
    }

    public static function queueDelete(int $userId, int $reminderId): void
    {
        // The sync run detects reminders with deleted_at set and removes the
        // remote event; nothing else is needed here beyond clearing the marker.
        self::queuePush($userId, $reminderId);
    }

    /**
     * Full two-way sync for one user.
     *
     * @return array{pushed: int, pulled: int, deleted: int, errors: int}
     */
    public static function syncUser(int $userId): array
    {
        $stats = ['pushed' => 0, 'pulled' => 0, 'deleted' => 0, 'errors' => 0];

        if (!self::isEnabled() || !PlanService::can($userId, 'google_sync')) {
            return $stats;
        }

        $settings = ReminderService::userSettings($userId);

        if ((int) ($settings['google_sync_enabled'] ?? 0) !== 1) {
            return $stats;
        }

        $token = self::accessToken($userId);
        $account = self::account($userId);

        if ($token === null || $account === null) {
            return $stats;
        }

        $calendarId = (string) ($account['calendar_id'] ?: 'primary');
        $db = App::i()->db();

        /* ---- Push local changes ---- */
        $reminders = $db->all(
            'SELECT r.*, m.google_id, m.local_updated_at
               FROM reminders r
               LEFT JOIN google_sync_map m ON m.reminder_id = r.id AND m.google_kind = "event"
              WHERE r.user_id = ?
                AND r.source <> "google"
                AND (m.id IS NULL OR m.local_updated_at IS NULL OR m.local_updated_at < r.updated_at)
              ORDER BY r.updated_at DESC
              LIMIT 100',
            [$userId]
        );

        foreach ($reminders as $reminder) {
            try {
                if (!empty($reminder['deleted_at'])) {
                    if (!empty($reminder['google_id'])) {
                        self::deleteEvent($token, $calendarId, (string) $reminder['google_id']);
                        $db->delete('google_sync_map', 'user_id = ? AND reminder_id = ?', [$userId, (int) $reminder['id']]);
                        $stats['deleted']++;
                    }
                    continue;
                }

                $event = self::eventBody($reminder, $userId);

                if (empty($reminder['google_id'])) {
                    $response = HttpClient::postJson(
                        self::CALENDAR_API . '/calendars/' . rawurlencode($calendarId) . '/events',
                        $event,
                        ['Authorization' => 'Bearer ' . $token],
                        20
                    );

                    if ($response['ok'] && !empty($response['json']['id'])) {
                        $db->upsert('google_sync_map', [
                            'user_id'          => $userId,
                            'reminder_id'      => (int) $reminder['id'],
                            'google_kind'      => 'event',
                            'google_id'        => (string) $response['json']['id'],
                            'etag'             => (string) ($response['json']['etag'] ?? ''),
                            'local_updated_at' => now_utc(),
                            'remote_updated_at'=> now_utc(),
                            'created_at'       => now_utc(),
                        ], ['reminder_id', 'etag', 'local_updated_at', 'remote_updated_at']);
                        $stats['pushed']++;
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    $response = HttpClient::request(
                        'PATCH',
                        self::CALENDAR_API . '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode((string) $reminder['google_id']),
                        ['json' => $event, 'headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 20]
                    );

                    if ($response['ok']) {
                        $db->query(
                            'UPDATE google_sync_map SET local_updated_at = ?, remote_updated_at = ? WHERE user_id = ? AND reminder_id = ?',
                            [now_utc(), now_utc(), $userId, (int) $reminder['id']]
                        );
                        $stats['pushed']++;
                    } else {
                        $stats['errors']++;
                    }
                }
            } catch (\Throwable $e) {
                Logger::warn('Google push failed', ['reminder_id' => $reminder['id'], 'error' => $e->getMessage()], 'google');
                $stats['errors']++;
            }
        }

        /* ---- Pull remote changes ---- */
        $query = [
            'maxResults'   => 100,
            'singleEvents' => 'true',
            'showDeleted'  => 'true',
        ];

        if (!empty($account['sync_token'])) {
            $query['syncToken'] = (string) $account['sync_token'];
        } else {
            $query['timeMin'] = gmdate('c', time() - 86400);
            $query['timeMax'] = gmdate('c', time() + (30 * 86400));
        }

        $response = HttpClient::get(self::CALENDAR_API . '/calendars/' . rawurlencode($calendarId) . '/events', [
            'query'   => $query,
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 25,
        ]);

        if ($response['status'] === 410) {
            // Sync token expired — start a fresh window next run.
            $db->update('google_accounts', ['sync_token' => null], 'user_id = :uid', ['uid' => $userId]);

            return $stats;
        }

        if ($response['ok'] && is_array($response['json'])) {
            foreach ($response['json']['items'] ?? [] as $item) {
                try {
                    $stats['pulled'] += self::applyRemoteEvent($userId, $item) ? 1 : 0;
                } catch (\Throwable $e) {
                    Logger::warn('Google pull failed', ['error' => $e->getMessage()], 'google');
                    $stats['errors']++;
                }
            }

            $db->update('google_accounts', [
                'sync_token'   => $response['json']['nextSyncToken'] ?? $account['sync_token'],
                'last_sync_at' => now_utc(),
            ], 'user_id = :uid', ['uid' => $userId]);
        }

        return $stats;
    }

    /**
     * Apply one remote event locally. Conflict rule: last write wins.
     */
    private static function applyRemoteEvent(int $userId, array $item): bool
    {
        $db = App::i()->db();
        $googleId = (string) ($item['id'] ?? '');

        if ($googleId === '') {
            return false;
        }

        $map = $db->one(
            'SELECT * FROM google_sync_map WHERE user_id = ? AND google_kind = "event" AND google_id = ?',
            [$userId, $googleId]
        );

        // Deleted in Google -> cancel locally.
        if (($item['status'] ?? '') === 'cancelled') {
            if ($map !== null && $map['reminder_id'] !== null) {
                ReminderService::cancelReminder((int) $map['reminder_id'], $userId);
                $db->delete('google_sync_map', 'id = ?', [(int) $map['id']]);

                return true;
            }

            return false;
        }

        $startRaw = $item['start']['dateTime'] ?? ($item['start']['date'] ?? null);

        if ($startRaw === null) {
            return false;
        }

        $allDay = !isset($item['start']['dateTime']);
        $startUtc = gmdate('Y-m-d H:i:s', strtotime($startRaw) ?: time());
        $title = mb_substr((string) ($item['summary'] ?? 'Google event'), 0, 255);
        $description = mb_substr((string) ($item['description'] ?? ''), 0, 2000);

        if ($map !== null && $map['reminder_id'] !== null) {
            $reminder = $db->one('SELECT * FROM reminders WHERE id = ?', [(int) $map['reminder_id']]);

            if ($reminder === null) {
                return false;
            }

            $remoteUpdated = strtotime((string) ($item['updated'] ?? 'now'));
            $localUpdated = strtotime((string) $reminder['updated_at'] . ' UTC');

            if ($remoteUpdated <= $localUpdated) {
                return false; // Local edit is newer — keep it.
            }

            ReminderService::update((int) $reminder['id'], $userId, [
                'title'       => $title,
                'description' => $description,
                'start_at'    => $startUtc,
                'all_day'     => $allDay ? 1 : 0,
                'location'    => mb_substr((string) ($item['location'] ?? ''), 0, 255) ?: null,
            ]);

            $db->update('google_sync_map', [
                'remote_updated_at' => now_utc(),
                'local_updated_at'  => now_utc(),
                'etag'              => (string) ($item['etag'] ?? ''),
            ], 'id = :id', ['id' => (int) $map['id']]);

            return true;
        }

        // Brand new event created in Google Calendar.
        $reminder = ReminderService::create($userId, [
            'title'         => $title,
            'description'   => $description,
            'type'          => 'task',
            'start_at'      => $startUtc,
            'all_day'       => $allDay ? 1 : 0,
            'location'      => mb_substr((string) ($item['location'] ?? ''), 0, 255) ?: null,
            'source'        => 'google',
            'source_ref'    => $googleId,
            'call_reminder' => 0,
        ]);

        if ($reminder === null) {
            return false;
        }

        $db->upsert('google_sync_map', [
            'user_id'           => $userId,
            'reminder_id'       => (int) $reminder['id'],
            'google_kind'       => 'event',
            'google_id'         => $googleId,
            'etag'              => (string) ($item['etag'] ?? ''),
            'direction'         => 'pull',
            'local_updated_at'  => now_utc(),
            'remote_updated_at' => now_utc(),
            'created_at'        => now_utc(),
        ], ['reminder_id', 'etag', 'local_updated_at', 'remote_updated_at']);

        return true;
    }

    private static function eventBody(array $reminder, int $userId): array
    {
        $tz = ReminderService::userTimezone($userId);
        $startUtc = (string) $reminder['start_at'];
        $allDay = (int) $reminder['all_day'] === 1;

        $start = $allDay
            ? ['date' => to_user_time($startUtc, 'Y-m-d', $tz)]
            : ['dateTime' => gmdate('c', strtotime($startUtc . ' UTC')), 'timeZone' => $tz];

        $endTs = strtotime($startUtc . ' UTC') + 1800;

        $end = $allDay
            ? ['date' => to_user_time(gmdate('Y-m-d H:i:s', $endTs), 'Y-m-d', $tz)]
            : ['dateTime' => gmdate('c', $endTs), 'timeZone' => $tz];

        $body = [
            'summary'     => (string) $reminder['title'],
            'description' => trim((string) $reminder['description'] . "\n\n[Krishna Reminder " . $reminder['short_code'] . ']'),
            'start'       => $start,
            'end'         => $end,
            'reminders'   => [
                'useDefault' => false,
                'overrides'  => [['method' => 'popup', 'minutes' => 10]],
            ],
        ];

        if (!empty($reminder['location'])) {
            $body['location'] = (string) $reminder['location'];
        }

        $rule = json_field($reminder['recurrence'] ?? null, ['freq' => 'none']);
        $rrule = self::toRrule($rule);

        if ($rrule !== null) {
            $body['recurrence'] = [$rrule];
        }

        return $body;
    }

    private static function toRrule(array $rule): ?string
    {
        $freq = strtoupper((string) ($rule['freq'] ?? 'none'));

        if ($freq === 'NONE' || $freq === '') {
            return null;
        }

        $parts = ['FREQ=' . $freq];

        if (($rule['interval'] ?? 1) > 1) {
            $parts[] = 'INTERVAL=' . (int) $rule['interval'];
        }

        if (!empty($rule['by_day'])) {
            $parts[] = 'BYDAY=' . implode(',', array_map('strtoupper', (array) $rule['by_day']));
        }

        if (!empty($rule['by_month_day'])) {
            $parts[] = 'BYMONTHDAY=' . (int) $rule['by_month_day'];
        }

        if (!empty($rule['count'])) {
            $parts[] = 'COUNT=' . (int) $rule['count'];
        }

        if (!empty($rule['until'])) {
            $parts[] = 'UNTIL=' . gmdate('Ymd\THis\Z', strtotime((string) $rule['until']) ?: time());
        }

        return 'RRULE:' . implode(';', $parts);
    }

    private static function deleteEvent(string $token, string $calendarId, string $eventId): void
    {
        HttpClient::request(
            'DELETE',
            self::CALENDAR_API . '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 15]
        );
    }

    /**
     * Optional Google Tasks push (one-way, toggled per user).
     */
    public static function pushTask(int $userId, array $reminder): bool
    {
        $token = self::accessToken($userId);
        $account = self::account($userId);

        if ($token === null || $account === null || empty($account['tasklist_id'])) {
            return false;
        }

        $response = HttpClient::postJson(
            self::TASKS_API . '/lists/' . rawurlencode((string) $account['tasklist_id']) . '/tasks',
            [
                'title' => (string) $reminder['title'],
                'notes' => (string) $reminder['description'],
                'due'   => gmdate('c', strtotime((string) $reminder['start_at'] . ' UTC')),
            ],
            ['Authorization' => 'Bearer ' . $token],
            20
        );

        return $response['ok'];
    }
}
