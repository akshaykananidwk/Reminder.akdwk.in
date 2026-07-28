<?php

/**
 * Mobile API v1.
 *
 * Every response uses the {success, data, message, code} envelope.
 * Auth: Bearer access token (30 days) + refresh token (2 years).
 * Write endpoints honour the `Idempotency-Key` header so offline replays from
 * the Android app can never create duplicates.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\AppPinService;
use App\Services\FcmService;
use App\Services\GeminiService;
use App\Services\InboundProcessor;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\ReminderService;
use App\Services\ReportService;
use App\Services\SummaryService;
use App\Services\UploadService;

$app = App::i();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Device-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (Request::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!$app->isInstalled()) {
    Response::error('Service not installed', 503, 'NOT_INSTALLED');
}

/* --------------------------------------------------------------- Routing */

$path = Request::path();
$path = preg_replace('#^/api/v1#', '', $path) ?: '/';
$path = '/' . trim((string) $path, '/');
$method = Request::method();
$segments = array_values(array_filter(explode('/', $path)));

/* ------------------------------------------------------------ Rate limit */

if (!RateLimiter::attempt('api_' . Request::ip(), 600, 300)) {
    Response::error(Lang::get('api.rate_limited'), 429, 'RATE_LIMITED');
}

/* -------------------------------------------------------------- Helpers */

