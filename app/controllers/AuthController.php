<?php

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Crypto;
use App\Core\Lang;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\GoogleService;
use App\Services\OtpService;
use App\Services\ReminderService;
use App\Services\TemplateService;
use App\Services\WhatsAppService;

/**
 * Registration, WhatsApp OTP verification, login (password or OTP), password
 * reset and optional Google sign-in.
 */
class AuthController extends Controller
{
    private const MAX_LOGIN_FAILURES = 6;
    private const LOCKOUT_MINUTES = 15;

    /* --------------------------------------------------------- Registration */

    public function showRegister(): void
    {
        $this->view('auth/register', [
            'title' => __('auth.register_title'),
            'plans' => App::i()->db()->all('SELECT code, name, name_gu, name_hi, price FROM plans WHERE is_active = 1 ORDER BY sort_order'),
        ], 'layouts/auth');
    }

    public function register(): void
    {
        if (!RateLimiter::attempt('register_' . Request::ip(), 8, 3600)) {
            Session::flash('error', __('api.rate_limited'));
            Response::back(url('/register'));
        }

        $validator = Validator::make($_POST, [
            'name'     => 'required|string|min:2|max:120',
            'phone'    => 'required|phone',
            'email'    => 'nullable|email|max:190',
            'password' => 'required|password',
            'language' => 'required|in:gu,hi,en',
            'city'     => 'nullable|string|max:120',
            'timezone' => 'nullable|string|max:60',
        ], [
            'name'     => __('common.name'),
            'phone'    => __('common.phone'),
            'password' => __('common.password'),
        ]);

        if ($validator->fails()) {
            Session::flashInput($_POST);
            Session::flash('error', $validator->firstError());
            Response::back(url('/register'));
        }

        $data = $validator->validated();
        $phone = normalize_phone((string) $data['phone']);
        $db = App::i()->db();

        if ($db->one('SELECT id FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]) !== null) {
            Session::flashInput($_POST);
            Session::flash('error', __('auth.number_taken'));
            Response::back(url('/register'));
        }

        if (!empty($data['email']) && $db->one('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$data['email']]) !== null) {
            Session::flashInput($_POST);
            Session::flash('error', __('auth.email_taken'));
            Response::back(url('/register'));
        }

        $timezone = (string) ($data['timezone'] ?? 'Asia/Kolkata');

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = (string) App::i()->settings()->get('default_timezone', 'Asia/Kolkata');
        }

        $trialPlan = $db->one('SELECT * FROM plans WHERE is_default = 1 AND is_active = 1 LIMIT 1');
        $referrerCode = (string) Request::input('ref', '');
        $referrer = $referrerCode !== '' ? $db->one('SELECT id FROM users WHERE referral_code = ?', [$referrerCode]) : null;

        $userId = $db->insert('users', [
            'name'            => (string) $data['name'],
            'email'           => $data['email'] ?: null,
            'phone'           => $phone,
            'password_hash'   => Auth::hashPassword((string) $data['password']),
            'language'        => (string) $data['language'],
            'timezone'        => $timezone,
            'city'            => $data['city'] ?: null,
            'is_active'       => 1,
            'is_verified'     => 0,
            'plan_id'         => $trialPlan === null ? null : (int) $trialPlan['id'],
            'plan_expires_at' => $trialPlan === null ? null : date('Y-m-d H:i:s', time() + (((int) $trialPlan['trial_days'] ?: 7) * 86400)),
            'referred_by'     => $referrer === null ? null : (int) $referrer['id'],
            'referral_code'   => $this->uniqueReferralCode(),
            'created_at'      => now_utc(),
        ]);

        $db->insert('whatsapp_numbers', [
            'user_id'    => $userId,
            'number'     => $phone,
            'label'      => 'Primary',
            'is_primary' => 1,
            'is_verified'=> 0,
            'is_active'  => 1,
            'created_at' => now_utc(),
        ]);

        ReminderService::ensureSettings($userId);

        if ($referrer !== null) {
            try {
                $db->insert('referrals', [
                    'referrer_id' => (int) $referrer['id'],
                    'referred_id' => $userId,
                    'status'      => 'signed_up',
                    'created_at'  => now_utc(),
                ]);
            } catch (\Throwable) {
                // Already recorded.
            }
        }

        Lang::setLocale((string) $data['language']);
        OtpService::send($phone, 'register', $userId, (string) $data['language']);

        Session::set('pending_verification', ['phone' => $phone, 'user_id' => $userId, 'purpose' => 'register']);
        Session::clearOldInput();

        Response::redirect(url('/verify'));
    }

    /* -------------------------------------------------------- Verification */

    public function showVerify(): void
    {
        $pending = Session::get('pending_verification');

        if (!is_array($pending)) {
            Response::redirect(url('/login'));
        }

        $this->view('auth/verify', [
            'title' => __('auth.verify_otp'),
            'phone' => (string) $pending['phone'],
        ], 'layouts/auth');
    }

    public function verify(): void
    {
        $pending = Session::get('pending_verification');

        if (!is_array($pending)) {
            Response::redirect(url('/login'));
        }

        $phone = (string) $pending['phone'];
        $purpose = (string) ($pending['purpose'] ?? 'register');
        $code = (string) Request::post('code', '');

        $result = OtpService::verify($phone, $code, $purpose);

        if (!$result['ok']) {
            AuditService::loginAttempt($phone, false, 'otp');
            Session::flash('error', $result['message']);
            Response::back(url('/verify'));
        }

        $db = App::i()->db();

        $db->update('whatsapp_numbers', [
            'is_verified' => 1,
            'verified_at' => now_utc(),
        ], 'number = :number', ['number' => $phone]);

        $user = $db->one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);

        if ($user === null) {
            // Verifying an additional number for an already logged-in account.
            Session::forget('pending_verification');
            Session::flash('success', __('auth.otp_verified'));
            Response::redirect(url('/client/settings'));
        }

        $db->update('users', ['is_verified' => 1], 'id = :id', ['id' => (int) $user['id']]);

        if ($purpose === 'register') {
            WhatsAppService::queue(
                $phone,
                TemplateService::render('welcome', (string) $user['language'], ['name' => (string) $user['name']]),
                (int) $user['id'],
                null,
                3,
                'welcome'
            );
        }

        Session::forget('pending_verification');
        Auth::login((int) $user['id'], true);
        AuditService::loginAttempt($phone, true, 'otp');

        Session::flash('success', __('auth.registered'));
        Response::redirect(url('/client'));
    }

