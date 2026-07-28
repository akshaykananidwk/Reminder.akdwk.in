<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * The internal send API: one method per WhatsApp message type, each returning
 * the same shape, each recorded in `wa_messages` before it leaves.
 *
 * Recording first is deliberate. If the process dies between the Graph call and
 * the database write, a row that says `sending` is recoverable — a status
 * webhook will complete it, or an operator can see it stuck. Writing the row
 * only on success means a message that Meta accepted is invisible to us, which
 * is the worst of the possible failures: the customer was charged and billed,
 * and the dashboard shows nothing.
 *
 * Every method takes the account explicitly so a multi-tenant install can never
 * accidentally send one business's message down another's number.
 */
class MetaMessageService
{
    /* --------------------------------------------------------- Text & media */

    public static function sendText(
        array $account,
        string $to,
        string $body,
        bool $previewUrl = false,
        ?string $replyTo = null,
        ?int $userId = null
    ): array {
        return self::send($account, $to, [
            'type' => 'text',
            'text' => [
                'preview_url' => $previewUrl,
                'body'        => mb_substr($body, 0, 4096),
            ],
        ], ['body' => $body, 'context_wamid' => $replyTo, 'user_id' => $userId]);
    }

    /**
     * Image, video, audio, document or sticker.
     *
     * `$source` is either a Meta media id (from MetaMediaService) or a public
     * https URL. A media id is preferred — it survives our server going down and
     * costs Meta nothing to fetch — so an id is detected rather than demanded.
     */
    public static function sendMedia(
        array $account,
        string $to,
        string $type,
        string $source,
        ?string $caption = null,
        ?string $filename = null,
        ?int $userId = null
    ): array {
        $type = strtolower($type);

        if (!in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            return self::failure('Unsupported media type: ' . $type);
        }

        $isId = ctype_digit($source);
        $media = $isId ? ['id' => $source] : ['link' => $source];

        // Meta rejects a caption on audio and sticker outright.
        if ($caption !== null && $caption !== '' && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = mb_substr($caption, 0, 1024);
        }

        if ($type === 'document' && $filename !== null && $filename !== '') {
            $media['filename'] = mb_substr($filename, 0, 240);
        }

        return self::send($account, $to, [
            'type' => $type,
            $type  => $media,
        ], [
            'body'     => $caption,
            'caption'  => $caption,
            'media_id' => $isId ? $source : null,
            'filename' => $filename,
            'user_id'  => $userId,
        ]);
    }

    public static function sendDocument(
        array $account,
        string $to,
        string $source,
        string $filename,
        ?string $caption = null,
        ?int $userId = null
    ): array {
        return self::sendMedia($account, $to, 'document', $source, $caption, $filename, $userId);
    }

    public static function sendLocation(
        array $account,
        string $to,
        float $latitude,
        float $longitude,
        string $name = '',
        string $address = '',
        ?int $userId = null
    ): array {
        $location = ['latitude' => $latitude, 'longitude' => $longitude];

        if ($name !== '') {
            $location['name'] = mb_substr($name, 0, 190);
        }

        if ($address !== '') {
            $location['address'] = mb_substr($address, 0, 500);
        }

        return self::send($account, $to, [
            'type'     => 'location',
            'location' => $location,
        ], [
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'body'      => trim($name . ' ' . $address),
            'user_id'   => $userId,
        ]);
    }

    /**
     * A contact card. Meta's `contacts` object is deeply nested and rejects a
     * partially-filled one, so the common fields are assembled here and the
     * caller passes plain strings.
     */
    public static function sendContact(
        array $account,
        string $to,
        string $name,
        string $phone,
        array $extra = [],
        ?int $userId = null
    ): array {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = (string) ($parts[0] ?? $name);
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        $contact = [
            'name' => array_filter([
                'formatted_name' => $name !== '' ? $name : $phone,
                'first_name'     => $first,
                'last_name'      => $last,
            ], static fn ($v): bool => $v !== ''),
            'phones' => [[
                'phone' => $phone,
                'type'  => 'CELL',
                'wa_id' => ltrim(normalize_phone($phone), '+'),
            ]],
        ];

        if (!empty($extra['email'])) {
            $contact['emails'] = [['email' => (string) $extra['email'], 'type' => 'WORK']];
        }

        if (!empty($extra['org'])) {
            $contact['org'] = ['company' => (string) $extra['org']];
        }

        return self::send($account, $to, [
            'type'     => 'contacts',
            'contacts' => [$contact],
        ], ['body' => $name . ' ' . $phone, 'user_id' => $userId]);
    }