/** Replay-safe write: returns a cached response when the key was seen before. */
$idempotent = static function (int $userId, string $endpoint, callable $work): never {
    $key = Request::header('Idempotency-Key');

    if (!is_string($key) || $key === '') {
        $work();
        Response::error('Handler did not respond', 500, 'NO_RESPONSE');
    }

    $key = mb_substr($key, 0, 120);
    $db = App::i()->db();

    $existing = $db->one('SELECT response FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$userId, $key]);

    if ($existing !== null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Idempotent-Replay: true');
        echo (string) $existing['response'];
        exit;
    }

    // Capture the handler's output so the exact same body can be replayed.
    // Handlers end with Response::json(), which exits — so the body is stored
    // from a shutdown callback, which still sees the open output buffer.
    ob_start();

    register_shutdown_function(static function () use ($db, $userId, $key, $endpoint): void {
        $body = ob_get_contents();

        if (!is_string($body) || $body === '') {
            return;
        }

        try {
            $db->insert('idempotency_keys', [
                'user_id'    => $userId,
                'idem_key'   => $key,
                'endpoint'   => mb_substr($endpoint, 0, 120),
                'response'   => $body,
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable) {
            // Duplicate key — a concurrent replay already stored it.
        }
    });

    $work();

    // A handler that returned without responding is a bug; fail loudly.
    Response::error('Handler did not respond', 500, 'NO_RESPONSE');
};

/** Serialise a reminder + its next occurrence for the app. */
$reminderJson = static function (array $r, ?array $occurrence = null): array {
    return [
        'id'            => (int) $r['id'],
        'short_code'    => (string) $r['short_code'],
        'title'         => (string) $r['title'],
        'description'   => (string) ($r['description'] ?? ''),
        'type'          => (string) $r['type'],
        'priority'      => (string) $r['priority'],
        'category_id'   => $r['category_id'] === null ? null : (int) $r['category_id'],
        'start_at'      => (string) $r['start_at'],
        'end_at'        => $r['end_at'],
        'all_day'       => (bool) $r['all_day'],
        'recurrence'    => json_field($r['recurrence'] ?? null, ['freq' => 'none']),
        'call_reminder' => (bool) $r['call_reminder'],
        'advance_alerts'=> json_field($r['advance_alerts'] ?? null, []),
        'snooze_default_min' => (int) $r['snooze_default_min'],
        'amount'        => $r['amount'] === null ? null : (float) $r['amount'],
        'currency'      => (string) $r['currency'],
        'person_name'   => $r['person_name'],
        'person_phone'  => $r['person_phone'],
        'location'      => $r['location'],
        'color'         => $r['color'],
        'source'        => (string) $r['source'],
        'status'        => (string) $r['status'],
        'assigned_to'   => $r['assigned_to'] === null ? null : (int) $r['assigned_to'],
        'created_at'    => (string) $r['created_at'],
        'updated_at'    => (string) $r['updated_at'],
        'deleted'       => $r['deleted_at'] !== null,
        'next_occurrence' => $occurrence,
    ];
};

$occurrenceJson = static function (array $o): array {
    return [
        'id'            => (int) $o['id'],
        'reminder_id'   => (int) $o['reminder_id'],
        'due_at'        => (string) $o['due_at'],
        'status'        => (string) $o['status'],
        'attempt_count' => (int) $o['attempt_count'],
        'snooze_count'  => (int) $o['snooze_count'],
        'done_at'       => $o['done_at'],
        'done_via'      => $o['done_via'],
        'title'         => $o['title'] ?? null,
        'short_code'    => $o['short_code'] ?? null,
        'type'          => $o['type'] ?? null,
        'priority'      => $o['priority'] ?? null,
        'amount'        => isset($o['amount']) && $o['amount'] !== null ? (float) $o['amount'] : null,
        'currency'      => $o['currency'] ?? 'INR',
        'updated_at'    => (string) ($o['updated_at'] ?? $o['created_at'] ?? now_utc()),
    ];
};

$db = $app->db();

try {
    /* =================================================== Public endpoints */

    if ($segments === ['health']) {
        // auth_header_received tells you, without guessing, whether Apache is
        // passing the Authorization header through to PHP. On CGI/FastCGI it
        // strips it by default, and the only symptom is that signing in works
        // and every authenticated call then answers 401.
        //
        //   curl -H 'Authorization: Bearer test' https://…/api/v1/health
        //
        // If that shows false, the .htaccess rules are not being applied —
        // check that AllowOverride permits them.
        Response::ok([
            'status'               => 'ok',
            'time'                 => now_utc(),
            'version'              => $app->config('app.version'),
            'auth_header_received' => Request::bearerToken() !== null,
            'sapi'                 => PHP_SAPI,
        ]);
    }

    if ($segments === ['app', 'version']) {
        $settings = $app->settings();

        Response::ok([
            'latest_version'   => (string) $settings->get('app_version', '1.0.0'),
            'min_version'      => (string) $settings->get('app_min_version', '1.0.0'),
            'download_url'     => (string) $settings->get('apk_release_url', ''),
            'release_notes'    => (string) $settings->get('app_release_notes', ''),
            'force_update'     => $settings->bool('app_force_update', false),
        ]);
    }

    if ($segments === ['auth', 'request-otp'] && $method === 'POST') {
        $phone = normalize_phone((string) Request::post('phone', ''));

        if ($phone === '') {
            Response::error(Lang::get('auth.invalid_number'), 422, 'INVALID_PHONE');
        }

        $number = $db->one(
            'SELECT w.*, u.language FROM whatsapp_numbers w JOIN users u ON u.id = w.user_id
              WHERE w.number = ? AND w.is_verified = 1 AND w.is_active = 1 AND u.is_active = 1 AND u.deleted_at IS NULL',
            [$phone]
        );

        if ($number === null) {
            // Do not reveal whether the number exists; behave identically.
            Response::ok(['sent' => true, 'retry_after' => OtpService::RESEND_COOLDOWN], Lang::get('auth.otp_sent'));
        }

        $result = OtpService::send($phone, 'login', (int) $number['user_id'], (string) $number['language']);

        if (!$result['ok']) {
            Response::error($result['message'], 429, 'OTP_THROTTLED', ['retry_after' => $result['retry_after']]);
        }

        Response::ok(['sent' => true, 'retry_after' => $result['retry_after']], $result['message']);
    }

    if ($segments === ['auth', 'verify-otp'] && $method === 'POST') {
        $phone = normalize_phone((string) Request::post('phone', ''));
        $code = (string) Request::post('code', '');

        $verified = OtpService::verify($phone, $code, 'login');

        if (!$verified['ok']) {
            Response::error($verified['message'], 401, 'OTP_INVALID');
        }

        $user = $db->one(
            'SELECT u.* FROM whatsapp_numbers w JOIN users u ON u.id = w.user_id WHERE w.number = ? AND w.is_verified = 1',
            [$phone]
        );

        if ($user === null || (int) $user['is_active'] !== 1) {
            Response::error(Lang::get('auth.account_suspended'), 403, 'ACCOUNT_INACTIVE');
        }

        // Register/update the device in the same call.
        $deviceId = null;
        $deviceUid = (string) Request::post('device_uid', '');

        if ($deviceUid !== '') {
            $deviceId = (int) $db->upsert('devices', [
                'user_id'      => (int) $user['id'],
                'device_uid'   => mb_substr($deviceUid, 0, 128),
                'fcm_token'    => Request::post('fcm_token') ?: null,
                'name'         => Request::post('name') ?: null,
                'model'        => Request::post('model') ?: null,
                'manufacturer' => Request::post('manufacturer') ?: null,
                'os_version'   => Request::post('os_version') ?: null,
                'app_version'  => Request::post('app_version') ?: null,
                'platform'     => 'android',
                'is_active'    => 1,
                'last_seen_at' => now_utc(),
                'created_at'   => now_utc(),
            ], ['fcm_token', 'name', 'model', 'manufacturer', 'os_version', 'app_version', 'is_active', 'last_seen_at']);

            if ($deviceId === 0) {
                $row = $db->one('SELECT id FROM devices WHERE user_id = ? AND device_uid = ?', [(int) $user['id'], $deviceUid]);
                $deviceId = $row === null ? null : (int) $row['id'];
            }
        }

        $tokens = Auth::issueApiTokens((int) $user['id'], $deviceId);
        \App\Services\AuditService::loginAttempt($phone, true, 'api');

        Response::ok([
            'tokens' => $tokens,
            'user'   => [
                'id'       => (int) $user['id'],
                'name'     => (string) $user['name'],
                'phone'    => (string) $user['phone'],
                'email'    => $user['email'],
                'language' => (string) $user['language'],
                'timezone' => (string) $user['timezone'],
                'streak'   => (int) $user['streak_days'],
            ],
            'device_id' => $deviceId,
        ], Lang::get('auth.welcome_back', ['name' => $user['name']]));
    }

    /**
     * Sign in with the four-digit app PIN the user set on the website.
     *
     * This exists because OTP-over-WhatsApp was the only way in: when the
     * gateway is down, the OTP never arrives, sign-in never completes, and the
     * app sits on an empty list with no way forward. The PIN needs nothing but
     * the site itself.
     *
     * A four-digit secret is only acceptable with the throttling and lockout in
     * AppPinService behind it, plus the per-IP limiter below.
     */
    if ($segments === ['auth', 'pin'] && $method === 'POST') {
        $phone = normalize_phone((string) Request::post('phone', ''));
        $pin = trim((string) Request::post('pin', ''));

        // Per-IP ceiling on top of the per-account lockout, so one attacker
        // cannot work through many accounts from the same place.
        if (!RateLimiter::attempt('pin_ip_' . Request::ip(), 20, 900)) {
            Response::error(Lang::get('auth.otp_too_many_attempts'), 429, 'RATE_LIMITED', ['retry_after' => 900]);
        }

        $result = AppPinService::verify($phone, $pin);

        if (!$result['ok']) {
            \App\Services\AuditService::loginAttempt($phone, false, 'api_pin');

            Response::error(
                $result['message'],
                $result['code'] === 'PIN_LOCKED' ? 429 : 401,
                $result['code'],
                ['retry_after' => $result['retry_after']]
            );
        }

        $user = $result['user'];

        // Same device registration as the OTP path, so a PIN sign-in is a
        // first-class login and push still reaches the phone.
        $deviceId = null;
        $deviceUid = (string) Request::post('device_uid', '');

        if ($deviceUid !== '') {
            $deviceId = (int) $db->upsert('devices', [
                'user_id'      => (int) $user['id'],
                'device_uid'   => mb_substr($deviceUid, 0, 128),
                'fcm_token'    => Request::post('fcm_token') ?: null,
                'name'         => Request::post('name') ?: null,
                'model'        => Request::post('model') ?: null,
                'manufacturer' => Request::post('manufacturer') ?: null,
                'os_version'   => Request::post('os_version') ?: null,
                'app_version'  => Request::post('app_version') ?: null,
                'platform'     => 'android',
                'is_active'    => 1,
                'last_seen_at' => now_utc(),
                'created_at'   => now_utc(),
            ], ['fcm_token', 'name', 'model', 'manufacturer', 'os_version', 'app_version', 'is_active', 'last_seen_at']);

            if ($deviceId === 0) {
                $row = $db->one('SELECT id FROM devices WHERE user_id = ? AND device_uid = ?', [(int) $user['id'], $deviceUid]);
                $deviceId = $row === null ? null : (int) $row['id'];
            }
        }

        $tokens = Auth::issueApiTokens((int) $user['id'], $deviceId);
        \App\Services\AuditService::loginAttempt($phone, true, 'api_pin');

        Response::ok([
            'tokens' => $tokens,
            'user'   => [
                'id'       => (int) $user['id'],
                'name'     => (string) $user['name'],
                'phone'    => (string) $user['phone'],
                'email'    => $user['email'],
                'language' => (string) $user['language'],
                'timezone' => (string) $user['timezone'],
                'streak'   => (int) $user['streak_days'],
            ],
            'device_id' => $deviceId,
        ], Lang::get('auth.welcome_back', ['name' => $user['name']]));
    }

    if ($segments === ['auth', 'refresh'] && $method === 'POST') {
        $refresh = (string) Request::post('refresh_token', '');
        $tokens = Auth::refreshApiTokens($refresh);

        if ($tokens === null) {
            Response::error('Refresh token is invalid or expired', 401, 'REFRESH_INVALID');
        }

        Response::ok(['tokens' => $tokens]);
    }

    /* =============================================== Authenticated below */

    $user = Auth::requireApiUser();
    $userId = (int) $user['id'];
    $tz = (string) $user['timezone'];
    Lang::setLocale((string) $user['language']);

    if ($segments === ['auth', 'logout'] && $method === 'POST') {
        $session = Auth::apiSession();

        if ($session !== null) {
            Auth::revokeSession((int) $session['id'], $userId);
        }

        Response::ok(['logged_out' => true], Lang::get('auth.logged_out'));
    }

    /* ------------------------------------------------------------- me --- */

    if ($segments === ['me'] && $method === 'GET') {
        $settings = ReminderService::userSettings($userId);
        $plan = PlanService::forUser($userId);

        Response::ok([
            'user' => [
                'id'       => $userId,
                'name'     => (string) $user['name'],
                'phone'    => (string) $user['phone'],
                'email'    => $user['email'],
                'language' => (string) $user['language'],
                'timezone' => $tz,
                'city'     => $user['city'],
                'streak'   => (int) $user['streak_days'],
                'best_streak' => (int) $user['best_streak'],
                'reminders_paused' => (bool) $user['reminders_paused'],
                'plan_expires_at'  => $user['plan_expires_at'],
            ],
            'settings' => $settings,
            'plan'     => [
                'code'  => (string) ($plan['code'] ?? 'trial'),
                'name'  => (string) ($plan['name'] ?? 'Trial'),
                'call_reminders' => (bool) ($plan['call_reminders'] ?? 1),
                'google_sync'    => (bool) ($plan['google_sync'] ?? 0),
                'api_access'     => (bool) ($plan['api_access'] ?? 0),
                'max_devices'    => (int) ($plan['max_devices'] ?? 1),
            ],
            'usage' => PlanService::usage($userId),
        ]);
    }

    if ($segments === ['me'] && in_array($method, ['POST', 'PATCH'], true)) {
        $fields = [];

        foreach (['name', 'email', 'city'] as $field) {
            $value = Request::post($field);

            if ($value !== null && $value !== '') {
                $fields[$field] = mb_substr((string) $value, 0, 190);
            }
        }

        $language = (string) Request::post('language', '');

        if (in_array($language, Lang::SUPPORTED, true)) {
            $fields['language'] = $language;
        }

        $timezone = (string) Request::post('timezone', '');

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            $fields['timezone'] = $timezone;
        }

        if ($fields !== []) {
            $db->update('users', $fields, 'id = :id', ['id' => $userId]);
        }

        Response::ok(['updated' => true], Lang::get('common.saved'));
    }

    if ($segments === ['me', 'settings'] && $method === 'GET') {
        Response::ok(ReminderService::userSettings($userId));
    }

    if ($segments === ['me', 'settings'] && in_array($method, ['POST', 'PATCH'], true)) {
        $allowed = [
            'morning_brief_time', 'night_summary_time', 'morning_brief_enabled', 'night_summary_enabled',
            'dnd_start', 'dnd_end', 'dnd_enabled', 'default_time', 'default_snooze_min', 'max_snoozes',
            'call_attempts', 'call_gap_minutes', 'ring_seconds', 'whatsapp_fallback', 'ringtone',
            'tts_enabled', 'tts_voice', 'tts_speed', 'wa_notify_created', 'wa_notify_due',
            'wa_notify_done', 'wa_notify_missed', 'theme', 'holiday_mode_until',
        ];

        $fields = [];

        foreach ($allowed as $field) {
            $value = Request::post($field);

            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        if ($fields !== []) {
            ReminderService::ensureSettings($userId);
            $db->update('user_settings', $fields, 'user_id = :uid', ['uid' => $userId]);
        }

        Response::ok(['updated' => true], Lang::get('common.saved'));
    }

    /* --------------------------------------------------------- devices --- */

    if ($segments === ['devices', 'register'] && $method === 'POST') {
        $deviceUid = (string) Request::post('device_uid', '');

        if ($deviceUid === '') {
            Response::error('device_uid is required', 422, 'VALIDATION_FAILED');
        }

        $existing = $db->one('SELECT id FROM devices WHERE user_id = ? AND device_uid = ?', [$userId, $deviceUid]);

        if ($existing === null && !PlanService::canAddDevice($userId)) {
            Response::error('Your plan allows fewer devices. Please upgrade or remove a device.', 403, 'DEVICE_LIMIT');
        }

        $db->upsert('devices', [
            'user_id'      => $userId,
            'device_uid'   => mb_substr($deviceUid, 0, 128),
            'fcm_token'    => Request::post('fcm_token') ?: null,
            'name'         => Request::post('name') ?: null,
            'model'        => Request::post('model') ?: null,
            'manufacturer' => Request::post('manufacturer') ?: null,
            'os_version'   => Request::post('os_version') ?: null,
            'app_version'  => Request::post('app_version') ?: null,
            'platform'     => 'android',
            'push_ok'      => 1,
            'is_active'    => 1,
            'last_seen_at' => now_utc(),
            'created_at'   => now_utc(),
        ], ['fcm_token', 'name', 'model', 'manufacturer', 'os_version', 'app_version', 'push_ok', 'is_active', 'last_seen_at']);

        $row = $db->one('SELECT * FROM devices WHERE user_id = ? AND device_uid = ?', [$userId, $deviceUid]);

        Response::ok(['device_id' => (int) ($row['id'] ?? 0)], 'Device registered');
    }

    if ($segments === ['devices', 'heartbeat'] && $method === 'POST') {
        $deviceUid = (string) Request::post('device_uid', '');

        $db->update('devices', [
            'last_seen_at' => now_utc(),
            'fcm_token'    => Request::post('fcm_token') ?: null,
            'app_version'  => Request::post('app_version') ?: null,
        ], 'user_id = :uid AND device_uid = :duid', ['uid' => $userId, 'duid' => $deviceUid]);

        Response::ok(['ok' => true, 'server_time' => now_utc()]);
    }

    if ($segments === ['devices'] && $method === 'GET') {
        Response::ok($db->all('SELECT id, device_uid, name, model, manufacturer, os_version, app_version, push_ok, last_seen_at FROM devices WHERE user_id = ? AND is_active = 1', [$userId]));
    }

    /* ------------------------------------------------------- reminders --- */

    if ($segments === ['reminders'] && $method === 'GET') {
        $since = (string) Request::get('since', '');
        $limit = min(500, max(1, (int) Request::get('limit', 200)));

        $where = 'r.user_id = ?';
        $params = [$userId];

        if ($since !== '' && strtotime($since) !== false) {
            // Delta sync must include soft-deleted rows so the app can drop them.
            $where .= ' AND r.updated_at > ?';
            $params[] = gmdate('Y-m-d H:i:s', strtotime($since));
        } else {
            $where .= ' AND r.deleted_at IS NULL';
        }

        $status = (string) Request::get('status', '');

        if (in_array($status, ['active', 'completed', 'cancelled', 'paused'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        $rows = $db->all("SELECT r.* FROM reminders r WHERE $where ORDER BY r.updated_at DESC LIMIT $limit", $params);

        $out = [];

        foreach ($rows as $row) {
            $next = $db->one(
                'SELECT * FROM reminder_occurrences WHERE reminder_id = ? AND status IN ("pending","notified","snoozed") ORDER BY due_at ASC LIMIT 1',
                [(int) $row['id']]
            );

            $out[] = $reminderJson($row, $next === null ? null : $occurrenceJson($next));
        }

        Response::ok(['reminders' => $out, 'server_time' => now_utc()]);
    }

    if ($segments === ['reminders'] && $method === 'POST') {
        $idempotent($userId, 'reminders.create', static function () use ($userId, $user, $tz, $reminderJson, $db): void {
            if (!PlanService::withinReminderQuota($userId)) {
                Response::error(Lang::get('reminder.quota_reached'), 403, 'QUOTA_EXCEEDED');
            }

            $title = trim((string) Request::post('title', ''));
            $dueAt = (string) Request::post('due_at', '');

            if ($title === '' || strtotime($dueAt) === false) {
                Response::error('title and due_at are required', 422, 'VALIDATION_FAILED');
            }

            $reminder = ReminderService::create($userId, [
                'title'         => $title,
                'description'   => (string) Request::post('description', ''),
                'type'          => (string) Request::post('type', 'task'),
                'priority'      => (string) Request::post('priority', 'normal'),
                'start_at'      => gmdate('Y-m-d H:i:s', (int) strtotime($dueAt)),
                'all_day'       => Request::bool('all_day'),
                'recurrence'    => Request::arr('recurrence', ['freq' => 'none']),
                'call_reminder' => Request::bool('call_reminder', true),
                'advance_alerts'=> Request::arr('advance_alerts'),
                'snooze_default_min' => (int) Request::post('snooze_default_min', 5),
                'amount'        => Request::post('amount') === null ? null : (float) Request::post('amount'),
                'currency'      => (string) Request::post('currency', 'INR'),
                'person_name'   => Request::post('person_name'),
                'person_phone'  => Request::post('person_phone'),
                'location'      => Request::post('location'),
                'category_id'   => Request::post('category_id') === null ? null : (int) Request::post('category_id'),
                'tags'          => Request::arr('tags'),
                'source'        => 'app',
                'timezone'      => $tz,
            ]);

            if ($reminder === null) {
                Response::error('Could not create the reminder', 400, 'CREATE_FAILED');
            }

            $next = $db->one('SELECT * FROM reminder_occurrences WHERE reminder_id = ? ORDER BY due_at ASC LIMIT 1', [(int) $reminder['id']]);

            Response::json($reminderJson($reminder, $next === null ? null : ['id' => (int) $next['id'], 'due_at' => (string) $next['due_at'], 'status' => (string) $next['status']]), Lang::get('reminder.created'), 201, 'CREATED');
        });
    }

    if (count($segments) === 2 && $segments[0] === 'reminders' && ctype_digit($segments[1])) {
        $reminderId = (int) $segments[1];
        $reminder = $db->one('SELECT * FROM reminders WHERE id = ? AND user_id = ?', [$reminderId, $userId]);

        if ($reminder === null) {
            Response::error(Lang::get('api.not_found'), 404, 'NOT_FOUND');
        }

        // Soft delete, so it can still be restored from the website's Trash.
        if ($method === 'DELETE') {
            $db->update('reminders', ['deleted_at' => now_utc()], 'id = :id', ['id' => $reminderId]);

            // Cancel anything still pending, or the dispatcher would keep
            // ringing for a reminder the user has deleted.
            $db->query(
                'UPDATE reminder_occurrences SET status = "cancelled", updated_at = ?
                  WHERE reminder_id = ? AND status IN ("pending", "snoozed")',
                [now_utc(), $reminderId]
            );

            Response::ok(['id' => $reminderId], Lang::get('reminder.deleted'));
        }

        if ($method === 'GET') {
            $occurrences = $db->all('SELECT * FROM reminder_occurrences WHERE reminder_id = ? ORDER BY due_at ASC LIMIT 100', [$reminderId]);
            $timeline = $db->all(
                'SELECT ur.* FROM user_responses ur JOIN reminder_occurrences o ON o.id = ur.occurrence_id WHERE o.reminder_id = ? ORDER BY ur.created_at DESC LIMIT 50',
                [$reminderId]
            );

            Response::ok([
                'reminder'    => $reminderJson($reminder),
                'occurrences' => array_map($occurrenceJson, $occurrences),
                'timeline'    => $timeline,
            ]);
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $data = [];

            foreach (['title', 'description', 'type', 'priority', 'person_name', 'person_phone', 'location', 'currency'] as $field) {
                $value = Request::post($field);

                if ($value !== null) {
                    $data[$field] = $value;
                }
            }

            if (Request::post('due_at') !== null && strtotime((string) Request::post('due_at')) !== false) {
                $data['start_at'] = gmdate('Y-m-d H:i:s', (int) strtotime((string) Request::post('due_at')));
            }

            if (Request::post('amount') !== null) {
                $data['amount'] = (float) Request::post('amount');
            }

            if (Request::post('recurrence') !== null) {
                $data['recurrence'] = Request::arr('recurrence');
            }

            if (Request::post('advance_alerts') !== null) {
                $data['advance_alerts'] = Request::arr('advance_alerts');
            }

            if (Request::post('call_reminder') !== null) {
                $data['call_reminder'] = Request::bool('call_reminder') ? 1 : 0;
            }

            ReminderService::update($reminderId, $userId, $data);

            $fresh = $db->one('SELECT * FROM reminders WHERE id = ?', [$reminderId]);

            Response::ok($reminderJson($fresh ?? $reminder), Lang::get('reminder.updated'));
        }

        if ($method === 'DELETE') {
            ReminderService::softDelete($reminderId, $userId);
            Response::ok(['deleted' => true], Lang::get('reminder.deleted'));
        }
    }

    if ($segments === ['reminders', 'parse'] && $method === 'POST') {
        $text = trim((string) Request::post('text', ''));

        if ($text === '') {
            Response::error('text is required', 422, 'VALIDATION_FAILED');
        }

        $create = Request::bool('create', false);
        $parsed = GeminiService::parse($text, $user);

        if (!$create) {
            Response::ok(['parsed' => $parsed['data'], 'source' => $parsed['source']]);
        }

        $outcome = InboundProcessor::applyEnvelope($parsed['data'], $user, 'app', null, $parsed['source'], $text);

        Response::ok([
            'parsed' => $parsed['data'],
            'source' => $parsed['source'],
            'result' => $outcome['result'],
            'reply'  => $outcome['reply'],
        ]);
    }

    /* ----------------------------------------------------- occurrences --- */

    if (count($segments) === 3 && $segments[0] === 'occurrences' && ctype_digit($segments[1]) && $method === 'POST') {
        $occurrenceId = (int) $segments[1];
        $action = $segments[2];

        $occurrence = $db->one('SELECT * FROM reminder_occurrences WHERE id = ? AND user_id = ?', [$occurrenceId, $userId]);

        if ($occurrence === null) {
            Response::error(Lang::get('api.not_found'), 404, 'NOT_FOUND');
        }

        $deviceId = null;
        $deviceUid = (string) Request::post('device_uid', '');

        if ($deviceUid !== '') {
            $row = $db->one('SELECT id FROM devices WHERE user_id = ? AND device_uid = ?', [$userId, $deviceUid]);
            $deviceId = $row === null ? null : (int) $row['id'];
        }

        switch ($action) {
            case 'done':
                ReminderService::complete($occurrenceId, $userId, 'app', Request::post('note'), $deviceId);
                Response::ok(['status' => 'done'], Lang::get('reminder.completed'));

                // no break — Response::ok exits
            case 'snooze':
                $minutes = (int) Request::post('minutes', 0);
                $result = ReminderService::snooze($occurrenceId, $userId, $minutes > 0 ? $minutes : null, 'app');

                if (!$result['ok']) {
                    Response::error((string) $result['message'], 409, (string) ($result['code'] ?? 'SNOOZE_FAILED'));
                }

                Response::ok($result, Lang::get('reminder.snoozed', ['minutes' => $result['minutes']]));

            case 'reschedule':
                $newDue = (string) Request::post('due_at', '');

                if (strtotime($newDue) === false) {
                    Response::error('due_at is required', 422, 'VALIDATION_FAILED');
                }

                ReminderService::reschedule($occurrenceId, $userId, gmdate('Y-m-d H:i:s', (int) strtotime($newDue)), 'app');
                Response::ok(['status' => 'rescheduled'], Lang::get('reminder.rescheduled'));

            case 'cancel':
                ReminderService::cancelOccurrence($occurrenceId, $userId, 'app');
                Response::ok(['status' => 'cancelled'], Lang::get('reminder.cancelled'));

            case 'dismiss':
                ReminderService::recordResponse($occurrenceId, $userId, 'dismiss', 'app', [], $deviceId);
                Response::ok(['status' => 'dismissed']);

            default:
                Response::error('Unknown action', 404, 'NOT_FOUND');
        }
    }

    if ($segments === ['occurrences'] && $method === 'GET') {
        $from = (string) Request::get('from', gmdate('Y-m-d H:i:s', time() - 86400));
        $to = (string) Request::get('to', gmdate('Y-m-d H:i:s', time() + (30 * 86400)));

        $rows = $db->all(
            'SELECT o.*, r.title, r.short_code, r.type, r.priority, r.amount, r.currency, r.call_reminder, r.snooze_default_min
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              ORDER BY o.due_at ASC LIMIT 1000',
            [$userId, gmdate('Y-m-d H:i:s', (int) strtotime($from)), gmdate('Y-m-d H:i:s', (int) strtotime($to))]
        );

        Response::ok(['occurrences' => array_map($occurrenceJson, $rows), 'server_time' => now_utc()]);
    }

    /* -------------------------------------------------------- dashboard --- */

    if ($segments === ['dashboard', 'stats'] && $method === 'GET') {
        $stats = ReminderService::stats($userId, $tz);
        $stats['payments'] = PaymentService::totals($userId, $tz);
        $stats['today'] = array_map($occurrenceJson, ReminderService::todayOccurrences($userId, $tz));

        Response::ok($stats);
    }

    if (count($segments) === 2 && $segments[0] === 'summary' && $method === 'GET') {
        $date = $segments[1] === 'today'
            ? (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d')
            : $segments[1];

        $row = $db->one('SELECT * FROM summaries WHERE user_id = ? AND summary_date = ? AND kind = "night"', [$userId, $date]);

        Response::ok([
            'date' => $date,
            'body' => $row['body'] ?? SummaryService::buildNightSummary($userId, $user, $date),
            'stats' => $row,
        ]);
    }

    if ($segments === ['reports'] && $method === 'GET') {
        $from = (string) Request::get('from', date('Y-m-d', strtotime('-30 days')));
        $to = (string) Request::get('to', date('Y-m-d'));

        Response::ok(ReportService::build($userId, $from, $to, $tz));
    }

    /* --------------------------------------------------------- payments --- */

    if ($segments === ['payments'] && $method === 'GET') {
        Response::ok([
            'payments' => $db->all(
                'SELECT p.*, r.short_code FROM payments p LEFT JOIN reminders r ON r.id = p.reminder_id
                  WHERE p.user_id = ? AND p.deleted_at IS NULL ORDER BY p.due_date DESC LIMIT 300',
                [$userId]
            ),
            'totals' => PaymentService::totals($userId, $tz),
        ]);
    }

    if (count($segments) === 3 && $segments[0] === 'payments' && ctype_digit($segments[1]) && $segments[2] === 'pay' && $method === 'POST') {
        $amount = (float) Request::post('amount', 0);

        if ($amount <= 0) {
            Response::error('amount must be greater than zero', 422, 'VALIDATION_FAILED');
        }

        $ok = PaymentService::recordPayment(
            (int) $segments[1],
            $userId,
            $amount,
            (string) Request::post('method', 'cash'),
            Request::post('note')
        );

        if (!$ok) {
            Response::error(Lang::get('api.not_found'), 404, 'NOT_FOUND');
        }

        Response::ok(['recorded' => true], Lang::get('payments.recorded'));
    }

    /* ------------------------------------------------------------ notes --- */

    if ($segments === ['notes'] && $method === 'GET') {
        Response::ok($db->all('SELECT * FROM notes WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 200', [$userId]));
    }

    if ($segments === ['notes'] && $method === 'POST') {
        $body = trim((string) Request::post('body', ''));

        if ($body === '') {
            Response::error('body is required', 422, 'VALIDATION_FAILED');
        }

        $id = $db->insert('notes', [
            'user_id'    => $userId,
            'title'      => mb_substr((string) Request::post('title', mb_substr($body, 0, 80)), 0, 255),
            'body'       => mb_substr($body, 0, 5000),
            'source'     => 'app',
            'created_at' => now_utc(),
        ]);

        Response::json(['id' => $id], Lang::get('common.saved'), 201, 'CREATED');
    }

    // Soft delete, scoped by user_id: the id comes from the client, so without
    // that clause anyone could delete someone else's note by guessing a number.
    if (count($segments) === 2 && $segments[0] === 'notes' && ctype_digit($segments[1])
        && in_array($method, ['DELETE', 'POST'], true) && Request::post('_method') !== 'update') {
        $noteId = (int) $segments[1];

        $affected = $db->query(
            'UPDATE notes SET deleted_at = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [now_utc(), $noteId, $userId]
        )->rowCount();

        if ($affected === 0) {
            Response::error('Note not found', 404, 'NOT_FOUND');
        }

        Response::ok(['id' => $noteId], Lang::get('common.deleted'));
    }

    /* --------------------------------------------------------- contacts --- */

    if ($segments === ['contacts'] && $method === 'GET') {
        Response::ok($db->all('SELECT * FROM contacts WHERE user_id = ? AND deleted_at IS NULL ORDER BY name ASC', [$userId]));
    }

    if ($segments === ['contacts'] && $method === 'POST') {
        $name = trim((string) Request::post('name', ''));

        if ($name === '') {
            Response::error('name is required', 422, 'VALIDATION_FAILED');
        }

        $id = $db->insert('contacts', [
            'user_id'    => $userId,
            'name'       => mb_substr($name, 0, 120),
            'phone'      => normalize_phone((string) Request::post('phone', '')) ?: null,
            'email'      => Request::post('email') ?: null,
            'role'       => Request::post('role') ?: null,
            'created_at' => now_utc(),
        ]);

        Response::json(['id' => $id], Lang::get('common.saved'), 201, 'CREATED');
    }

    if ($segments === ['categories'] && $method === 'GET') {
        Response::ok($db->all('SELECT * FROM categories WHERE user_id IS NULL OR user_id = ? ORDER BY sort_order ASC', [$userId]));
    }

    /* ------------------------------------------------------------- sync --- */

    if ($segments === ['sync', 'pull'] && $method === 'GET') {
        $since = (string) Request::get('since', '');
        $sinceUtc = $since !== '' && strtotime($since) !== false
            ? gmdate('Y-m-d H:i:s', (int) strtotime($since))
            : gmdate('Y-m-d H:i:s', time() - (30 * 86400));

        $reminders = $db->all('SELECT * FROM reminders WHERE user_id = ? AND updated_at > ? ORDER BY updated_at ASC LIMIT 500', [$userId, $sinceUtc]);

        $occurrences = $db->all(
            'SELECT o.*, r.title, r.short_code, r.type, r.priority, r.amount, r.currency
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND o.updated_at > ? AND o.due_at > ?
              ORDER BY o.due_at ASC LIMIT 1000',
            [$userId, $sinceUtc, gmdate('Y-m-d H:i:s', time() - 172800)]
        );

        Response::ok([
            'reminders'   => array_map(static fn ($r) => $reminderJson($r), $reminders),
            'occurrences' => array_map($occurrenceJson, $occurrences),
            'settings'    => ReminderService::userSettings($userId),
            'server_time' => now_utc(),
        ]);
    }

    if ($segments === ['sync', 'push'] && $method === 'POST') {
        $actions = Request::arr('actions');
        $results = [];

        foreach (array_slice($actions, 0, 200) as $action) {
            if (!is_array($action)) {
                continue;
            }

            $clientId = (string) ($action['client_id'] ?? '');
            $type = (string) ($action['type'] ?? '');
            $occurrenceId = (int) ($action['occurrence_id'] ?? 0);

            // Offline replays are deduplicated on client_id.
            if ($clientId !== '') {
                $seen = $db->one('SELECT id FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$userId, 'sync:' . $clientId]);

                if ($seen !== null) {
                    $results[] = ['client_id' => $clientId, 'status' => 'duplicate'];
                    continue;
                }
            }

            $ok = false;

            switch ($type) {
                case 'done':
                    $ok = ReminderService::complete($occurrenceId, $userId, 'app', $action['note'] ?? null);
                    break;

                case 'snooze':
                    $result = ReminderService::snooze($occurrenceId, $userId, (int) ($action['minutes'] ?? 0) ?: null, 'app');
                    $ok = (bool) $result['ok'];
                    break;

                case 'reschedule':
                    $due = (string) ($action['due_at'] ?? '');
                    $ok = strtotime($due) !== false
                        && ReminderService::reschedule($occurrenceId, $userId, gmdate('Y-m-d H:i:s', (int) strtotime($due)), 'app');
                    break;

                case 'cancel':
                    $ok = ReminderService::cancelOccurrence($occurrenceId, $userId, 'app');
                    break;

                case 'create':
                    $created = ReminderService::create($userId, [
                        'title'    => (string) ($action['title'] ?? ''),
                        'start_at' => gmdate('Y-m-d H:i:s', (int) (strtotime((string) ($action['due_at'] ?? 'now')) ?: time())),
                        'type'     => (string) ($action['reminder_type'] ?? 'task'),
                        'source'   => 'app',
                        'timezone' => $tz,
                    ]);
                    $ok = $created !== null;
                    break;
            }

            if ($clientId !== '') {
                try {
                    $db->insert('idempotency_keys', [
                        'user_id'    => $userId,
                        'idem_key'   => 'sync:' . mb_substr($clientId, 0, 110),
                        'endpoint'   => 'sync.push',
                        'response'   => json_encode(['ok' => $ok]),
                        'created_at' => now_utc(),
                    ]);
                } catch (\Throwable) {
                    // Concurrent replay.
                }
            }

            $results[] = ['client_id' => $clientId, 'status' => $ok ? 'ok' : 'failed'];
        }

        Response::ok(['results' => $results, 'server_time' => now_utc()]);
    }

    /* ------------------------------------------------------------- misc --- */

    if ($segments === ['tts'] && $method === 'POST') {
        $text = trim((string) Request::post('text', ''));

        if ($text === '') {
            Response::error('text is required', 422, 'VALIDATION_FAILED');
        }

        $url = \App\Services\TtsService::synthesise($text, (string) $user['language']);

        if ($url === null) {
            Response::error('Server TTS is not configured', 404, 'TTS_UNAVAILABLE');
        }

        Response::ok(['url' => $url]);
    }

    if ($segments === ['attachments'] && $method === 'POST') {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file)) {
            Response::error('file is required', 422, 'VALIDATION_FAILED');
        }

        $result = UploadService::store($file, $userId, 'attachments', [
            'reminder_id' => Request::post('reminder_id') === null ? null : (int) Request::post('reminder_id'),
        ]);

        if (!$result['ok']) {
            Response::error((string) $result['error'], 422, 'UPLOAD_FAILED');
        }

        Response::json(['attachment_id' => $result['attachment_id'], 'url' => $result['url']], 'Uploaded', 201, 'CREATED');
    }

    if ($segments === ['test', 'call'] && $method === 'POST') {
        $result = FcmService::sendToUser($userId, [
            'type'          => 'call',
            'occurrence_id' => '0',
            'reminder_id'   => '0',
            'short_code'    => 'TEST',
            'title'         => 'Krishna Reminder — test call',
            'language'      => (string) $user['language'],
            'speech'        => \App\Services\TtsService::speech(
                ['title' => 'test reminder', 'type' => 'task', 'amount' => null, 'person_name' => '', 'start_at' => now_utc()],
                $user
            ),
            'ringtone'      => 'flute',
            'ring_seconds'  => '20',
            'tts_enabled'   => '1',
            'attempt'       => '1',
            'sent_at'       => now_utc(),
        ]);

        Response::ok($result, 'Test call sent');
    }

    Response::error(Lang::get('api.not_found') . ' (' . $path . ')', 404, 'NOT_FOUND');
} catch (Throwable $e) {
    Logger::exception($e, 'api');

    Response::error(
        $app->config('app.debug') ? $e->getMessage() : 'Internal server error',
        500,
        'SERVER_ERROR'
    );
}