    public function sendOtp(): void
    {
        $phone = normalize_phone((string) Request::post('phone', ''));
        $purpose = (string) Request::post('purpose', 'login');

        if (!in_array($purpose, ['login', 'register', 'verify_number', 'reset_password'], true)) {
            $purpose = 'login';
        }

        if ($phone === '') {
            $this->respond(false, __('auth.invalid_number'));
        }

        $db = App::i()->db();
        $user = $db->one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);

        // For login/reset we must not leak whether the number exists.
        if ($user === null && in_array($purpose, ['login', 'reset_password'], true)) {
            $this->respond(true, __('auth.otp_sent'));
        }

        $result = OtpService::send(
            $phone,
            $purpose,
            $user === null ? null : (int) $user['id'],
            (string) ($user['language'] ?? Lang::locale())
        );

        Session::set('pending_verification', ['phone' => $phone, 'user_id' => $user['id'] ?? null, 'purpose' => $purpose]);

        $this->respond($result['ok'], $result['message'], ['retry_after' => $result['retry_after']]);
    }

    /* ---------------------------------------------------------------- Login */

    public function showLogin(): void
    {
        $this->view('auth/login', ['title' => __('auth.login_title')], 'layouts/auth');
    }

    public function login(): void
    {
        $identifier = trim((string) Request::post('identifier', ''));
        $password = (string) Request::post('password', '');
        $remember = Request::bool('remember');

        if ($identifier === '' || $password === '') {
            Session::flash('error', __('auth.invalid_credentials'));
            Response::back(url('/login'));
        }

        if (AuditService::recentFailures($identifier, self::LOCKOUT_MINUTES) >= self::MAX_LOGIN_FAILURES) {
            Session::flash('error', __('auth.locked_out', ['minutes' => self::LOCKOUT_MINUTES]));
            Response::back(url('/login'));
        }

        if (!RateLimiter::attempt('login_' . Request::ip(), 25, 900)) {
            Session::flash('error', __('api.rate_limited'));
            Response::back(url('/login'));
        }

        $db = App::i()->db();
        $phone = normalize_phone($identifier);

        $user = str_contains($identifier, '@')
            ? $db->one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$identifier])
            : $db->one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);

        if ($user === null || !Auth::verifyPassword($password, $user['password_hash'] ?? null)) {
            AuditService::loginAttempt($identifier, false);
            Session::flash('error', __('auth.invalid_credentials'));
            Response::back(url('/login'));
        }

        if ((int) $user['is_active'] !== 1) {
            Session::flash('error', __('auth.account_suspended'));
            Response::back(url('/login'));
        }

        if ((int) $user['is_verified'] !== 1) {
            OtpService::send((string) $user['phone'], 'register', (int) $user['id'], (string) $user['language']);
            Session::set('pending_verification', ['phone' => (string) $user['phone'], 'user_id' => (int) $user['id'], 'purpose' => 'register']);
            Session::flash('warning', __('auth.verify_first'));
            Response::redirect(url('/verify'));
        }

        AuditService::loginAttempt($identifier, true);
        Auth::login((int) $user['id'], $remember);

        $intended = Session::get('_intended');
        Session::forget('_intended');

        Session::flash('success', __('auth.welcome_back', ['name' => $user['name']]));
        Response::redirect(url(is_string($intended) && str_starts_with($intended, '/client') ? $intended : '/client'));
    }

    /** Number + OTP login (no password needed). */
    public function loginWithOtp(): void
    {
        $phone = normalize_phone((string) Request::post('phone', ''));
        $code = (string) Request::post('code', '');

        $result = OtpService::verify($phone, $code, 'login');

        if (!$result['ok']) {
            AuditService::loginAttempt($phone, false, 'otp');
            Session::flash('error', $result['message']);
            Response::back(url('/login'));
        }

        $user = App::i()->db()->one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);

        if ($user === null || (int) $user['is_active'] !== 1) {
            Session::flash('error', __('auth.account_suspended'));
            Response::back(url('/login'));
        }

        AuditService::loginAttempt($phone, true, 'otp');
        Auth::login((int) $user['id'], true);

        Session::flash('success', __('auth.welcome_back', ['name' => $user['name']]));
        Response::redirect(url('/client'));
    }

    public function logout(): void
    {
        Auth::logout();
        Session::flash('success', __('auth.logged_out'));
        Response::redirect(url('/login'));
    }

    /* ------------------------------------------------------ Password reset */

    public function showForgot(): void
    {
        $this->view('auth/forgot', ['title' => __('auth.forgot_password')], 'layouts/auth');
    }

    public function forgot(): void
    {
        $phone = normalize_phone((string) Request::post('phone', ''));
        $user = App::i()->db()->one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);

        if ($user !== null) {
            OtpService::send($phone, 'reset_password', (int) $user['id'], (string) $user['language']);
        }

        Session::set('pending_verification', ['phone' => $phone, 'purpose' => 'reset_password']);
        Session::flash('success', __('auth.otp_sent'));
        Response::redirect(url('/reset-password'));
    }

    public function showReset(): void
    {
        $pending = Session::get('pending_verification');

        if (!is_array($pending)) {
            Response::redirect(url('/forgot-password'));
        }

        $this->view('auth/reset', [
            'title' => __('auth.reset_password'),
            'phone' => (string) $pending['phone'],
        ], 'layouts/auth');
    }

    public function reset(): void
    {
        $pending = Session::get('pending_verification');

        if (!is_array($pending)) {
            Response::redirect(url('/forgot-password'));
        }

        $validator = Validator::make($_POST, [
            'code'     => 'required|string|min:6|max:6',
            'password' => 'required|password|confirmed',
        ], ['password' => __('common.password')]);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());
            Response::back(url('/reset-password'));
        }

        $phone = (string) $pending['phone'];
        $result = OtpService::verify($phone, (string) Request::post('code', ''), 'reset_password');

        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            Response::back(url('/reset-password'));
        }

        $db = App::i()->db();
        $user = $db->one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);

        if ($user === null) {
            Session::flash('error', __('common.not_found'));
            Response::redirect(url('/login'));
        }

        $db->update('users', [
            'password_hash' => Auth::hashPassword((string) Request::post('password', '')),
        ], 'id = :id', ['id' => (int) $user['id']]);

        // Any existing session could belong to the attacker; drop them all.
        $db->query('UPDATE sessions SET revoked = 1 WHERE user_id = ?', [(int) $user['id']]);

        Session::forget('pending_verification');
        AuditService::log('password.reset', 'user', (int) $user['id'], [], 'user', (int) $user['id']);

        Session::flash('success', __('auth.password_changed'));
        Response::redirect(url('/login'));
    }

    /* --------------------------------------------------------------- Google */

    public function googleRedirect(): void
    {
        if (!GoogleService::isEnabled()) {
            Session::flash('error', 'Google sign-in is not configured.');
            Response::redirect(url('/login'));
        }

        $state = Crypto::randomToken(16);
        Session::set('google_state', $state);
        Session::set('google_intent', Auth::check() ? 'connect' : 'login');

        Response::redirect(GoogleService::authUrl($state, !Auth::check()));
    }

    public function googleCallback(): void
    {
        $state = (string) Request::get('state', '');
        $expected = (string) Session::get('google_state', '');
        Session::forget('google_state');

        if ($state === '' || !hash_equals($expected, $state)) {
            Session::flash('error', 'Google sign-in failed (state mismatch).');
            Response::redirect(url('/login'));
        }

        $code = (string) Request::get('code', '');

        if ($code === '') {
            Session::flash('error', 'Google sign-in was cancelled.');
            Response::redirect(url('/login'));
        }

        $exchange = GoogleService::exchangeCode($code);

        if (!$exchange['ok']) {
            Session::flash('error', 'Google sign-in failed: ' . $exchange['error']);
            Response::redirect(url('/login'));
        }

        $tokens = (array) $exchange['tokens'];
        $profile = GoogleService::userInfo((string) ($tokens['access_token'] ?? ''));

        // Connecting Google to an existing, logged-in account.
        if (Auth::check()) {
            GoogleService::storeAccount((int) Auth::id(), $tokens, $profile);
            Session::flash('success', 'Google connected.');
            Response::redirect(url('/client/integrations'));
        }

        $email = (string) ($profile['email'] ?? '');
        $user = $email === '' ? null : App::i()->db()->one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);

        // A WhatsApp number is still mandatory, so unknown Google users register.
        if ($user === null) {
            Session::flash('warning', 'Please sign up with your WhatsApp number first — it is required for reminders.');
            Session::flashInput(['email' => $email, 'name' => (string) ($profile['name'] ?? '')]);
            Response::redirect(url('/register'));
        }

        GoogleService::storeAccount((int) $user['id'], $tokens, $profile);
        Auth::login((int) $user['id'], true);

        Session::flash('success', __('auth.welcome_back', ['name' => $user['name']]));
        Response::redirect(url('/client'));
    }

    /* -------------------------------------------------------------- Helpers */

    private function uniqueReferralCode(): string
    {
        $db = App::i()->db();

        for ($i = 0; $i < 20; $i++) {
            $code = 'KR' . Crypto::shortCode(5);

            if ($db->one('SELECT id FROM users WHERE referral_code = ?', [$code]) === null) {
                return $code;
            }
        }

        return 'KR' . Crypto::shortCode(8);
    }

    private function respond(bool $ok, string $message, array $data = []): never
    {
        if (Request::wantsJson()) {
            Response::json($data, $message, $ok ? 200 : 429, $ok ? 'OK' : 'THROTTLED');
        }

        Session::flash($ok ? 'success' : 'error', $message);
        Response::back(url('/login'));
    }
}
