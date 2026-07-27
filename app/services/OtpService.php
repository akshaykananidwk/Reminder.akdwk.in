<?php

namespace App\Services;

use App\Core\App;
use App\Core\RateLimiter;
use App\Core\Request;

/**
 * WhatsApp OTP issue/verify with expiry, attempt caps, resend cooldown and
 * per-IP / per-number rate limits.
 */
class OtpService
{
    public const TTL_SECONDS = 300;      // 5 minutes
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN = 60;   // seconds

    /**
     * @return array{ok: bool, message: string, retry_after: int}
     */
    public static function send(string $phone, string $purpose = 'login', ?int $userId = null, string $lang = 'gu'): array
    {
        $phone = normalize_phone($phone);

        if ($phone === '') {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.invalid_number', [], $lang), 'retry_after' => 0];
        }

        if (WhatsAppService::isBlocked($phone)) {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.number_blocked', [], $lang), 'retry_after' => 0];
        }

        // Resend cooldown per number.
        $cooldownKey = 'otp_cooldown_' . $phone;

        if (!RateLimiter::attempt($cooldownKey, 1, self::RESEND_COOLDOWN)) {
            return [
                'ok'          => false,
                'message'     => \App\Core\Lang::get('auth.otp_cooldown', [], $lang),
                'retry_after' => RateLimiter::secondsUntilReset($cooldownKey, self::RESEND_COOLDOWN),
            ];
        }

        // Hourly ceilings: 5 per number, 15 per IP.
        if (!RateLimiter::attempt('otp_hour_' . $phone, 5, 3600)) {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_too_many', [], $lang), 'retry_after' => 3600];
        }

        if (PHP_SAPI !== 'cli' && !RateLimiter::attempt('otp_ip_' . Request::ip(), 15, 3600)) {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_too_many', [], $lang), 'retry_after' => 3600];
        }

        $db = App::i()->db();

        // Invalidate any outstanding code for this number+purpose.
        $db->query('UPDATE otp_codes SET is_used = 1 WHERE phone = ? AND purpose = ? AND is_used = 0', [$phone, $purpose]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $db->insert('otp_codes', [
            'phone'      => $phone,
            'user_id'    => $userId,
            'code_hash'  => password_hash($code, PASSWORD_BCRYPT),
            'purpose'    => $purpose,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            'ip'         => PHP_SAPI === 'cli' ? null : Request::ip(),
            'created_at' => now_utc(),
        ]);

        $message = TemplateService::render('otp', $lang, ['code' => $code]);

        if ($message === '') {
            $message = 'Krishna Reminder verification code: ' . $code;
        }

        // OTP is the highest-priority outbound message; send it straight away
        // rather than waiting for the next queue tick.
        $result = WhatsAppService::sendNow($phone, $message, null, $userId);

        if (!$result['ok']) {
            // Fall back to the queue so a transient gateway blip is retried.
            WhatsAppService::queue($phone, $message, $userId, null, 1, 'otp');
        }

        return ['ok' => true, 'message' => \App\Core\Lang::get('auth.otp_sent', [], $lang), 'retry_after' => self::RESEND_COOLDOWN];
    }

    /**
     * @return array{ok: bool, message: string, user_id: int|null}
     */
    public static function verify(string $phone, string $code, string $purpose = 'login', string $lang = 'gu'): array
    {
        $phone = normalize_phone($phone);
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if ($phone === '' || strlen($code) !== 6) {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_invalid', [], $lang), 'user_id' => null];
        }

        $db = App::i()->db();

        $row = $db->one(
            'SELECT * FROM otp_codes WHERE phone = ? AND purpose = ? AND is_used = 0 ORDER BY id DESC LIMIT 1',
            [$phone, $purpose]
        );

        if ($row === null) {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_not_found', [], $lang), 'user_id' => null];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $db->update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => (int) $row['id']]);

            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_expired', [], $lang), 'user_id' => null];
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $db->update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => (int) $row['id']]);

            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_too_many_attempts', [], $lang), 'user_id' => null];
        }

        $db->query('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);

        if (!password_verify($code, (string) $row['code_hash'])) {
            return ['ok' => false, 'message' => \App\Core\Lang::get('auth.otp_invalid', [], $lang), 'user_id' => null];
        }

        $db->update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => (int) $row['id']]);
        RateLimiter::clear('otp_cooldown_' . $phone);

        return [
            'ok'      => true,
            'message' => \App\Core\Lang::get('auth.otp_verified', [], $lang),
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
        ];
    }

    public static function purgeExpired(): int
    {
        try {
            return App::i()->db()->delete('otp_codes', 'expires_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        } catch (\Throwable) {
            return 0;
        }
    }
}
