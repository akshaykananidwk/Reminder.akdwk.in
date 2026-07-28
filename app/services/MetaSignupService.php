<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * Embedded Signup: connecting a customer's WhatsApp Business Account from
 * inside this application, without them ever opening Meta's dashboard.
 *
 * The flow, in the order it actually happens:
 *
 *   1. The browser opens Meta's Facebook Login dialog with our `config_id`.
 *      The customer picks (or creates) a Business, a WhatsApp Business Account
 *      and a phone number entirely inside Meta's own dialog.
 *   2. The dialog posts back a WABA id and phone number id via `message_event`,
 *      and Facebook Login returns a short-lived authorisation **code**.
 *   3. We exchange that code, server-side, for a business access token. This is
 *      the step that must never happen in the browser: the exchange needs the
 *      app secret, and an app secret in JavaScript is an app secret published.
 *   4. We subscribe our app to that WABA's webhooks — the step everyone
 *      forgets, and the reason a correctly configured callback URL still
 *      receives nothing.
 *   5. We register the phone number with the Cloud API using its two-step PIN.
 *      Until that succeeds, every send answers 133010.
 *
 * Steps 3–5 are done here so a customer's connection is either complete or
 * reported as incomplete, never half-finished in a way that looks fine until
 * the first reminder is due.
 */
class MetaSignupService
{
    /* ------------------------------------------------------------- Config */

    /**
     * @return array{ok: bool, level: string, message: string, hint: string}
     */
    public static function status(): array
    {
        $settings = App::i()->settings();

        $appId = trim((string) $settings->get('meta_app_id', ''));
        $appSecret = trim((string) $settings->get('meta_app_secret', ''));
        $configId = trim((string) $settings->get('meta_config_id', ''));

        if ($appId === '' && $appSecret === '' && $configId === '') {
            return [
                'ok'      => false,
                'level'   => 'info',
                'message' => 'Embedded Signup is not set up.',
                'hint'    => 'Create a Meta app of type Business, add the WhatsApp product, then paste the App ID, '
                           . 'App Secret and Embedded Signup configuration ID here.',
            ];
        }

        foreach ([
            'meta_app_id'     => ['App ID', $appId],
            'meta_app_secret' => ['App Secret', $appSecret],
            'meta_config_id'  => ['Embedded Signup configuration ID', $configId],
        ] as [$label, $value]) {
            if ($value === '') {
                return [
                    'ok'      => false,
                    'level'   => 'error',
                    'message' => 'The ' . $label . ' is missing.',
                    'hint'    => 'All three are needed before the Connect button can work.',
                ];
            }
        }

        if (!ctype_digit($appId)) {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'The App ID must be digits only.',
                'hint'    => 'You may have pasted the app name or the display id instead of the numeric App ID.',
            ];
        }

