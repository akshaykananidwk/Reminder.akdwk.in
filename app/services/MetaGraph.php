<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * The one place this application talks to graph.facebook.com.
 *
 * Everything Meta-related — sending, media, templates, Embedded Signup, phone
 * registration, webhook subscription — goes through `call()`. That buys three
 * things that are painful to retrofit later:
 *
 *   1. Every request is logged to `wa_api_log` with the access token stripped,
 *      so a support question ("did the message actually leave?") is answered
 *      from the database instead of from guesswork.
 *   2. Retries and backoff live in one place, with the rule that matters:
 *      the Cloud API has no idempotency key, so a POST /messages that may have
 *      reached Meta is NEVER retried. Retrying a read timeout is how people end
 *      up sending the same reminder three times.
 *   3. The Graph version is resolved once, from settings, and validated — a
 *      typo cannot silently produce a URL that 404s forever.
 */
class MetaGraph
{
    public const HOST = 'https://graph.facebook.com';
    public const DEFAULT_VERSION = 'v23.0';

    /** Total attempts, including the first. */
    private const MAX_ATTEMPTS = 3;

    /** Meta error codes that mean "try again shortly", not "this is wrong". */
    private const TRANSIENT_CODES = [
        1,      // API Unknown — Meta's own catch-all, usually transient
        2,      // API Service — temporary Graph outage
        4,      // Application request limit reached
        80007,  // Rate limit hit
        130429, // Cloud API message throughput limit
        131000, // Something went wrong (Meta side)
        131048, // Spam rate limit hit
        613,    // Calls to this API have exceeded the rate limit
    ];

    /** Anything with one of these keys never reaches the log table in the clear. */
    private const SECRET_KEYS = [
        'access_token', 'app_secret', 'client_secret', 'secret', 'token',
        'authorization', 'code', 'input_token', 'fb_exchange_token',
        'webhook_verify_token', 'verify_token', 'pin', 'password',
    ];

    /* ------------------------------------------------------------ Version */

    public static function version(): string
    {
        try {
            $configured = trim((string) App::i()->settings()->get('meta_graph_version', ''));
        } catch (\Throwable) {
            $configured = '';
        }

        if ($configured === '') {
            try {
                $configured = trim((string) App::i()->settings()->get('wa_cloud_api_version', ''));
            } catch (\Throwable) {
                $configured = '';
            }
        }

        return preg_match('/^v\d+\.\d+$/', $configured) === 1 ? $configured : self::DEFAULT_VERSION;
    }

