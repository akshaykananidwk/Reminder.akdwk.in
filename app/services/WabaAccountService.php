<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * Connected WhatsApp Business Accounts — the tenant boundary of the platform.
 *
 * Everything Meta-facing is addressed by a `waba_accounts` row: its own access
 * token, app secret, phone numbers, templates, webhooks and billing. A single
 * installation can therefore carry many businesses without one being able to
 * send as another.
 *
 * Tokens never leave this class in the clear except through `token()`. They are
 * encrypted at rest with the same AES-256-GCM key as every other secret, so a
 * stolen database dump is not a stolen WhatsApp account.
 */
class WabaAccountService
{
    /* ------------------------------------------------------------ Reading */

    public static function find(int $id): ?array
    {
        return self::row('SELECT * FROM waba_accounts WHERE id = ?', [$id]);
    }

    public static function findByWabaId(string $wabaId): ?array
    {
        $wabaId = trim($wabaId);

        return $wabaId === '' ? null : self::row('SELECT * FROM waba_accounts WHERE waba_id = ?', [$wabaId]);
    }

    /** Which account owns this phone number id? Webhooks arrive addressed this way. */
    public static function findByPhoneNumberId(string $phoneNumberId): ?array
    {
        $phoneNumberId = trim($phoneNumberId);

        if ($phoneNumberId === '') {
            return null;
        }

        return self::row(
            'SELECT a.* FROM waba_accounts a
               JOIN waba_phone_numbers p ON p.waba_account_id = a.id
              WHERE p.phone_number_id = ?
              LIMIT 1',
            [$phoneNumberId]
        );
    }

    /**
     * The account a given user sends through.
     *
     * A tenant's own account wins; otherwise the platform account (the one with
     * no owner) is used, which is what a single-business installation has.
     */
    public static function forUser(?int $userId = null): ?array
    {
        if ($userId !== null) {
            $own = self::row(
                "SELECT * FROM waba_accounts WHERE owner_user_id = ? AND status = 'active' ORDER BY id LIMIT 1",
                [$userId]
            );

            if ($own !== null) {
                return $own;
            }
        }

        return self::platform();
    }

    /** The installation's own account — no owner, active, lowest id. */
    public static function platform(): ?array
    {
        $row = self::row(
            "SELECT * FROM waba_accounts WHERE owner_user_id IS NULL AND status = 'active' ORDER BY id LIMIT 1"
        );

        if ($row !== null) {
            return $row;
        }

        // An installation that was configured before multi-tenancy existed has
        // its credentials in `settings` and no row here. Adopt them rather than
        // making the operator re-enter what they already typed.
        return self::adoptLegacySettings();
    }

    /** @return array<int, array> */
    public static function all(bool $activeOnly = false): array
    {
        try {
            $sql = 'SELECT * FROM waba_accounts';

            if ($activeOnly) {
                $sql .= " WHERE status = 'active'";
            }

            return App::i()->db()->all($sql . ' ORDER BY id');
        } catch (\Throwable) {
            return [];
        }
    }

    /* ------------------------------------------------------------ Secrets */

    /** The decrypted access token, or '' when there is none we can read. */
    public static function token(?array $account): string
    {
        $stored = trim((string) ($account['access_token'] ?? ''));

        if ($stored === '') {
            return '';
        }

        $plain = Crypto::decrypt($stored);

        if ($plain === null) {
            // Either the row predates encryption, or APP_KEY changed. A token
            // that starts EAA is plainly not ciphertext, so accept it; anything
            // else is unreadable and must not be sent as a bearer token.
            if (str_starts_with($stored, 'EAA')) {
                return $stored;
            }

            Logger::error('WABA access token could not be decrypted', [
                'account_id' => $account['id'] ?? null,
            ], 'meta');

            return '';
        }

        return $plain;
    }

    /** The app secret used to verify this account's webhook signatures. */
    public static function appSecret(?array $account): string
    {
        $stored = trim((string) ($account['app_secret'] ?? ''));

        if ($stored !== '') {
            $plain = Crypto::decrypt($stored);

            if ($plain !== null && $plain !== '') {
                return $plain;
            }

            // Not ciphertext — a 32-character hex secret pasted directly.
            if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
                return $stored;
            }
        }

