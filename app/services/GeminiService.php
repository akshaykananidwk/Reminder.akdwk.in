<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Google Gemini natural-language parser.
 *
 * Cost control is a first-class concern: the system prompt is deliberately
 * short, output is capped and JSON-only, identical messages are cached for
 * 24 hours, and every call is metered against the user's monthly plan quota.
 */
class GeminiService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * Parse free-form text into the structured reminder envelope.
     *
     * @return array{ok: bool, data: array, source: string, error: string|null}
     */
    public static function parse(string $text, array $user, array $options = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['ok' => false, 'data' => self::emptyEnvelope(), 'source' => 'none', 'error' => 'Empty message'];
        }

        $settings = App::i()->settings();
        $userId = (int) ($user['id'] ?? 0);

        // 1. Hard stops before spending a single token.
        if (!$settings->bool('gemini_enabled', true) || self::apiKey() === '') {
            return self::fallback($text, $user, 'ai_disabled');
        }

        if (!PlanService::withinAiQuota($userId)) {
            self::notifyQuota($user);

            return self::fallback($text, $user, 'quota_exceeded');
        }

        if (!self::withinGlobalBudget()) {
            Logger::warn('Global AI token budget reached — using fallback parser', [], 'ai');

            return self::fallback($text, $user, 'budget_exceeded');
        }

        // 2. Cache identical messages (same text + same day + same language).
        $cacheKey = hash('sha256', $text . '|' . ($user['language'] ?? 'gu') . '|' . date('Y-m-d'));
        $cached = self::fromCache($cacheKey);

        if ($cached !== null) {
            self::logUsage($userId, 0, 0, 0, true, true, null);

            return ['ok' => true, 'data' => $cached, 'source' => 'cache', 'error' => null];
        }

        // 3. Call the model.
        $model = (string) $settings->get('gemini_model', 'gemini-2.0-flash');
        $payload = self::buildPayload($text, $user, $options);
        $started = microtime(true);

        $response = HttpClient::postJson(
            sprintf(self::ENDPOINT, rawurlencode($model)),
            $payload,
            ['x-goog-api-key' => self::apiKey()],
            (int) ($options['timeout'] ?? 25)
        );

        $latency = (int) round((microtime(true) - $started) * 1000);

        if (!$response['ok'] || !is_array($response['json'])) {
            $error = 'Gemini HTTP ' . $response['status'] . ' ' . mb_substr($response['body'], 0, 300);
            Logger::warn('Gemini call failed', ['error' => $error], 'ai');
            self::logUsage($userId, 0, 0, $latency, false, false, $error);

            // Try the rotation key once before giving up.
            $secondary = (string) $settings->get('gemini_api_key_2', '');

            if ($secondary !== '' && empty($options['_retried'])) {
                $options['_retried'] = true;
                $options['_use_key'] = $secondary;

                return self::parseWithKey($text, $user, $options, $secondary, $model, $payload);
            }

            return self::fallback($text, $user, 'api_error');
        }

        return self::handleModelResponse($response, $text, $user, $model, $latency, $cacheKey);
    }

    private static function parseWithKey(string $text, array $user, array $options, string $key, string $model, array $payload): array
    {
        $started = microtime(true);

        $response = HttpClient::postJson(
            sprintf(self::ENDPOINT, rawurlencode($model)),
            $payload,
            ['x-goog-api-key' => $key],
            25
        );

        $latency = (int) round((microtime(true) - $started) * 1000);

        if (!$response['ok'] || !is_array($response['json'])) {
            self::logUsage((int) ($user['id'] ?? 0), 0, 0, $latency, false, false, 'rotation key failed');

            return self::fallback($text, $user, 'api_error');
        }

        $cacheKey = hash('sha256', $text . '|' . ($user['language'] ?? 'gu') . '|' . date('Y-m-d'));

        return self::handleModelResponse($response, $text, $user, $model, $latency, $cacheKey);
    }

    private static function handleModelResponse(array $response, string $text, array $user, string $model, int $latency, string $cacheKey): array
    {
        $json = $response['json'];
        $userId = (int) ($user['id'] ?? 0);

        $promptTokens = (int) ($json['usageMetadata']['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($json['usageMetadata']['candidatesTokenCount'] ?? 0);

        $raw = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            $raw .= (string) ($part['text'] ?? '');
        }

        $decoded = self::decodeJson($raw);

        if ($decoded === null) {
            self::logUsage($userId, $promptTokens, $completionTokens, $latency, false, false, 'Unparseable model output');
            Logger::warn('Gemini returned unparseable JSON', ['raw' => mb_substr($raw, 0, 500)], 'ai');

            return self::fallback($text, $user, 'bad_json');
        }

        $envelope = self::normalise($decoded, $user);

        self::logUsage($userId, $promptTokens, $completionTokens, $latency, true, false, null, $model);
        self::store($cacheKey, $envelope);

        // Low confidence is treated as "not sure enough" — merge with the regex
        // parser so the user still gets a usable reminder.
        $confidence = (float) ($envelope['items'][0]['confidence'] ?? 1.0);

        if ($envelope['intent'] === 'create' && $confidence < 0.5) {
            $fb = FallbackParser::parse($text, $user);

            if (!empty($fb['items'][0]['due_at'])) {
                $envelope['items'][0]['due_at'] = $fb['items'][0]['due_at'];
                $envelope['items'][0]['confidence'] = 0.5;
            }
        }

        return ['ok' => true, 'data' => $envelope, 'source' => 'gemini', 'error' => null];
    }

    /* ---------------------------------------------------------- Prompt ---- */

    private static function buildPayload(string $text, array $user, array $options): array
    {
        $settings = App::i()->settings();
        $tz = (string) ($user['timezone'] ?? 'Asia/Kolkata');
        $lang = (string) ($user['language'] ?? 'gu');

        try {
            $now = new \DateTime('now', new \DateTimeZone($tz));
        } catch (\Throwable) {
            $now = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
        }

        $defaultTime = (string) ($options['default_time'] ?? '09:00');
        $categories = implode(', ', $options['categories'] ?? ['work', 'payment', 'personal', 'health', 'meeting', 'bill', 'birthday', 'shop', 'family', 'other']);

        $custom = trim((string) $settings->get('ai_prompt_template', ''));
        $system = $custom !== '' ? $custom : self::defaultSystemPrompt();

        $system = strtr($system, [
            '{now}'           => $now->format('c'),
            '{weekday}'       => $now->format('l'),
            '{timezone}'      => $tz,
            '{language}'      => $lang,
            '{default_time}'  => $defaultTime,
            '{categories}'    => $categories,
        ]);

        return [
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $text]],
                ],
            ],
            'generationConfig' => [
                'temperature'      => (float) $settings->get('gemini_temperature', 0.2),
                'maxOutputTokens'  => (int) $settings->get('gemini_max_tokens', 1024),
                'responseMimeType' => 'application/json',
                'topP'             => 0.9,
            ],
            'safetySettings' => [],
        ];
    }

    /**
     * Kept intentionally compact — a long prompt is the number one cause of
     * runaway token cost on this kind of product.
     */
    private static function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