    /**
     * Build a full Graph URL.
     *
     * A path starting with `/` is taken as already versioned (e.g. an OAuth
     * endpoint that must not carry a version); everything else is prefixed with
     * the configured version.
     */
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return self::HOST . '/' . self::version();
        }

        // Already versioned by the caller — do not version it twice.
        if (preg_match('#^v\d+\.\d+/#', $path) === 1) {
            return self::HOST . '/' . $path;
        }

        return self::HOST . '/' . self::version() . '/' . $path;
    }

    /* --------------------------------------------------------------- Call */

    /**
     * @param array{
     *   token?: string, json?: array, form?: array, query?: array,
     *   headers?: array, timeout?: int, idempotent?: bool, retries?: int,
     *   account_id?: int|null, save_to?: string, url?: string
     * } $options
     *
     * @return array{ok: bool, status: int, body: string, json: array|null,
     *               error: string|null, latency_ms: int, code: int|null,
     *               subcode: int|null, message: string|null, attempts: int,
     *               retryable: bool}
     */
    public static function call(string $method, string $path, array $options = []): array
    {
        $method = strtoupper($method);
        $url = (string) ($options['url'] ?? self::url($path));
        $token = (string) ($options['token'] ?? '');
        $timeout = (int) ($options['timeout'] ?? 25);
        $accountId = isset($options['account_id']) ? (int) $options['account_id'] : null;

        // A GET is always safe to repeat. A POST is only safe to repeat when the
        // caller says so — /messages is not, /subscribed_apps is.
        $idempotent = (bool) ($options['idempotent'] ?? ($method === 'GET' || $method === 'DELETE'));
        $maxAttempts = max(1, (int) ($options['retries'] ?? self::MAX_ATTEMPTS));

        $headers = $options['headers'] ?? [];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $request = [
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        foreach (['json', 'form', 'query', 'body', 'save_to'] as $key) {
            if (array_key_exists($key, $options)) {
                $request[$key] = $options[$key];
            }
        }

        $attempt = 0;
        $result = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $result = HttpClient::request($method, $url, $request);
            $result = self::decorate($result);

            if ($result['ok'] || !self::shouldRetry($result, $idempotent) || $attempt >= $maxAttempts) {
                break;
            }

            self::sleepMs(self::backoffMs($attempt, $result));
        }

        /** @var array $result */
        $result['attempts'] = $attempt;

        self::log($method, $url, $path, $request, $result, $accountId);

        if (!$result['ok']) {
            Logger::warn('Graph call failed', [
                'method'   => $method,
                'path'     => $path,
                'status'   => $result['status'],
                'code'     => $result['code'],
                'attempts' => $attempt,
                'message'  => $result['message'] ?? $result['error'],
            ], 'meta');
        }

        return $result;
    }

    public static function get(string $path, array $options = []): array
    {
        return self::call('GET', $path, $options);
    }

    public static function post(string $path, array $options = []): array
    {
        return self::call('POST', $path, $options);
    }

    public static function delete(string $path, array $options = []): array
    {
        return self::call('DELETE', $path, $options);
    }

    /* ------------------------------------------------------------- Errors */

    /** Add Meta's error fields to a raw HttpClient result. */
    private static function decorate(array $result): array
    {
        $error = $result['json']['error'] ?? null;

        $result['code'] = is_array($error) && isset($error['code']) ? (int) $error['code'] : null;
        $result['subcode'] = is_array($error) && isset($error['error_subcode'])
            ? (int) $error['error_subcode']
            : null;
        $result['message'] = self::errorMessage($result['json']);

        // Graph answers 200 with an error body often enough that HTTP status
        // alone is not a success signal.
        if ($error !== null) {
            $result['ok'] = false;
        }

        $result['retryable'] = self::isRetryable($result);

        return $result;
    }

    public static function errorMessage(?array $json): ?string
    {
        $error = $json['error'] ?? null;

        if (!is_array($error)) {
            return null;
        }

        $parts = array_filter([
            (string) ($error['error_user_title'] ?? ''),
            (string) ($error['error_user_msg'] ?? ''),
            (string) ($error['message'] ?? ''),
        ], static fn (string $v): bool => trim($v) !== '');

        return $parts === [] ? 'Unknown Graph API error' : implode(' — ', array_unique($parts));
    }

    /** Is this failure the kind that a later attempt could survive? */
    public static function isRetryable(array $result): bool
    {
        if ($result['ok'] ?? false) {
            return false;
        }

        $status = (int) ($result['status'] ?? 0);
        $code = $result['code'] ?? null;

        if ($code !== null && in_array($code, self::TRANSIENT_CODES, true)) {
            return true;
        }

        // A 4xx other than 429 is our mistake; repeating it changes nothing.
        return $status === 0 || $status === 429 || $status >= 500;
    }

    /**
     * Retry only when it is both worth it and safe.
     *
     * The safety half matters most for POST /messages: cURL reports a timeout
     * identically whether Meta never saw the request or accepted it and was
     * slow to answer. Repeating the second case sends the message twice, and
     * WhatsApp has no idempotency key to protect us. So a non-idempotent call is
     * retried only when the server explicitly told us to come back — 429 or 503
     * — which it cannot do after having accepted the message.
     */
    public static function shouldRetry(array $result, bool $idempotent): bool
    {
        if (!self::isRetryable($result)) {
            return false;
        }

        if ($idempotent) {
            return true;
        }

        $status = (int) ($result['status'] ?? 0);

        return $status === 429 || $status === 503;
    }

    /** Exponential backoff, honouring Retry-After semantics where we have them. */
    public static function backoffMs(int $attempt, array $result = []): int
    {
        $base = (int) (500 * (2 ** max(0, $attempt - 1)));   // 500, 1000, 2000…
        $base = min($base, 8000);

        // Rate limits deserve more room than a generic 500.
        if ((int) ($result['status'] ?? 0) === 429) {
            $base = max($base, 2000);
        }

        // Jitter, so a burst of workers does not retry in lockstep.
        return $base + random_int(0, 250);
    }

    private static function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /* -------------------------------------------------------- Redaction */

    /**
     * Strip anything that could authenticate as the customer.
     *
     * Two passes, because tokens hide in two shapes: a key we recognise
     * (`access_token`), and a value that simply looks like a Meta token
     * wherever it happens to sit (a URL query string, an error message that
     * echoes the request).
     */
    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)
                    ? '[redacted]'
                    : self::redact($item);
            }

            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        // Meta user/system tokens all start EAA; app secret proofs and bearer
        // headers are caught by the patterns around them.
        $value = (string) preg_replace('/EAA[A-Za-z0-9_\-]{20,}/', '[redacted]', $value);
        $value = (string) preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $value);
        $value = (string) preg_replace('/(access_token=)[^&\s"]+/i', '$1[redacted]', $value);

        return $value;
    }

    /* -------------------------------------------------------------- Log */

    private static function log(
        string $method,
        string $url,
        string $path,
        array $request,
        array $result,
        ?int $accountId
    ): void {
        try {
            $db = App::i()->db();

            if (!$db->tableExists('wa_api_log')) {
                return;
            }

            $safeRequest = self::redact([
                'query'   => $request['query'] ?? null,
                'body'    => $request['json'] ?? $request['form'] ?? null,
                'headers' => array_keys($request['headers'] ?? []),
                'attempts'=> $result['attempts'] ?? 1,
            ]);

            // A media download can be megabytes; a template list can be huge.
            // Store enough to debug, never enough to fill the disk.
            $response = $result['json'] ?? ['raw' => mb_substr((string) $result['body'], 0, 2000)];

            $db->insert('wa_api_log', [
                'waba_account_id' => $accountId,
                'method'          => mb_substr($method, 0, 8),
                'endpoint'        => mb_substr(self::redact(self::stripHost($url, $path)), 0, 255),
                'request'         => self::encode($safeRequest, 8000),
                'http_code'       => (int) ($result['status'] ?? 0),
                'response'        => self::encode(self::redact($response), 16000),
                'error_code'      => $result['code'] ?? null,
                'latency_ms'      => (int) ($result['latency_ms'] ?? 0),
                'created_at'      => now_utc(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never be the reason a message fails to send.
            Logger::warn('wa_api_log write skipped', ['error' => $e->getMessage()], 'meta');
        }
    }

    private static function stripHost(string $url, string $path): string
    {
        $stripped = str_starts_with($url, self::HOST) ? substr($url, strlen(self::HOST)) : $url;

        return $stripped !== '' ? $stripped : ('/' . ltrim($path, '/'));
    }

    /** JSON that always fits the column, and is always valid JSON. */
    private static function encode(mixed $value, int $limit): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_string($json)) {
            return '{}';
        }

        if (strlen($json) <= $limit) {
            return $json;
        }

        // Truncating raw JSON produces something MySQL rejects on a JSON column,
        // so the truncated form is re-wrapped as a valid document.
        return (string) json_encode(
            ['truncated' => true, 'preview' => mb_substr($json, 0, $limit - 64)],
            JSON_UNESCAPED_UNICODE
        );
    }

    /* ------------------------------------------------------------ Advice */

    /**
     * Meta's error catalogue, translated into something an operator can act on.
     * Shared by the sender, the template manager and the signup flow, so the
     * same failure never gets three different explanations.
     */
    public static function explain(array $result): string
    {
        $code = $result['code'] ?? null;
        $subcode = $result['subcode'] ?? null;
        $status = (int) ($result['status'] ?? 0);
        $message = $result['message'] ?? null;

        return match (true) {
            $status === 0 =>
                'Could not reach graph.facebook.com — check outbound HTTPS from the server.',
            $code === 131047 =>
                'Outside the 24-hour customer service window. Only an approved template can be sent here.',
            $code === 131051 =>
                'Meta does not support this message type for this number.',
            $code === 190 && $subcode === 463 =>
                'The access token has expired. Reconnect the WhatsApp account, or issue a System User token that does not expire.',
            $code === 190 =>
                'The access token is invalid or was revoked. Reconnect the account under Admin → WhatsApp.',
            $code === 10, $code === 200, $code === 299 =>
                'The token is missing a permission — whatsapp_business_messaging and whatsapp_business_management are both needed.',
            $code === 100 =>
                'Meta rejected the request as malformed: ' . ((string) $message),
            $code === 131030 =>
                'This number is not on the allowed recipient list. Add it while the app is in development mode.',
            $code === 131026 =>
                'The destination cannot receive the message — the number may not be on WhatsApp.',
            $code === 131031 =>
                'The WhatsApp Business Account has been restricted or disabled by Meta.',
            $code === 133010 =>
                'The phone number is not registered with the Cloud API. Complete registration first.',
            $code === 133005 =>
                'The two-step verification PIN was wrong. Use the PIN set on this number.',
            $code === 132000 =>
                'The template variable count does not match the approved template.',
            $code === 132001 =>
                'That template name and language does not exist, or is not approved yet.',
            $code === 132005 =>
                'A template parameter is too long, or contains a newline or tab where Meta forbids one.',
            $code === 132007 =>
                'The template content violates Meta policy and cannot be sent.',
            $code === 132012, $code === 132015 =>
                'The template is paused or disabled by Meta because of low quality feedback.',
            $code === 130429, $code === 131048, $code === 4, $code === 80007, $code === 613 =>
                'Meta is rate limiting this account. The message stays queued and will be retried.',
            $code === 131056 =>
                'Too many messages to this same number in a short time. It will be retried.',
            $status === 429 =>
                'Rate limited by Meta. The request will be retried automatically.',
            $status >= 500 =>
                'Meta returned a server error. This is on their side and will be retried.',
            $message !== null => $message,
            default => 'HTTP ' . $status,
        };
    }
}
