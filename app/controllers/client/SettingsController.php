<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Crypto;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\FcmService;
use App\Services\GoogleService;
use App\Services\InvoiceService;
use App\Services\OtpService;
use App\Services\PlanService;
use App\Services\ReminderService;
use App\Services\TtsService;

/**
 * Settings, devices, integrations, billing, referrals, help and account data
 * rights (export / delete) — everything under the "account" part of /client.
 */
class SettingsController extends Controller
{
    /* ------------------------------------------------------------ Settings */

    public function index(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $this->view('client/settings', [
            'title'      => __('nav.settings'),
            'pageTitle'  => __('nav.settings'),
            'settings'   => ReminderService::userSettings($userId),
            'numbers'    => App::i()->db()->all('SELECT * FROM whatsapp_numbers WHERE user_id = ? ORDER BY is_primary DESC, id ASC', [$userId]),
            'sessions'   => App::i()->db()->all(
                'SELECT id, type, ip, user_agent, last_used_at, created_at FROM sessions WHERE user_id = ? AND revoked = 0 ORDER BY last_used_at DESC LIMIT 20',
                [$userId]
            ),
            'timezones'  => timezone_identifiers_list(),
        ], 'layouts/client');
    }

    public function save(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        ReminderService::ensureSettings($userId);

        $fields = [
            'morning_brief_time'    => (string) Request::post('morning_brief_time', '07:30'),
            'night_summary_time'    => (string) Request::post('night_summary_time', '21:30'),
            'morning_brief_enabled' => Request::bool('morning_brief_enabled') ? 1 : 0,
            'night_summary_enabled' => Request::bool('night_summary_enabled') ? 1 : 0,
            'dnd_enabled'           => Request::bool('dnd_enabled') ? 1 : 0,
            'dnd_start'             => (string) Request::post('dnd_start', '23:00'),
            'dnd_end'               => (string) Request::post('dnd_end', '06:30'),
            'default_time'          => (string) Request::post('default_time', '09:00'),
            'default_snooze_min'    => max(1, (int) Request::post('default_snooze_min', 5)),
            'max_snoozes'           => max(1, (int) Request::post('max_snoozes', 5)),
            'call_attempts'         => max(1, min(5, (int) Request::post('call_attempts', 3))),
            'call_gap_minutes'      => max(1, min(30, (int) Request::post('call_gap_minutes', 2))),
            'ring_seconds'          => max(10, min(120, (int) Request::post('ring_seconds', 45))),
            'whatsapp_fallback'     => Request::bool('whatsapp_fallback') ? 1 : 0,
            'ringtone'              => (string) Request::post('ringtone', 'flute'),
            'tts_enabled'           => Request::bool('tts_enabled') ? 1 : 0,
            'tts_speed'             => max(0.5, min(1.5, (float) Request::post('tts_speed', 1.0))),
            'wa_notify_created'     => Request::bool('wa_notify_created') ? 1 : 0,
            'wa_notify_due'         => Request::bool('wa_notify_due') ? 1 : 0,
            'wa_notify_done'        => Request::bool('wa_notify_done') ? 1 : 0,
            'wa_notify_missed'      => Request::bool('wa_notify_missed') ? 1 : 0,
            'theme'                 => in_array((string) Request::post('theme', 'auto'), ['light', 'dark', 'auto'], true) ? (string) Request::post('theme') : 'auto',
            'holiday_mode_until'    => Request::post('holiday_mode_until') ?: null,
        ];

        App::i()->db()->update('user_settings', $fields, 'user_id = :uid', ['uid' => $userId]);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/settings'));
    }

    public function saveProfile(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $fields = [];
        $name = trim((string) Request::post('name', ''));

        if ($name !== '') {
            $fields['name'] = mb_substr($name, 0, 120);
        }

        $email = trim((string) Request::post('email', ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $taken = App::i()->db()->one('SELECT id FROM users WHERE email = ? AND id <> ? AND deleted_at IS NULL', [$email, $userId]);

            if ($taken !== null) {
                Session::flash('error', __('auth.email_taken'));
                Response::back(url('/client/settings'));
            }

            $fields['email'] = $email;
        }

        $language = (string) Request::post('language', '');

        if (in_array($language, Lang::SUPPORTED, true)) {
            $fields['language'] = $language;
            Session::set('locale', $language);
        }

        $timezone = (string) Request::post('timezone', '');

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            $fields['timezone'] = $timezone;
        }

        $city = trim((string) Request::post('city', ''));

        if ($city !== '') {
            $fields['city'] = mb_substr($city, 0, 120);
        }

        if ($fields !== []) {
            App::i()->db()->update('users', $fields, 'id = :id', ['id' => $userId]);
        }

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/settings'));
    }