        // Fall back to the platform app secret: with Embedded Signup every
        // tenant's webhook is signed by our own app, not by theirs.
        try {
            return trim((string) App::i()->settings()->get('meta_app_secret', ''))
                ?: trim((string) App::i()->settings()->get('wa_cloud_app_secret', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Does this account have enough to actually call Meta? */
    public static function isUsable(?array $account): bool
    {
        return $account !== null
            && (string) ($account['status'] ?? '') === 'active'
            && self::token($account) !== ''
            && self::defaultPhone((int) $account['id']) !== null;
    }

    /* ------------------------------------------------------------ Writing */

    /**
     * Create or update an account by its WABA id. Secrets in `$data` are given
     * in the clear and encrypted here, so no caller has to remember to.
     */
    public static function save(array $data): int
    {
        $db = App::i()->db();
        $wabaId = trim((string) ($data['waba_id'] ?? ''));

        if ($wabaId === '') {
            throw new \InvalidArgumentException('waba_id is required');
        }

        foreach (['access_token', 'app_secret'] as $secret) {
            if (isset($data[$secret]) && is_string($data[$secret])) {
                $value = trim($data[$secret]);
                $data[$secret] = $value === '' ? null : Crypto::encrypt($value);
            }
        }

        $existing = self::findByWabaId($wabaId);
        $data['updated_at'] = now_utc();

        if ($existing !== null) {
            // A blank secret means "leave it alone", never "erase it" — an admin
            // form that shows a masked field must not wipe a working token.
            foreach (['access_token', 'app_secret'] as $secret) {
                if (($data[$secret] ?? null) === null) {
                    unset($data[$secret]);
                }
            }

            $db->update('waba_accounts', $data, 'id = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        $data['created_at'] ??= now_utc();
        $data['name'] ??= 'WhatsApp Business Account';

        return $db->insert('waba_accounts', $data);
    }

    public static function markError(int $accountId, string $message): void
    {
        try {
            App::i()->db()->update('waba_accounts', [
                'last_error' => mb_substr($message, 0, 500),
                'updated_at' => now_utc(),
            ], 'id = :id', ['id' => $accountId]);
        } catch (\Throwable) {
            // Never let bookkeeping mask the original failure.
        }
    }

    public static function clearError(int $accountId): void
    {
        try {
            App::i()->db()->update(
                'waba_accounts',
                ['last_error' => null, 'updated_at' => now_utc()],
                'id = :id',
                ['id' => $accountId]
            );
        } catch (\Throwable) {
        }
    }

    /* ------------------------------------------------------- Phone numbers */

    public static function defaultPhone(int $accountId): ?array
    {
        return self::row(
            'SELECT * FROM waba_phone_numbers
              WHERE waba_account_id = ? AND is_active = 1
              ORDER BY is_default DESC, id
              LIMIT 1',
            [$accountId]
        );
    }

    public static function phoneNumberId(?array $account): string
    {
        if ($account === null) {
            return '';
        }

        $phone = self::defaultPhone((int) $account['id']);

        return $phone === null ? '' : (string) $phone['phone_number_id'];
    }

    /**
     * Pull the phone numbers Meta has under this WABA and mirror them locally,
     * including quality rating and messaging tier — the two numbers an operator
     * needs when deliveries start failing.
     *
     * @return array{ok: bool, count: int, message: string}
     */
    public static function syncPhoneNumbers(int $accountId): array
    {
        $account = self::find($accountId);

        if ($account === null) {
            return ['ok' => false, 'count' => 0, 'message' => 'Account not found.'];
        }

        $result = MetaGraph::get((string) $account['waba_id'] . '/phone_numbers', [
            'token'      => self::token($account),
            'account_id' => $accountId,
            'query'      => [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,'
                          . 'code_verification_status,platform_type,throughput',
                'limit'  => 100,
            ],
        ]);

        if (!$result['ok']) {
            self::markError($accountId, MetaGraph::explain($result));

            return ['ok' => false, 'count' => 0, 'message' => MetaGraph::explain($result)];
        }

        $db = App::i()->db();
        $rows = $result['json']['data'] ?? [];
        $count = 0;
        $hasDefault = self::defaultPhone($accountId) !== null;

        foreach (is_array($rows) ? $rows : [] as $row) {
            $phoneNumberId = trim((string) ($row['id'] ?? ''));

            if ($phoneNumberId === '') {
                continue;
            }

            $quality = strtoupper((string) ($row['quality_rating'] ?? 'UNKNOWN'));

            $db->upsert('waba_phone_numbers', [
                'waba_account_id' => $accountId,
                'phone_number_id' => $phoneNumberId,
                'display_number'  => mb_substr((string) ($row['display_phone_number'] ?? ''), 0, 24),
                'verified_name'   => mb_substr((string) ($row['verified_name'] ?? ''), 0, 190),
                'quality_rating'  => in_array($quality, ['GREEN', 'YELLOW', 'RED'], true) ? $quality : 'UNKNOWN',
                'messaging_limit' => mb_substr((string) ($row['throughput']['level'] ?? ''), 0, 32) ?: null,
                'platform_type'   => mb_substr((string) ($row['platform_type'] ?? ''), 0, 32) ?: null,
                'is_default'      => $hasDefault ? 0 : 1,
                'is_active'       => 1,
                'created_at'      => now_utc(),
                'updated_at'      => now_utc(),
            ], [
                'display_number', 'verified_name', 'quality_rating',
                'messaging_limit', 'platform_type', 'is_active', 'updated_at',
            ]);

            $hasDefault = true;
            $count++;
        }

        self::clearError($accountId);

        return ['ok' => true, 'count' => $count, 'message' => $count . ' number(s) synced.'];
    }

    /* ----------------------------------------------------------- Webhooks */

    /**
     * Subscribe our app to this WABA's webhooks.
     *
     * Without this the callback URL is configured but Meta sends nothing, which
     * looks exactly like a broken webhook and wastes an afternoon. It is the
     * single most-missed step of a Cloud API setup, so the platform does it
     * itself rather than documenting it.
     *
     * @return array{ok: bool, message: string}
     */
    public static function subscribeWebhook(int $accountId): array
    {
        $account = self::find($accountId);

        if ($account === null) {
            return ['ok' => false, 'message' => 'Account not found.'];
        }

        $result = MetaGraph::post((string) $account['waba_id'] . '/subscribed_apps', [
            'token'      => self::token($account),
            'account_id' => $accountId,
            // Safe to repeat: subscribing twice is the same as subscribing once.
            'idempotent' => true,
        ]);

        if (!$result['ok']) {
            $message = MetaGraph::explain($result);
            self::markError($accountId, $message);

            return ['ok' => false, 'message' => $message];
        }

        App::i()->db()->update('waba_accounts', [
            'webhook_subscribed_at' => now_utc(),
            'updated_at'            => now_utc(),
        ], 'id = :id', ['id' => $accountId]);

        self::clearError($accountId);

        return ['ok' => true, 'message' => 'Webhook subscribed.'];
    }

    /** Is our app currently subscribed? Asked by the health panel. */
    public static function webhookStatus(int $accountId): array
    {
        $account = self::find($accountId);

        if ($account === null) {
            return ['ok' => false, 'message' => 'Account not found.'];
        }

        $result = MetaGraph::get((string) $account['waba_id'] . '/subscribed_apps', [
            'token'      => self::token($account),
            'account_id' => $accountId,
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'message' => MetaGraph::explain($result)];
        }

        $apps = $result['json']['data'] ?? [];
        $count = is_array($apps) ? count($apps) : 0;

        return [
            'ok'      => $count > 0,
            'message' => $count > 0
                ? 'Subscribed (' . $count . ' app' . ($count === 1 ? '' : 's') . ').'
                : 'Not subscribed — Meta will send no webhooks until this is fixed.',
        ];
    }

    /* --------------------------------------------------- Phone registration */

    /**
     * Register a phone number with the Cloud API using its two-step PIN. Until
     * this succeeds every send answers 133010.
     *
     * @return array{ok: bool, message: string}
     */
    public static function registerPhone(int $accountId, string $phoneNumberId, string $pin): array
    {
        $account = self::find($accountId);

        if ($account === null) {
            return ['ok' => false, 'message' => 'Account not found.'];
        }

        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            return ['ok' => false, 'message' => 'The two-step PIN must be exactly six digits.'];
        }

        $result = MetaGraph::post(rawurlencode($phoneNumberId) . '/register', [
            'token'      => self::token($account),
            'account_id' => $accountId,
            'idempotent' => true,
            'json'       => ['messaging_product' => 'whatsapp', 'pin' => $pin],
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'message' => MetaGraph::explain($result)];
        }

        try {
            App::i()->db()->update('waba_phone_numbers', [
                'registered_at' => now_utc(),
                'updated_at'    => now_utc(),
            ], 'phone_number_id = :pid', ['pid' => $phoneNumberId]);
        } catch (\Throwable) {
        }

        return ['ok' => true, 'message' => 'Number registered with the Cloud API.'];
    }

    /* ------------------------------------------------------------- Legacy */

    /**
     * Turn the old single-account settings into a real account row.
     *
     * The previous build stored one token and one phone number id in `settings`.
     * Those installations must not break the moment this code ships, so the
     * first time the platform account is asked for and none exists, the settings
     * are promoted into `waba_accounts` — once, idempotently.
     */
    public static function adoptLegacySettings(): ?array
    {
        try {
            $settings = App::i()->settings();
            $token = trim((string) $settings->get('wa_cloud_token', ''));
            $phoneId = trim((string) $settings->get('wa_cloud_phone_id', ''));

            if ($token === '' || $phoneId === '' || !ctype_digit($phoneId)) {
                return null;
            }

            $db = App::i()->db();

            if (!$db->tableExists('waba_accounts')) {
                return null;
            }

            // The WABA id may not have been stored; the phone number id is the
            // one thing we always have, and Meta will tell us the rest.
            $wabaId = trim((string) $settings->get('wa_cloud_business_id', ''));

            if ($wabaId === '') {
                $lookup = MetaGraph::get(rawurlencode($phoneId), [
                    'token' => $token,
                    'query' => ['fields' => 'id,display_phone_number,verified_name,quality_rating,whatsapp_business_account'],
                ]);

                $wabaId = trim((string) ($lookup['json']['whatsapp_business_account']['id'] ?? ''));
            }

            if ($wabaId === '') {
                // Without a WABA id there is no account to key on. Use the phone
                // number id as a stable stand-in so the row is still unique and
                // sending works; a later sync fills in the real one.
                $wabaId = 'pnid:' . $phoneId;
            }

            $accountId = self::save([
                'owner_user_id' => null,
                'name'          => (string) ($settings->get('site_name', '') ?: 'WhatsApp Business Account'),
                'waba_id'       => $wabaId,
                'access_token'  => $token,
                'app_secret'    => trim((string) $settings->get('wa_cloud_app_secret', '')),
                'app_id'        => trim((string) $settings->get('meta_app_id', '')) ?: null,
                'token_type'    => 'system_user',
                'status'        => 'active',
            ]);

            $db->upsert('waba_phone_numbers', [
                'waba_account_id' => $accountId,
                'phone_number_id' => $phoneId,
                'display_number'  => mb_substr(normalize_phone((string) $settings->get('wa_sender_number', '')), 0, 24),
                'is_default'      => 1,
                'is_active'       => 1,
                'created_at'      => now_utc(),
                'updated_at'      => now_utc(),
            ], ['is_active', 'updated_at']);

            Logger::info('Adopted legacy Cloud API settings into a WABA account', [
                'account_id' => $accountId,
            ], 'meta');

            return self::find($accountId);
        } catch (\Throwable $e) {
            Logger::warn('Could not adopt legacy Cloud API settings', ['error' => $e->getMessage()], 'meta');

            return null;
        }
    }

    /* -------------------------------------------------------------- Utils */

    private static function row(string $sql, array $params = []): ?array
    {
        try {
            return App::i()->db()->one($sql, $params);
        } catch (\Throwable) {
            // The tables may not exist yet on a half-migrated install.
            return null;
        }
    }
}