You convert Gujarati/Hindi/English messages into reminder JSON for an Indian user.

Context: now = {now} ({weekday}), timezone {timezone}, user language {language}, default time {default_time}, categories: {categories}.

Rules:
- Resolve every relative date against `now`. Never invent a year.
- No time given -> use the default time. Parsed time already past -> roll to the next sensible occurrence.
- Several tasks in one message -> several items.
- Genuinely ambiguous -> needs_confirmation true and ONE short question in the user language.
- Amounts: "5000", "5 હજાર", "पाँच हज़ार" -> numeric amount, currency INR.
- Repetition words (દરરોજ/रोज/daily, દર સોમવારે/हर सोमवार/every Monday, દર મહિને 5 તારીખે) -> recurrence.
- Output JSON only, no markdown fences, matching exactly:
{"intent":"create|list|complete|snooze|cancel|update|summary|payment|note|help|unknown","language":"gu|hi|en","needs_confirmation":false,"question":null,"items":[{"title":"","description":"","type":"task|payment|call|meeting|medicine|birthday|bill|note|other","due_at":"ISO8601 with offset","all_day":false,"recurrence":{"freq":"none|daily|weekly|monthly|yearly","interval":1,"by_day":[],"by_month_day":null,"until":null,"count":null},"priority":"low|normal|high|urgent","call_reminder":true,"advance_alerts_min":[],"snooze_default_min":5,"person":{"name":null,"phone":null},"amount":null,"currency":"INR","location":null,"tags":[],"confidence":0.0}],"reference":{"short_code":null,"query":null},"reply_text":"short confirmation in the user language"}
PROMPT;
    }

    /* -------------------------------------------------------- Vision ------ */

    /**
     * Bill photo -> payment reminder. Downloads the image and sends it inline.
     */
    public static function parseImage(string $imageUrl, array $user): array
    {
        $settings = App::i()->settings();

        if (!$settings->bool('gemini_enabled', true) || self::apiKey() === '') {
            return ['ok' => false, 'data' => self::emptyEnvelope(), 'source' => 'none', 'error' => 'AI disabled'];
        }

        if (!PlanService::withinAiQuota((int) ($user['id'] ?? 0))) {
            return ['ok' => false, 'data' => self::emptyEnvelope(), 'source' => 'none', 'error' => 'Quota exceeded'];
        }

        $download = HttpClient::get($imageUrl, ['timeout' => 20]);

        if (!$download['ok'] || $download['body'] === '') {
            return ['ok' => false, 'data' => self::emptyEnvelope(), 'source' => 'none', 'error' => 'Image download failed'];
        }

        $mime = str_starts_with($download['body'], "\x89PNG") ? 'image/png' : 'image/jpeg';
        $model = (string) $settings->get('gemini_model', 'gemini-2.0-flash');
        $payload = self::buildPayload('Extract the bill/invoice: party name, total amount and due date. Create a payment reminder.', $user, []);

        $payload['contents'][0]['parts'][] = [
            'inline_data' => ['mime_type' => $mime, 'data' => base64_encode($download['body'])],
        ];

        $started = microtime(true);
        $response = HttpClient::postJson(sprintf(self::ENDPOINT, rawurlencode($model)), $payload, ['x-goog-api-key' => self::apiKey()], 40);
        $latency = (int) round((microtime(true) - $started) * 1000);

        if (!$response['ok'] || !is_array($response['json'])) {
            self::logUsage((int) ($user['id'] ?? 0), 0, 0, $latency, false, false, 'vision failed');

            return ['ok' => false, 'data' => self::emptyEnvelope(), 'source' => 'none', 'error' => 'Vision call failed'];
        }

        return self::handleModelResponse($response, '[image]', $user, $model, $latency, hash('sha256', $imageUrl));
    }

    /* ------------------------------------------------------- Normalising -- */

    public static function normalise(array $raw, array $user): array
    {
        $envelope = self::emptyEnvelope();

        $envelope['intent'] = self::enum($raw['intent'] ?? 'unknown',
            ['create', 'list', 'complete', 'snooze', 'cancel', 'update', 'summary', 'payment', 'note', 'help', 'unknown'], 'unknown');
        $envelope['language'] = self::enum($raw['language'] ?? ($user['language'] ?? 'gu'), ['gu', 'hi', 'en'], 'gu');
        $envelope['needs_confirmation'] = (bool) ($raw['needs_confirmation'] ?? false);
        $envelope['question'] = isset($raw['question']) && is_string($raw['question']) ? $raw['question'] : null;
        $envelope['reply_text'] = isset($raw['reply_text']) && is_string($raw['reply_text']) ? $raw['reply_text'] : '';

        $envelope['reference'] = [
            'short_code' => isset($raw['reference']['short_code']) && is_string($raw['reference']['short_code'])
                ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $raw['reference']['short_code']) ?? '')
                : null,
            'query' => $raw['reference']['query'] ?? null,
        ];

        foreach ($raw['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $recurrence = is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [];

            $envelope['items'][] = [
                'title'        => mb_substr($title, 0, 255),
                'description'  => mb_substr((string) ($item['description'] ?? ''), 0, 2000),
                'type'         => self::enum($item['type'] ?? 'task',
                    ['task', 'payment', 'call', 'meeting', 'medicine', 'birthday', 'bill', 'note', 'other'], 'task'),
                'due_at'       => self::isoOrNull($item['due_at'] ?? null),
                'all_day'      => (bool) ($item['all_day'] ?? false),
                'recurrence'   => [
                    'freq'         => self::enum($recurrence['freq'] ?? 'none', ['none', 'daily', 'weekly', 'monthly', 'yearly'], 'none'),
                    'interval'     => max(1, (int) ($recurrence['interval'] ?? 1)),
                    'by_day'       => array_values(array_filter(array_map(
                        static fn ($d) => strtoupper(substr((string) $d, 0, 2)),
                        is_array($recurrence['by_day'] ?? null) ? $recurrence['by_day'] : []
                    ), static fn ($d) => in_array($d, ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], true))),
                    'by_month_day' => isset($recurrence['by_month_day']) && $recurrence['by_month_day'] !== null
                        ? max(1, min(31, (int) $recurrence['by_month_day'])) : null,
                    'until'        => self::isoOrNull($recurrence['until'] ?? null),
                    'count'        => isset($recurrence['count']) && $recurrence['count'] !== null ? max(1, (int) $recurrence['count']) : null,
                ],
                'priority'     => self::enum($item['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'], 'normal'),
                'call_reminder' => (bool) ($item['call_reminder'] ?? true),
                'advance_alerts_min' => array_values(array_filter(array_map(
                    static fn ($m) => (int) $m,
                    is_array($item['advance_alerts_min'] ?? null) ? $item['advance_alerts_min'] : []
                ), static fn ($m) => $m > 0 && $m <= 20160)),
                'snooze_default_min' => max(1, min(1440, (int) ($item['snooze_default_min'] ?? 5))),
                'person'       => [
                    'name'  => isset($item['person']['name']) && is_string($item['person']['name']) ? mb_substr($item['person']['name'], 0, 120) : null,
                    'phone' => isset($item['person']['phone']) ? normalize_phone((string) $item['person']['phone']) : null,
                ],
                'amount'       => isset($item['amount']) && is_numeric($item['amount']) ? (float) $item['amount'] : null,
                'currency'     => strtoupper(mb_substr((string) ($item['currency'] ?? 'INR'), 0, 3)),
                'location'     => isset($item['location']) && is_string($item['location']) ? mb_substr($item['location'], 0, 255) : null,
                'tags'         => array_values(array_filter(array_map(
                    static fn ($tag) => mb_substr(trim((string) $tag), 0, 60),
                    is_array($item['tags'] ?? null) ? $item['tags'] : []
                ))),
                'confidence'   => max(0.0, min(1.0, (float) ($item['confidence'] ?? 0.8))),
            ];
        }

        return $envelope;
    }

    public static function emptyEnvelope(): array
    {
        return [
            'intent'             => 'unknown',
            'language'           => 'gu',
            'needs_confirmation' => false,
            'question'           => null,
            'items'              => [],
            'reference'          => ['short_code' => null, 'query' => null],
            'reply_text'         => '',
        ];
    }

    /* ------------------------------------------------------------ Helpers - */

    private static function fallback(string $text, array $user, string $reason): array
    {
        if (!App::i()->settings()->bool('ai_fallback_enabled', true)) {
            return ['ok' => false, 'data' => self::emptyEnvelope(), 'source' => 'none', 'error' => $reason];
        }

        $data = FallbackParser::parse($text, $user);

        return ['ok' => true, 'data' => $data, 'source' => 'fallback:' . $reason, 'error' => null];
    }

    private static function notifyQuota(array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId === 0) {
            return;
        }

        // At most one quota warning per user per day.
        $key = 'quota_notice_' . $userId . '_' . date('Y-m-d');

        if (!\App\Core\RateLimiter::attempt($key, 1, 86400)) {
            return;
        }

        WhatsAppService::queueTemplate('quota_exceeded', $user, [
            'name' => $user['name'] ?? '',
            'url'  => App::i()->url('/client/billing'),
        ], 7);
    }

    private static function apiKey(): string
    {
        return (string) App::i()->settings()->get('gemini_api_key', '');
    }

    private static function decodeJson(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Strip markdown fences the model sometimes adds despite instructions.
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?? $raw;

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: grab the outermost JSON object.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private static function enum(mixed $value, array $allowed, string $default): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function isoOrNull(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : date('c', $ts);
    }

    private static function logUsage(
        int $userId,
        int $promptTokens,
        int $completionTokens,
        int $latency,
        bool $success,
        bool $cached,
        ?string $error,
        string $model = ''
    ): void {
        $settings = App::i()->settings();
        $model = $model !== '' ? $model : (string) $settings->get('gemini_model', 'gemini-2.0-flash');

        $cost = ($promptTokens / 1000) * (float) $settings->get('ai_cost_per_1k_input', 0.000075)
              + ($completionTokens / 1000) * (float) $settings->get('ai_cost_per_1k_output', 0.0003);

        try {
            App::i()->db()->insert('ai_logs', [
                'user_id'           => $userId > 0 ? $userId : null,
                'model'             => $model,
                'purpose'           => 'parse',
                'prompt_tokens'     => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens'      => $promptTokens + $completionTokens,
                'cost'              => round($cost, 6),
                'latency_ms'        => $latency,
                'success'           => $success ? 1 : 0,
                'cached'            => $cached ? 1 : 0,
                'error'             => $error === null ? null : mb_substr($error, 0, 500),
                'created_at'        => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Failed to write AI log', ['error' => $e->getMessage()], 'ai');
        }
    }

    private static function withinGlobalBudget(): bool
    {
        $budget = App::i()->settings()->int('ai_monthly_budget_tokens', 0);

        if ($budget <= 0) {
            return true;
        }

        $used = (int) App::i()->db()->value(
            'SELECT COALESCE(SUM(total_tokens), 0) FROM ai_logs WHERE created_at >= ?',
            [date('Y-m-01 00:00:00')],
            0
        );

        return $used < $budget;
    }

    private static function fromCache(string $key): ?array
    {
        $hours = App::i()->settings()->int('ai_cache_hours', 24);

        if ($hours <= 0) {
            return null;
        }

        $row = App::i()->db()->one(
            'SELECT response FROM ai_cache WHERE cache_key = ? AND expires_at > ?',
            [$key, now_utc()]
        );

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['response'], true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function store(string $key, array $envelope): void
    {
        $hours = App::i()->settings()->int('ai_cache_hours', 24);

        if ($hours <= 0) {
            return;
        }

        try {
            App::i()->db()->upsert('ai_cache', [
                'cache_key'  => $key,
                'response'   => json_encode($envelope, JSON_UNESCAPED_UNICODE),
                'expires_at' => date('Y-m-d H:i:s', time() + ($hours * 3600)),
                'created_at' => now_utc(),
            ], ['response', 'expires_at']);
        } catch (\Throwable) {
            // Cache is an optimisation, never a hard dependency.
        }
    }

    /** Used by the installer and the admin "test parse" button. */
    public static function testConnection(string $apiKey, string $model = 'gemini-2.0-flash'): array
    {
        $response = HttpClient::postJson(
            sprintf(self::ENDPOINT, rawurlencode($model)),
            [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with {"ok":true} only.']]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 32, 'responseMimeType' => 'application/json'],
            ],
            ['x-goog-api-key' => $apiKey],
            20
        );

        $text = '';
        foreach ($response['json']['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        return [
            'ok'      => $response['ok'] && $text !== '',
            'message' => $response['ok']
                ? 'Gemini responded: ' . mb_substr(trim($text), 0, 120)
                : 'HTTP ' . $response['status'] . ' — ' . mb_substr($response['body'] ?: (string) $response['error'], 0, 300),
        ];
    }
}