    /* ------------------------------------------------------------ Templates */

    /**
     * Send an approved template.
     *
     * `$components` is Meta's own component array. TemplateService builds it
     * from named variables so callers never have to count positional {{1}}s,
     * but the raw form is accepted for anything unusual.
     */
    public static function sendTemplate(
        array $account,
        string $to,
        string $templateName,
        string $language = 'en',
        array $components = [],
        ?int $userId = null
    ): array {
        $template = [
            'name'     => $templateName,
            'language' => ['code' => $language],
        ];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return self::send($account, $to, [
            'type'     => 'template',
            'template' => $template,
        ], [
            'template_name'     => $templateName,
            'template_language' => $language,
            'body'              => self::templatePreview($components),
            'user_id'           => $userId,
        ]);
    }

    /**
     * An authentication template carrying a one-time code.
     *
     * Meta requires the code in the body parameter AND again in the button's
     * URL/copy-code parameter; sending only one of the two is accepted at submit
     * time and then fails at send time, which is a miserable thing to debug.
     */
    public static function sendOtp(
        array $account,
        string $to,
        string $templateName,
        string $code,
        string $language = 'en',
        ?int $userId = null
    ): array {
        $components = [
            [
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ],
            [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => '0',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ],
        ];

        return self::sendTemplate($account, $to, $templateName, $language, $components, $userId);
    }

    /* ---------------------------------------------------------- Interactive */

    /**
     * Up to three reply buttons. Only valid inside the 24-hour window.
     *
     * @param array<int, array{id: string, title: string}> $buttons
     */
    public static function sendButtons(
        array $account,
        string $to,
        string $body,
        array $buttons,
        string $header = '',
        string $footer = '',
        ?int $userId = null
    ): array {
        $rows = [];

        foreach (array_slice($buttons, 0, 3) as $button) {
            $rows[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => mb_substr((string) ($button['id'] ?? ''), 0, 256),
                    'title' => mb_substr((string) ($button['title'] ?? ''), 0, 20),
                ],
            ];
        }

