<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Four-digit app PIN.
 *
 * The user sets it on the website; the phone then unlocks with just the site
 * URL and the PIN. Before this existed the app could only be signed into with
 * an OTP delivered over WhatsApp — so whenever the WhatsApp gateway was down,
 * nobody could sign in at all and the app showed an empty list forever.
 *
 * A four-digit PIN is 10,000 combinations. That is only safe because guessing
 * is throttled and locked out here — the length is the user's choice, the
 * protection around it is not optional:
 *
 *   - stored as a bcrypt hash, never in plain text, never recoverable;
 *   - 5 wrong attempts locks the account's PIN for 15 minutes;
 *   - 10 wrong attempts within a day disables the PIN entirely, and it has to
 *     be set again from the website;
 *   - obvious PINs (0000, 1234, 1111, 2580 …) are refused;
 *   - a correct PIN clears the counter, a lockout never leaks whether the PIN
 *     was right.
 */
class AppPinService
{
    public const LENGTH = 4;

    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;      // 15 minutes
    private const DISABLE_ATTEMPTS = 10;   // within DISABLE_WINDOW
    private const DISABLE_WINDOW = 86400;

    /**
     * PINs that a stranger would try first. Refusing them costs the user
     * nothing and removes the only guesses worth making.
     */
    private const FORBIDDEN = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '2345', '3456', '4567', '5678', '6789', '7890',
        '4321', '5432', '6543', '7654', '8765', '9876', '0987',
        '1212', '2121', '1010', '0101', '1122', '2580', '0852', '1379',
    ];

    /* --------------------------------------------------------------- Rules */

    /**
     * @return array{ok: bool, message: string}
     */
    public static function validate(string $pin): array
    {
        if (preg_match('/^\d{' . self::LENGTH . '}$/', $pin) !== 1) {
            return ['ok' => false, 'message' => __('pin.must_be_four_digits')];
        }

        if (in_array($pin, self::FORBIDDEN, true)) {
            return ['ok' => false, 'message' => __('pin.too_easy')];
        }

        return ['ok' => true, 'message' => ''];
    }

    /* --------------------------------------------------------------- Store */

    /**
     * @return array{ok: bool, message: string}
     */
    public static function set(int $userId, string $pin): array
    {
        $check = self::validate($pin);

        if (!$check['ok']) {
            return $check;
        }

        App::i()->db()->update('users', [
            'app_pin_hash'          => password_hash($pin, PASSWORD_BCRYPT),
            'app_pin_set_at'        => now_utc(),
            'app_pin_attempts'      => 0,
            'app_pin_locked_until'  => null,
            'app_pin_last_fail_at'  => null,
        ], 'id = :id', ['id' => $userId]);

        AuditService::log('user.app_pin_set', 'user', $userId);
        Logger::info('App PIN set', ['user_id' => $userId], 'auth');

        return ['ok' => true, 'message' => __('pin.saved')];
    }

    public static function clear(int $userId): void
    {
        App::i()->db()->update('users', [
            'app_pin_hash'         => null,
            'app_pin_set_at'       => null,
            'app_pin_attempts'     => 0,
            'app_pin_locked_until' => null,
        ], 'id = :id', ['id' => $userId]);

        AuditService::log('user.app_pin_cleared', 'user', $userId);
    }

    public static function isSet(int $userId): bool
    {
        $hash = App::i()->db()->value('SELECT app_pin_hash FROM users WHERE id = ?', [$userId]);

        return is_string($hash) && $hash !== '';
    }

    /* -------------------------------------------------------------- Verify */

    /**
     * Check a PIN for a phone number.
     *
     * Deliberately returns the same shape whether the number is unknown, the
     * PIN is unset or the PIN is wrong — a login endpoint must not become a way
     * to find out which numbers are registered.
     *
     * @return array{ok: bool, user: array|null, message: string, retry_after: int, code: string}
     */
    public static function verify(string $phone, string $pin): array
    {
        $db = App::i()->db();
        $phone = normalize_phone($phone);

        $generic = [
            'ok'          => false,
            'user'        => null,
            'message'     => __('pin.incorrect'),
            'retry_after' => 0,
            'code'        => 'PIN_INVALID',
        ];

        if ($phone === '' || preg_match('/^\d{' . self::LENGTH . '}$/', $pin) !== 1) {
            return $generic;
        }

        $user = $db->one(
            'SELECT * FROM users WHERE phone = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
            [$phone]
        );

        if ($user === null || empty($user['app_pin_hash'])) {
            // Spend roughly the time a real check would, so timing does not
            // distinguish "no such user" from "wrong PIN".
            password_verify($pin, '$2y$10$usesomesillystringforeseeableusesomesillystringfore');

            return $generic;
        }

        $userId = (int) $user['id'];

        /* --- Locked out? -------------------------------------------------- */
        $lockedUntil = $user['app_pin_locked_until'];

        if ($lockedUntil !== null && strtotime((string) $lockedUntil . ' UTC') > time()) {
            $retryAfter = strtotime((string) $lockedUntil . ' UTC') - time();

            return [
                'ok'          => false,
                'user'        => null,
                'message'     => __('pin.locked', ['minutes' => (string) max(1, (int) ceil($retryAfter / 60))]),
                'retry_after' => $retryAfter,
                'code'        => 'PIN_LOCKED',
            ];
        }

        /* --- Wrong PIN ---------------------------------------------------- */
        if (!password_verify($pin, (string) $user['app_pin_hash'])) {
            return self::recordFailure($user);
        }

        /* --- Correct ------------------------------------------------------ */
        $db->update('users', [
            'app_pin_attempts'     => 0,
            'app_pin_locked_until' => null,
            'app_pin_last_fail_at' => null,
            'last_login_at'        => now_utc(),
        ], 'id = :id', ['id' => $userId]);

        Logger::info('App PIN login', ['user_id' => $userId], 'auth');

        return ['ok' => true, 'user' => $user, 'message' => '', 'retry_after' => 0, 'code' => 'OK'];
    }

    /**
     * @return array{ok: bool, user: null, message: string, retry_after: int, code: string}
     */
    private static function recordFailure(array $user): array
    {
        $db = App::i()->db();
        $userId = (int) $user['id'];

        // Failures older than the disable window no longer count, so an honest
        // typo months ago cannot combine with today's to disable the PIN.
        $lastFail = $user['app_pin_last_fail_at'];
        $withinWindow = $lastFail !== null
            && strtotime((string) $lastFail . ' UTC') > time() - self::DISABLE_WINDOW;

        $attempts = ($withinWindow ? (int) $user['app_pin_attempts'] : 0) + 1;

        /* Too many in a day — turn the PIN off completely. */
        if ($attempts >= self::DISABLE_ATTEMPTS) {
            self::clear($userId);

            Logger::warn('App PIN disabled after repeated failures', [
                'user_id'  => $userId,
                'attempts' => $attempts,
            ], 'auth');

            self::notifyLockout($user, true);

            return [
                'ok'          => false,
                'user'        => null,
                'message'     => __('pin.disabled'),
                'retry_after' => 0,
                'code'        => 'PIN_DISABLED',
            ];
        }

        /* Short lockout. */
        $lockUntil = $attempts >= self::MAX_ATTEMPTS
            ? gmdate('Y-m-d H:i:s', time() + self::LOCK_SECONDS)
            : null;

        $db->update('users', [
            'app_pin_attempts'     => $attempts,
            'app_pin_last_fail_at' => now_utc(),
            'app_pin_locked_until' => $lockUntil,
        ], 'id = :id', ['id' => $userId]);

        if ($lockUntil !== null) {
            Logger::warn('App PIN locked', ['user_id' => $userId, 'attempts' => $attempts], 'auth');
            self::notifyLockout($user, false);

            return [
                'ok'          => false,
                'user'        => null,
                'message'     => __('pin.locked', ['minutes' => (string) (self::LOCK_SECONDS / 60)]),
                'retry_after' => self::LOCK_SECONDS,
                'code'        => 'PIN_LOCKED',
            ];
        }

        return [
            'ok'          => false,
            'user'        => null,
            'message'     => __('pin.incorrect_remaining', [
                'count' => (string) (self::MAX_ATTEMPTS - $attempts),
            ]),
            'retry_after' => 0,
            'code'        => 'PIN_INVALID',
        ];
    }

    /**
     * Tell the owner someone is guessing. If their PIN is being attacked they
     * should hear about it from us, not discover it afterwards.
     */
    private static function notifyLockout(array $user, bool $disabled): void
    {
        try {
            WhatsAppService::queueTemplate(
                $disabled ? 'app_pin_disabled' : 'app_pin_locked',
                $user,
                ['name' => (string) ($user['name'] ?? '')],
                1
            );
        } catch (\Throwable $e) {
            Logger::warn('Could not send PIN lockout alert', ['error' => $e->getMessage()], 'auth');
        }
    }
}