        return ['ok' => true, 'level' => 'success', 'message' => 'Embedded Signup is ready.', 'hint' => ''];
    }

    public static function isConfigured(): bool
    {
        return self::status()['ok'];
    }

    /**
     * The values the login button needs. Deliberately contains no secret — this
     * is rendered into a page.
     *
     * @return array{app_id: string, config_id: string, version: string}
     */
    public static function browserConfig(): array
    {
        $settings = App::i()->settings();

        return [
            'app_id'    => trim((string) $settings->get('meta_app_id', '')),
            'config_id' => trim((string) $settings->get('meta_config_id', '')),
            'version'   => MetaGraph::version(),
        ];
    }

    /* ---------------------------------------------------------- Exchange */

    /**
     * Trade the authorisation code for a business access token.
     *
     * @return array{ok: bool, token: string|null, expires_in: int, message: string}
     */
    public static function exchangeCode(string $code): array
    {
        $settings = App::i()->settings();
        $appId = trim((string) $settings->get('meta_app_id', ''));
        $appSecret = trim((string) $settings->get('meta_app_secret', ''));

        if ($code === '' || $appId === '' || $appSecret === '') {
            return ['ok' => false, 'token' => null, 'expires_in' => 0, 'message' => 'Missing code or app credentials.'];
        }

        $result = MetaGraph::get('oauth/access_token', [
            'query'   => [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'code'          => $code,
            ],
            'timeout' => 20,
        ]);

        $token = $result['json']['access_token'] ?? null;

        if (!$result['ok'] || !is_string($token) || $token === '') {
            return [
                'ok'         => false,
                'token'      => null,
                'expires_in' => 0,
                'message'    => MetaGraph::explain($result),
            ];
        }

        return [
            'ok'         => true,
            'token'      => $token,
            'expires_in' => (int) ($result['json']['expires_in'] ?? 0),
            'message'    => 'Token issued.',
        ];
    }

    /**
     * Ask Meta what this token can actually do.
     *
     * The WABA ids come back in `granular_scopes`, which is the only reliable
     * way to learn them when the browser did not report them — and the browser
     * does not report them when the customer completed signup in a popup that
     * was blocked or closed early.
     *
     * @return array{ok: bool, waba_ids: array<int, string>, business_ids: array<int, string>,
     *               expires_at: int, scopes: array<int, string>, message: string}
     */
    public static function inspectToken(string $token): array
    {
        $settings = App::i()->settings();
        $appId = trim((string) $settings->get('meta_app_id', ''));
        $appSecret = trim((string) $settings->get('meta_app_secret', ''));

        $empty = [
            'ok' => false, 'waba_ids' => [], 'business_ids' => [],
            'expires_at' => 0, 'scopes' => [], 'message' => '',
        ];

        if ($token === '' || $appId === '' || $appSecret === '') {
            return array_merge($empty, ['message' => 'Missing token or app credentials.']);
        }

        $result = MetaGraph::get('debug_token', [
            'query' => [
                'input_token'  => $token,
                // The app access token is literally "id|secret"; it is never
                // sent anywhere but here.
                'access_token' => $appId . '|' . $appSecret,
            ],
        ]);

        $data = $result['json']['data'] ?? null;

        if (!$result['ok'] || !is_array($data)) {
            return array_merge($empty, ['message' => MetaGraph::explain($result)]);
        }

        $wabaIds = [];
        $businessIds = [];
        $scopes = [];

        foreach ($data['granular_scopes'] ?? [] as $scope) {
            if (!is_array($scope)) {
                continue;
            }

            $name = (string) ($scope['scope'] ?? '');
            $scopes[] = $name;
            $targets = array_map('strval', $scope['target_ids'] ?? []);

            if ($name === 'whatsapp_business_management' || $name === 'whatsapp_business_messaging') {
                $wabaIds = array_merge($wabaIds, $targets);
            }

            if ($name === 'business_management') {
                $businessIds = array_merge($businessIds, $targets);
            }
        }

        return [
            'ok'           => (bool) ($data['is_valid'] ?? false),
            'waba_ids'     => array_values(array_unique($wabaIds)),
            'business_ids' => array_values(array_unique($businessIds)),
            'expires_at'   => (int) ($data['expires_at'] ?? 0),
            'scopes'       => array_values(array_unique($scopes)),
            'message'      => ($data['is_valid'] ?? false) ? 'Token is valid.' : 'Meta reports this token as invalid.',
        ];
    }

    /* ------------------------------------------------------------ Connect */

    /**
     * Complete a signup: exchange, store, subscribe, sync, optionally register.
     *
     * Each step reports its own outcome instead of one boolean, because a
     * connection that stored a token but failed to subscribe looks identical to
     * a working one until the first webhook does not arrive.
     *
     * @return array{ok: bool, account_id: int|null, steps: array<int, array{step: string, ok: bool, detail: string}>, message: string}
     */
    public static function connect(
        string $code,
        ?string $wabaId = null,
        ?string $phoneNumberId = null,
        ?int $ownerUserId = null,
        ?string $pin = null
    ): array {
        $steps = [];
        $step = static function (string $name, bool $ok, string $detail) use (&$steps): void {
            $steps[] = ['step' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        $exchange = self::exchangeCode($code);
        $step('Exchange authorisation code', $exchange['ok'], $exchange['message']);

        if (!$exchange['ok']) {
            return ['ok' => false, 'account_id' => null, 'steps' => $steps, 'message' => $exchange['message']];
        }

        $token = (string) $exchange['token'];
        $inspect = self::inspectToken($token);

        $step(
            'Inspect token',
            $inspect['ok'],
            $inspect['ok']
                ? count($inspect['waba_ids']) . ' WhatsApp account(s) granted'
                : $inspect['message']
        );

        $wabaId = trim((string) $wabaId);

        if ($wabaId === '') {
            $wabaId = (string) ($inspect['waba_ids'][0] ?? '');
        }

        if ($wabaId === '') {
            $message = 'Meta granted no WhatsApp Business Account. The signup dialog was probably '
                     . 'closed before a number was chosen — start again and complete every step.';
            $step('Identify the WhatsApp account', false, $message);

            return ['ok' => false, 'account_id' => null, 'steps' => $steps, 'message' => $message];
        }

        $step('Identify the WhatsApp account', true, $wabaId);

        $profile = self::accountProfile($token, $wabaId);

        try {
            $accountId = WabaAccountService::save([
                'owner_user_id'        => $ownerUserId,
                'name'                 => (string) ($profile['name'] ?? 'WhatsApp Business Account'),
                'waba_id'              => $wabaId,
                'business_id'          => (string) ($inspect['business_ids'][0] ?? '') ?: null,
                'access_token'         => $token,
                'token_type'           => 'exchanged',
                'token_expires_at'     => $inspect['expires_at'] > 0
                    ? gmdate('Y-m-d H:i:s', $inspect['expires_at'])
                    : null,
                'app_id'               => trim((string) App::i()->settings()->get('meta_app_id', '')) ?: null,
                'currency'             => (string) ($profile['currency'] ?? 'INR'),
                'webhook_verify_token' => self::verifyToken(),
                'status'               => 'active',
            ]);
        } catch (\Throwable $e) {
            $step('Store the account', false, $e->getMessage());

            return ['ok' => false, 'account_id' => null, 'steps' => $steps, 'message' => $e->getMessage()];
        }

        $step('Store the account (token encrypted)', true, 'Account #' . $accountId);

        $subscribe = WabaAccountService::subscribeWebhook($accountId);
        $step('Subscribe to webhooks', $subscribe['ok'], $subscribe['message']);

        $sync = WabaAccountService::syncPhoneNumbers($accountId);
        $step('Sync phone numbers', $sync['ok'], $sync['message']);

        if ($phoneNumberId !== null && $phoneNumberId !== '') {
            self::preferPhone($accountId, $phoneNumberId);
        }

        if ($pin !== null && $pin !== '') {
            $target = $phoneNumberId ?: WabaAccountService::phoneNumberId(WabaAccountService::find($accountId));

            if ($target !== '') {
                $register = WabaAccountService::registerPhone($accountId, $target, $pin);
                $step('Register the number with the Cloud API', $register['ok'], $register['message']);
            }
        } else {
            $step(
                'Register the number with the Cloud API',
                false,
                'Skipped — no two-step PIN was given. Until this is done every send fails with 133010.'
            );
        }

        // Templates created in Meta's UI become usable here immediately.
        $account = WabaAccountService::find($accountId);

        if ($account !== null) {
            $templates = WaTemplateService::syncAll($account);
            $step('Sync templates', $templates['ok'], $templates['message']);
        }

        $failed = array_values(array_filter($steps, static fn (array $s): bool => !$s['ok']));

        Logger::info('Embedded Signup completed', [
            'account_id' => $accountId,
            'waba_id'    => $wabaId,
            'failed'     => count($failed),
        ], 'meta');

        return [
            'ok'         => $failed === [],
            'account_id' => $accountId,
            'steps'      => $steps,
            'message'    => $failed === []
                ? 'WhatsApp connected and ready.'
                : 'Connected, but ' . count($failed) . ' step(s) need attention.',
        ];
    }

    /**
     * Connect using a System User token pasted by hand.
     *
     * Embedded Signup needs an approved Meta app; a business that already has a
     * permanent token should not have to wait for app review to use this
     * product, so the manual path is a first-class citizen rather than a
     * fallback.
     */
    public static function connectWithToken(
        string $token,
        ?string $wabaId = null,
        ?int $ownerUserId = null,
        ?string $appSecret = null
    ): array {
        $steps = [];
        $step = static function (string $name, bool $ok, string $detail) use (&$steps): void {
            $steps[] = ['step' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        $token = trim($token);

        if ($token === '') {
            return ['ok' => false, 'account_id' => null, 'steps' => [], 'message' => 'Paste the access token first.'];
        }

        $wabaId = trim((string) $wabaId);
        $inspect = self::inspectToken($token);

        // debug_token needs the app credentials. Without them the token can
        // still work perfectly — it just cannot be inspected, so a missing
        // inspection is reported, not treated as a failure.
        $step(
            'Inspect token',
            $inspect['ok'],
            $inspect['ok'] ? 'Valid' : ($inspect['message'] ?: 'Could not inspect — App ID/Secret not set.')
        );

        if ($wabaId === '') {
            $wabaId = (string) ($inspect['waba_ids'][0] ?? '');
        }

        if ($wabaId === '') {
            $message = 'Enter the WhatsApp Business Account ID — it could not be discovered from this token.';
            $step('Identify the WhatsApp account', false, $message);

            return ['ok' => false, 'account_id' => null, 'steps' => $steps, 'message' => $message];
        }

        $profile = self::accountProfile($token, $wabaId);
        $step('Read the account', $profile !== [], $profile === [] ? 'Meta rejected the token for this WABA.' : (string) ($profile['name'] ?? $wabaId));

        if ($profile === []) {
            return [
                'ok' => false, 'account_id' => null, 'steps' => $steps,
                'message' => 'Meta rejected this token for that WhatsApp Business Account.',
            ];
        }

        $accountId = WabaAccountService::save([
            'owner_user_id'        => $ownerUserId,
            'name'                 => (string) ($profile['name'] ?? 'WhatsApp Business Account'),
            'waba_id'              => $wabaId,
            'access_token'         => $token,
            'app_secret'           => $appSecret !== null ? trim($appSecret) : '',
            'token_type'           => 'system_user',
            'currency'             => (string) ($profile['currency'] ?? 'INR'),
            'webhook_verify_token' => self::verifyToken(),
            'status'               => 'active',
        ]);

        $step('Store the account (token encrypted)', true, 'Account #' . $accountId);

        $subscribe = WabaAccountService::subscribeWebhook($accountId);
        $step('Subscribe to webhooks', $subscribe['ok'], $subscribe['message']);

        $sync = WabaAccountService::syncPhoneNumbers($accountId);
        $step('Sync phone numbers', $sync['ok'], $sync['message']);

        $account = WabaAccountService::find($accountId);

        if ($account !== null) {
            $templates = WaTemplateService::syncAll($account);
            $step('Sync templates', $templates['ok'], $templates['message']);
        }

        $failed = array_values(array_filter($steps, static fn (array $s): bool => !$s['ok']));

        return [
            'ok'         => $failed === [],
            'account_id' => $accountId,
            'steps'      => $steps,
            'message'    => $failed === [] ? 'WhatsApp connected.' : 'Connected, with ' . count($failed) . ' warning(s).',
        ];
    }

    /* --------------------------------------------------------------- Utils */

    /** Name, currency and timezone of a WABA. Empty array means Meta refused. */
    public static function accountProfile(string $token, string $wabaId): array
    {
        $result = MetaGraph::get(rawurlencode($wabaId), [
            'token' => $token,
            'query' => ['fields' => 'id,name,currency,timezone_id,account_review_status,message_template_namespace'],
        ]);

        return $result['ok'] && is_array($result['json']) ? $result['json'] : [];
    }

    private static function preferPhone(int $accountId, string $phoneNumberId): void
    {
        try {
            $db = App::i()->db();
            $db->update('waba_phone_numbers', ['is_default' => 0], 'waba_account_id = :aid', ['aid' => $accountId]);
            $db->update(
                'waba_phone_numbers',
                ['is_default' => 1],
                'waba_account_id = :aid AND phone_number_id = :pid',
                ['aid' => $accountId, 'pid' => $phoneNumberId]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * The verify token Meta echoes during the webhook handshake. One per
     * install, generated once and reused, so an operator never has to invent
     * one and never has to remember it.
     */
    public static function verifyToken(): string
    {
        $settings = App::i()->settings();
        $existing = trim((string) $settings->get('meta_webhook_verify_token', ''));

        if ($existing !== '') {
            return $existing;
        }

        $token = 'kr_' . Crypto::randomToken(16);
        $settings->set('meta_webhook_verify_token', $token, false, 'meta');

        // The old single-account setting is what the webhook endpoint still
        // checks, so keep the two in step rather than having two truths.
        if (trim((string) $settings->get('wa_cloud_verify_token', '')) === '') {
            $settings->set('wa_cloud_verify_token', $token, false, 'whatsapp');
        }

        return $token;
    }

    /** Where Meta should send webhooks. Shown so it can be pasted, or set for the customer. */
    public static function callbackUrl(): string
    {
        return App::i()->url('/api/wa_webhook.php');
    }
}