        if ($rows === []) {
            return self::failure('At least one button is required.');
        }

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => $rows],
        ];

        if ($header !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];
        }

        if ($footer !== '') {
            $interactive['footer'] = ['text' => mb_substr($footer, 0, 60)];
        }

        return self::send($account, $to, [
            'type'        => 'interactive',
            'interactive' => $interactive,
        ], ['body' => $body, 'interactive_type' => 'button', 'user_id' => $userId]);
    }

    /**
     * A list picker — up to ten rows across up to ten sections.
     *
     * @param array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}> $sections
     */
    public static function sendList(
        array $account,
        string $to,
        string $body,
        string $buttonText,
        array $sections,
        string $header = '',
        string $footer = '',
        ?int $userId = null
    ): array {
        $built = [];

        foreach (array_slice($sections, 0, 10) as $section) {
            $rows = [];

            foreach (array_slice($section['rows'] ?? [], 0, 10) as $row) {
                $entry = [
                    'id'    => mb_substr((string) ($row['id'] ?? ''), 0, 200),
                    'title' => mb_substr((string) ($row['title'] ?? ''), 0, 24),
                ];

                if (!empty($row['description'])) {
                    $entry['description'] = mb_substr((string) $row['description'], 0, 72);
                }

                $rows[] = $entry;
            }

            if ($rows !== []) {
                $built[] = [
                    'title' => mb_substr((string) ($section['title'] ?? ' '), 0, 24),
                    'rows'  => $rows,
                ];
            }
        }

        if ($built === []) {
            return self::failure('At least one list row is required.');
        }

        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => mb_substr($body, 0, 1024)],
            'action' => [
                'button'   => mb_substr($buttonText, 0, 20),
                'sections' => $built,
            ],
        ];

        if ($header !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];
        }

        if ($footer !== '') {
            $interactive['footer'] = ['text' => mb_substr($footer, 0, 60)];
        }

        return self::send($account, $to, [
            'type'        => 'interactive',
            'interactive' => $interactive,
        ], ['body' => $body, 'interactive_type' => 'list', 'user_id' => $userId]);
    }

    /* ----------------------------------------------------------- Read state */

    /** Mark an inbound message read, so the customer sees the blue ticks. */
    public static function markRead(array $account, string $wamid): bool
    {
        $phoneId = WabaAccountService::phoneNumberId($account);

        if ($phoneId === '' || $wamid === '') {
            return false;
        }

        $result = MetaGraph::post(rawurlencode($phoneId) . '/messages', [
            'token'      => WabaAccountService::token($account),
            'account_id' => (int) $account['id'],
            'idempotent' => true,
            'json'       => [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $wamid,
            ],
        ]);

        return (bool) $result['ok'];
    }

    /* ---------------------------------------------------------- The pipeline */

    /**
     * Post one message and record it.
     *
     * @param array $body    the type-specific part of Meta's payload
     * @param array $columns extra columns for the wa_messages row
     *
     * @return array{ok: bool, wamid: string|null, message_row_id: int|null,
     *               error: string|null, code: int|null, status: int, retryable: bool}
     */
    public static function send(array $account, string $to, array $body, array $columns = []): array
    {
        $phoneId = WabaAccountService::phoneNumberId($account);
        $token = WabaAccountService::token($account);

        if ($phoneId === '') {
            return self::failure('No WhatsApp phone number is registered on this account.');
        }

        if ($token === '') {
            return self::failure('No usable access token on this account — reconnect it.');
        }

        $to = ltrim(normalize_phone($to), '+');

        if ($to === '') {
            return self::failure('Invalid destination number.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
        ] + $body;

        // A reply keeps the quoted bubble in the customer's chat, which matters
        // when several reminders are outstanding at once.
        $replyTo = $columns['context_wamid'] ?? null;

        if (is_string($replyTo) && $replyTo !== '') {
            $payload['context'] = ['message_id' => $replyTo];
        }

        $rowId = self::record($account, $phoneId, $to, $body, $columns, $payload);

        $result = MetaGraph::post(rawurlencode($phoneId) . '/messages', [
            'token'      => $token,
            'account_id' => (int) $account['id'],
            // No idempotency key exists for /messages: a retry can duplicate the
            // message, so MetaGraph only repeats it on an explicit 429/503.
            'idempotent' => false,
            'json'       => $payload,
        ]);

        $wamid = $result['json']['messages'][0]['id'] ?? null;
        $ok = $result['ok'] && is_string($wamid) && $wamid !== '';

        self::finish($rowId, $ok, is_string($wamid) ? $wamid : null, $result);

        if (!$ok) {
            Logger::warn('WhatsApp send failed', [
                'account' => $account['id'] ?? null,
                'to'      => mb_substr($to, 0, 6) . '…',
                'type'    => $body['type'] ?? 'text',
                'code'    => $result['code'],
                'error'   => MetaGraph::explain($result),
            ], 'meta');
        }

        return [
            'ok'             => $ok,
            'wamid'          => is_string($wamid) ? $wamid : null,
            'message_row_id' => $rowId,
            'error'          => $ok ? null : MetaGraph::explain($result),
            'code'           => $result['code'],
            'status'         => (int) $result['status'],
            'retryable'      => !$ok && (bool) ($result['retryable'] ?? false),
        ];
    }

    /** Write the outbound row before the wire call, so nothing is ever lost. */
    private static function record(
        array $account,
        string $phoneId,
        string $to,
        array $body,
        array $columns,
        array $payload
    ): ?int {
        try {
            return App::i()->db()->insert('wa_messages', [
                'waba_account_id'   => (int) $account['id'],
                'phone_number_id'   => $phoneId,
                'user_id'           => $columns['user_id'] ?? null,
                'direction'         => 'out',
                'contact_wa_id'     => $to,
                'message_type'      => (string) ($body['type'] ?? 'text'),
                'body'              => isset($columns['body']) ? mb_substr((string) $columns['body'], 0, 5000) : null,
                'caption'           => isset($columns['caption']) ? mb_substr((string) $columns['caption'], 0, 1024) : null,
                'media_id'          => $columns['media_id'] ?? null,
                'filename'          => $columns['filename'] ?? null,
                'latitude'          => $columns['latitude'] ?? null,
                'longitude'         => $columns['longitude'] ?? null,
                'template_name'     => $columns['template_name'] ?? null,
                'template_language' => $columns['template_language'] ?? null,
                'context_wamid'     => $columns['context_wamid'] ?? null,
                'interactive_type'  => $columns['interactive_type'] ?? null,
                'status'            => 'sending',
                'payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at'        => now_utc(),
                'updated_at'        => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Could not record outbound message', ['error' => $e->getMessage()], 'meta');

            return null;
        }
    }

    private static function finish(?int $rowId, bool $ok, ?string $wamid, array $result): void
    {
        if ($rowId === null) {
            return;
        }

        try {
            $data = $ok
                ? [
                    'wamid'      => $wamid,
                    'status'     => 'sent',
                    'sent_at'    => now_utc(),
                    'updated_at' => now_utc(),
                ]
                : [
                    'status'       => 'failed',
                    'error_code'   => $result['code'] ?? null,
                    'error_title'  => mb_substr((string) ($result['message'] ?? 'Send failed'), 0, 255),
                    'error_detail' => mb_substr(MetaGraph::explain($result), 0, 500),
                    'failed_at'    => now_utc(),
                    'updated_at'   => now_utc(),
                ];

            App::i()->db()->update('wa_messages', $data, 'id = :id', ['id' => $rowId]);
        } catch (\Throwable $e) {
            Logger::warn('Could not finalise outbound message row', ['error' => $e->getMessage()], 'meta');
        }
    }

    /** A readable stand-in for what a template actually said. */
    private static function templatePreview(array $components): string
    {
        $values = [];

        foreach ($components as $component) {
            foreach ($component['parameters'] ?? [] as $parameter) {
                if (isset($parameter['text']) && is_string($parameter['text'])) {
                    $values[] = $parameter['text'];
                }
            }
        }

        return mb_substr(implode(' | ', $values), 0, 1000);
    }

    private static function failure(string $message): array
    {
        return [
            'ok'             => false,
            'wamid'          => null,
            'message_row_id' => null,
            'error'          => $message,
            'code'           => null,
            'status'         => 0,
            'retryable'      => false,
        ];
    }

    /* ---------------------------------------------------------- 24h window */

    /**
     * Meta only accepts free-form messages within 24 hours of the customer's
     * last inbound message. Outside it, every free-form send answers 131047 and
     * the reminder silently never arrives — so this is checked before sending,
     * not discovered afterwards.
     */
    public static function withinServiceWindow(array $account, string $number): bool
    {
        $number = ltrim(normalize_phone($number), '+');

        if ($number === '') {
            return false;
        }

        try {
            $last = App::i()->db()->value(
                "SELECT MAX(created_at) FROM wa_messages
                  WHERE waba_account_id = ? AND contact_wa_id = ? AND direction = 'in'",
                [(int) $account['id'], $number]
            );
        } catch (\Throwable) {
            return false;
        }

        if (!is_string($last) || $last === '') {
            // Fall back to the pre-migration inbound table so an install that
            // upgrades mid-conversation does not lose its open windows.
            return MetaCloudService::withinServiceWindow($number);
        }

        $time = strtotime($last . ' UTC');

        // A minute of headroom, so a send racing the boundary is not rejected.
        return $time !== false && $time > (time() - 86400 + 60);
    }
}