    public function changePassword(): void
    {
        $user = $this->requireUser();

        $current = (string) Request::post('current_password', '');
        $new = (string) Request::post('password', '');
        $confirm = (string) Request::post('password_confirm', '');

        if (!Auth::verifyPassword($current, $user['password_hash'] ?? null)) {
            Session::flash('error', __('auth.invalid_credentials'));
            Response::back(url('/client/settings'));
        }

        if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            Session::flash('error', __('validation.password_weak'));
            Response::back(url('/client/settings'));
        }

        if ($new !== $confirm) {
            Session::flash('error', __('validation.confirmed', ['field' => __('common.password')]));
            Response::back(url('/client/settings'));
        }

        App::i()->db()->update('users', ['password_hash' => Auth::hashPassword($new)], 'id = :id', ['id' => (int) $user['id']]);
        AuditService::log('password.changed', 'user', (int) $user['id'], [], 'user', (int) $user['id']);

        Session::flash('success', __('auth.password_changed'));
        Response::redirect(url('/client/settings'));
    }

    /* ------------------------------------------------------ WhatsApp numbers */

    public function addNumber(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $db = App::i()->db();

        $count = (int) $db->value('SELECT COUNT(*) FROM whatsapp_numbers WHERE user_id = ?', [$userId], 0);

        if ($count >= 4) {
            Session::flash('error', 'You can link at most 4 numbers (1 primary + 3 additional).');
            Response::back(url('/client/settings'));
        }

        $phone = normalize_phone((string) Request::post('number', ''));

        if ($phone === '') {
            Session::flash('error', __('auth.invalid_number'));
            Response::back(url('/client/settings'));
        }

        if ($db->one('SELECT id FROM whatsapp_numbers WHERE number = ?', [$phone]) !== null) {
            Session::flash('error', __('auth.number_taken'));
            Response::back(url('/client/settings'));
        }

        $db->insert('whatsapp_numbers', [
            'user_id'    => $userId,
            'number'     => $phone,
            'label'      => mb_substr((string) Request::post('label', 'Additional'), 0, 60),
            'is_primary' => 0,
            'is_verified'=> 0,
            'is_active'  => 1,
            'created_at' => now_utc(),
        ]);

        OtpService::send($phone, 'verify_number', $userId, (string) $user['language']);
        Session::set('pending_verification', ['phone' => $phone, 'user_id' => $userId, 'purpose' => 'verify_number']);

        Response::redirect(url('/verify'));
    }

    public function removeNumber(string $id): void
    {
        $user = $this->requireUser();

        $number = App::i()->db()->one(
            'SELECT * FROM whatsapp_numbers WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user['id']]
        );

        if ($number === null || (int) $number['is_primary'] === 1) {
            Session::flash('error', 'The primary number cannot be removed.');
            Response::back(url('/client/settings'));
        }

        App::i()->db()->delete('whatsapp_numbers', 'id = ? AND user_id = ?', [(int) $id, (int) $user['id']]);

        Session::flash('success', __('common.deleted'));
        Response::redirect(url('/client/settings'));
    }

    public function revokeSession(string $id): void
    {
        $user = $this->requireUser();
        Auth::revokeSession((int) $id, (int) $user['id']);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/settings'));
    }

    /* ------------------------------------------------------------- Devices */

    public function devices(): void
    {
        $user = $this->requireUser();

        $this->view('client/devices', [
            'title'     => __('nav.devices'),
            'pageTitle' => __('nav.devices'),
            'devices'   => App::i()->db()->all(
                'SELECT * FROM devices WHERE user_id = ? ORDER BY last_seen_at DESC',
                [(int) $user['id']]
            ),
            'fcmReady'  => FcmService::isConfigured(),
            'apkUrl'    => (string) App::i()->settings()->get('apk_release_url', ''),
        ], 'layouts/client');
    }

    public function testDevice(string $id): void
    {
        $user = $this->requireUser();

        $device = App::i()->db()->one(
            'SELECT * FROM devices WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user['id']]
        );

        if ($device === null || empty($device['fcm_token'])) {
            Session::flash('error', 'This device has no push token yet. Open the app once and try again.');
            Response::back(url('/client/devices'));
        }

        $speech = TtsService::speech(
            ['title' => 'test reminder', 'type' => 'task', 'amount' => null, 'person_name' => '', 'start_at' => now_utc()],
            $user
        );

        $result = FcmService::sendToToken((string) $device['fcm_token'], [
            'type'          => 'call',
            'occurrence_id' => '0',
            'reminder_id'   => '0',
            'short_code'    => 'TEST',
            'title'         => 'Krishna Reminder — test call',
            'language'      => (string) $user['language'],
            'speech'        => $speech,
            'ringtone'      => 'flute',
            'ring_seconds'  => '20',
            'tts_enabled'   => '1',
            'attempt'       => '1',
            'sent_at'       => now_utc(),
        ]);

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? __('devices.test_sent') : $result['response']);
        Response::redirect(url('/client/devices'));
    }

    public function removeDevice(string $id): void
    {
        $user = $this->requireUser();

        App::i()->db()->update('devices', ['is_active' => 0, 'fcm_token' => null], 'id = :id AND user_id = :uid', [
            'id' => (int) $id, 'uid' => (int) $user['id'],
        ]);

        Session::flash('success', __('devices.removed'));
        Response::redirect(url('/client/devices'));
    }

    /* -------------------------------------------------------- Integrations */

    public function integrations(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $this->view('client/integrations', [
            'title'        => __('nav.integrations'),
            'pageTitle'    => __('nav.integrations'),
            'google'       => GoogleService::account($userId),
            'googleReady'  => GoogleService::isEnabled(),
            'canGoogle'    => PlanService::can($userId, 'google_sync'),
            'canApi'       => PlanService::can($userId, 'api_access'),
            'apiKeys'      => App::i()->db()->all('SELECT id, name, key_prefix, scopes, last_used_at, created_at FROM api_keys WHERE user_id = ? AND is_active = 1', [$userId]),
            'webhooks'     => App::i()->db()->all('SELECT * FROM user_webhooks WHERE user_id = ?', [$userId]),
            'newKey'       => Session::get('_new_api_key'),
        ], 'layouts/client');

        Session::forget('_new_api_key');
    }

    public function generateApiKey(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        if (!PlanService::can($userId, 'api_access')) {
            Session::flash('error', __('reminder.quota_reached'));
            Response::redirect(url('/client/billing'));
        }

        $key = 'kr_' . Crypto::randomToken(24);

        App::i()->db()->insert('api_keys', [
            'user_id'    => $userId,
            'name'       => mb_substr((string) Request::post('name', 'Default'), 0, 120),
            'key_prefix' => substr($key, 0, 10),
            'key_hash'   => hash('sha256', $key),
            'created_at' => now_utc(),
        ]);

        // Shown exactly once.
        Session::set('_new_api_key', $key);
        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/integrations'));
    }

    public function saveWebhook(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $url = trim((string) Request::post('url', ''));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            Session::flash('error', __('validation.url', ['field' => 'Webhook URL']));
            Response::back(url('/client/integrations'));
        }

        App::i()->db()->insert('user_webhooks', [
            'user_id'    => $userId,
            'url'        => mb_substr($url, 0, 500),
            'secret'     => Crypto::randomToken(16),
            'events'     => (string) Request::post('events', 'reminder.created,reminder.due,reminder.done'),
            'is_active'  => 1,
            'created_at' => now_utc(),
        ]);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/integrations'));
    }

    public function disconnectGoogle(): void
    {
        $user = $this->requireUser();

        GoogleService::disconnect((int) $user['id'], Request::bool('delete_events'));

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/integrations'));
    }

    /* -------------------------------------------------------------- Billing */

    public function billing(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $this->view('client/billing', [
            'title'     => __('nav.billing'),
            'pageTitle' => __('nav.billing'),
            'usage'     => PlanService::usage($userId),
            'plans'     => App::i()->db()->all('SELECT * FROM plans WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order'),
            'invoices'  => App::i()->db()->all('SELECT * FROM invoices WHERE user_id = ? ORDER BY issued_at DESC LIMIT 30', [$userId]),
            'subscriptions' => App::i()->db()->all(
                'SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 10',
                [$userId]
            ),
        ], 'layouts/client');
    }

    /**
     * Manual subscription request: the admin approves it and the plan activates.
     */
    public function subscribe(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $db = App::i()->db();

        $plan = $db->one('SELECT * FROM plans WHERE id = ? AND is_active = 1', [(int) Request::post('plan_id', 0)]);

        if ($plan === null) {
            Session::flash('error', __('common.not_found'));
            Response::back(url('/client/billing'));
        }

        $amount = (float) $plan['price'];
        $couponRow = null;
        $coupon = trim((string) Request::post('coupon', ''));

        if ($coupon !== '') {
            $applied = InvoiceService::applyCoupon($coupon, $amount, (int) $plan['id']);

            if ($applied['ok']) {
                $amount = $applied['amount'];
                $couponRow = $applied['coupon'];
                Session::flash('success', $applied['message']);
            } else {
                Session::flash('error', $applied['message']);
                Response::back(url('/client/billing'));
            }
        }

        $subscriptionId = $db->insert('subscriptions', [
            'user_id'        => $userId,
            'plan_id'        => (int) $plan['id'],
            'status'         => 'pending',
            'starts_at'      => now_utc(),
            'ends_at'        => date('Y-m-d H:i:s', time() + (((int) $plan['duration_days']) * 86400)),
            'amount'         => $amount,
            'currency'       => (string) $plan['currency'],
            'coupon_id'      => $couponRow === null ? null : (int) $couponRow['id'],
            'payment_method' => (string) Request::post('payment_method', 'manual'),
            'payment_ref'    => Request::post('payment_ref') ?: null,
            'created_at'     => now_utc(),
        ]);

        InvoiceService::createForSubscription($subscriptionId);

        $adminNumber = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($adminNumber !== '') {
            \App\Services\WhatsAppService::queue(
                $adminNumber,
                "💳 *New plan request*\n👤 " . $user['name'] . ' (' . display_phone((string) $user['phone']) . ")\n"
                . '📦 ' . $plan['name'] . "\n💵 " . money($amount, (string) $plan['currency'])
                . "\n\nApprove: " . App::i()->url('/admin/subscriptions'),
                null,
                null,
                4
            );
        }

        Session::flash('success', __('billing.request_sent'));
        Response::redirect(url('/client/billing'));
    }

    public function invoice(string $id): void
    {
        $user = $this->requireUser();

        $invoice = App::i()->db()->one(
            'SELECT i.*, p.name AS plan_name FROM invoices i
               LEFT JOIN subscriptions s ON s.id = i.subscription_id
               LEFT JOIN plans p ON p.id = s.plan_id
              WHERE i.id = ? AND i.user_id = ?',
            [(int) $id, (int) $user['id']]
        );

        if ($invoice === null) {
            Response::notFound();
        }

        $this->view('client/invoice', [
            'title'   => 'Invoice ' . $invoice['invoice_no'],
            'invoice' => $invoice,
            'user'    => $user,
        ], null);
    }

    /* ------------------------------------------------------------ Referrals */

    public function referral(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $db = App::i()->db();

        $this->view('client/referral', [
            'title'     => __('nav.referral'),
            'pageTitle' => __('nav.referral'),
            'link'      => App::i()->url('/register?ref=' . (string) $user['referral_code']),
            'referrals' => $db->all(
                'SELECT r.*, u.name, u.created_at AS joined_at FROM referrals r JOIN users u ON u.id = r.referred_id WHERE r.referrer_id = ? ORDER BY r.created_at DESC',
                [$userId]
            ),
            'ledger'    => $db->all('SELECT * FROM commissions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50', [$userId]),
            'balance'   => InvoiceService::commissionBalance($userId),
            'percent'   => (float) App::i()->settings()->get('referral_commission_percent', 20),
        ], 'layouts/client');
    }

    public function requestPayout(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $balance = InvoiceService::commissionBalance($userId);

        if ($balance['available'] < 500) {
            Session::flash('error', 'Minimum payout is ' . money(500) . '.');
            Response::back(url('/client/referral'));
        }

        App::i()->db()->insert('commissions', [
            'user_id'    => $userId,
            'amount'     => $balance['available'],
            'type'       => 'payout',
            'status'     => 'pending',
            'note'       => 'Payout requested by user',
            'created_at' => now_utc(),
        ]);

        Session::flash('success', __('referral.payout_requested'));
        Response::redirect(url('/client/referral'));
    }

    /* ----------------------------------------------------------------- Help */

    public function help(): void
    {
        $this->requireUser();

        $this->view('client/help', [
            'title'     => __('nav.help'),
            'pageTitle' => __('nav.help'),
            'waNumber'  => (string) App::i()->settings()->get('support_whatsapp', '919978123146'),
        ], 'layouts/client');
    }

    /* ----------------------------------------------------- Data rights (DPDP) */

    public function exportData(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $db = App::i()->db();

        $export = [
            'exported_at' => now_utc(),
            'user'        => array_diff_key($user, ['password_hash' => null]),
            'settings'    => ReminderService::userSettings($userId),
            'numbers'     => $db->all('SELECT number, label, is_primary, is_verified, created_at FROM whatsapp_numbers WHERE user_id = ?', [$userId]),
            'reminders'   => $db->all('SELECT * FROM reminders WHERE user_id = ?', [$userId]),
            'occurrences' => $db->all('SELECT * FROM reminder_occurrences WHERE user_id = ? LIMIT 20000', [$userId]),
            'payments'    => $db->all('SELECT * FROM payments WHERE user_id = ?', [$userId]),
            'transactions'=> $db->all('SELECT * FROM payment_transactions WHERE user_id = ?', [$userId]),
            'notes'       => $db->all('SELECT * FROM notes WHERE user_id = ?', [$userId]),
            'contacts'    => $db->all('SELECT * FROM contacts WHERE user_id = ?', [$userId]),
            'whatsapp_in' => $db->all('SELECT from_number, body, received_at, handled_by FROM wa_inbound_raw WHERE user_id = ? LIMIT 5000', [$userId]),
            'summaries'   => $db->all('SELECT * FROM summaries WHERE user_id = ?', [$userId]),
        ];

        AuditService::log('data.exported', 'user', $userId, [], 'user', $userId);

        Response::stream(
            (string) json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'krishna-reminder-data-' . $userId . '.json',
            'application/json'
        );
    }

    public function deleteAccount(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $confirm = strtoupper(trim((string) Request::post('confirm', '')));

        if ($confirm !== 'DELETE') {
            Session::flash('error', 'Type DELETE to confirm.');
            Response::back(url('/client/settings'));
        }

        if (!Auth::verifyPassword((string) Request::post('password', ''), $user['password_hash'] ?? null)) {
            Session::flash('error', __('auth.invalid_credentials'));
            Response::back(url('/client/settings'));
        }

        $db = App::i()->db();

        // Soft-delete the account, free the unique number/email, and revoke access.
        $db->update('users', [
            'deleted_at' => now_utc(),
            'is_active'  => 0,
            'phone'      => 'deleted_' . $userId . '_' . substr((string) $user['phone'], -4),
            'email'      => null,
        ], 'id = :id', ['id' => $userId]);

        $db->delete('whatsapp_numbers', 'user_id = ?', [$userId]);
        $db->query('UPDATE sessions SET revoked = 1 WHERE user_id = ?', [$userId]);
        $db->update('devices', ['is_active' => 0, 'fcm_token' => null], 'user_id = :uid', ['uid' => $userId]);

        AuditService::log('account.deleted', 'user', $userId, [], 'user', $userId);
        Auth::logout();

        Session::flash('success', 'Your account has been deleted. All data will be purged within 30 days.');
        Response::redirect(url('/'));
    }
}
