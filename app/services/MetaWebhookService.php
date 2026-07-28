<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Everything Meta sends back.
 *
 * A Cloud API webhook is not one thing: the same URL receives inbound messages,
 * delivery receipts with the price attached, template approval decisions, phone
 * quality downgrades and account restrictions. They arrive interleaved, out of
 * order, and Meta retries anything we do not answer 200 — which means the same
 * event will be delivered twice more often than most people expect.
 *
 * Two rules follow from that, and both are enforced here:
 *
 *   1. Store the raw event first, then process it. If processing throws, the
 *      event is still on disk and can be replayed; without this a bad deploy
 *      loses the billing records for everything that arrived during it.
 *   2. Deduplicate on a key derived from the event itself, not on arrival time.
 *      A redelivered "delivered" receipt must not be counted as a second
 *      delivery, and a redelivered inbound message must not answer twice.
 */
class MetaWebhookService
{
    /**
     * Persist and process one webhook body.
     *
     * @return array{stored: int, processed: int, skipped: int}
     */
    public static function handle(array $payload, bool $signatureValid): array
    {
        $stored = 0;
        $processed = 0;
        $skipped = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $wabaId = (string) ($entry['id'] ?? '');

            foreach ($entry['changes'] ?? [] as $change) {
                if (!is_array($change)) {
                    continue;
                }

                $field = (string) ($change['field'] ?? 'unknown');
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                foreach (self::split($field, $value) as $event) {
                    $eventId = self::store($wabaId, $field, $event['key'], $event['payload'], $signatureValid);

                    if ($eventId === null) {
                        $skipped++;   // already seen
                        continue;
                    }

                    $stored++;

                    try {
                        self::process($field, $event['payload'], $wabaId);
                        self::markProcessed($eventId, null);
                        $processed++;
                    } catch (\Throwable $e) {
                        self::markProcessed($eventId, $e->getMessage());
                        Logger::exception($e, 'meta');
                    }
                }
            }
        }

        return ['stored' => $stored, 'processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * One webhook body can carry several independent facts — three statuses and
     * two messages in a single `value`. Splitting them means one bad message
     * cannot lose the other four, and each gets its own dedup key.
     *
     * @return array<int, array{key: string|null, payload: array}>
     */
    public static function split(string $field, array $value): array
    {
        $out = [];
        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];

        foreach ($value['messages'] ?? [] as $message) {
            if (!is_array($message)) {
                continue;
            }

            $out[] = [
                'key'     => 'in_' . (string) ($message['id'] ?? md5(json_encode($message) ?: '')),
                'payload' => [
                    'metadata' => $metadata,
                    'contacts' => $contacts,
                    'message'  => $message,
                ],
            ];
        }

        foreach ($value['statuses'] ?? [] as $status) {
            if (!is_array($status)) {
                continue;
            }

            // The key includes the status: sent, delivered and read all carry
            // the same wamid and all three must be recorded.
            $out[] = [
                'key'     => 'st_' . (string) ($status['id'] ?? '') . '_' . (string) ($status['status'] ?? ''),
                'payload' => ['metadata' => $metadata, 'status' => $status],
            ];
        }

        if ($out === []) {
            // Template decisions, quality updates, account restrictions — one
            // fact per change, keyed by its own contents.
            $out[] = [
                'key'     => self::genericKey($field, $value),
                'payload' => $value,
            ];
        }

        return $out;
    }

    private static function genericKey(string $field, array $value): ?string
    {
        $parts = array_filter([
            $field,
            (string) ($value['message_template_id'] ?? ''),
            (string) ($value['event'] ?? ''),
            (string) ($value['display_phone_number'] ?? ''),
            (string) ($value['current_limit'] ?? ''),
            (string) ($value['ban_info']['waba_ban_state'] ?? ''),
        ], static fn (string $v): bool => $v !== '');

        if (count($parts) < 2) {
            // Nothing distinguishing — hash the body so an identical redelivery
            // is caught but two genuinely different events are not merged.
            return mb_substr($field . '_' . md5((string) json_encode($value)), 0, 190);
        }

        return mb_substr(implode('_', $parts), 0, 190);
    }

    /* -------------------------------------------------------------- Storage */

    /** @return int|null the new event row id, or null when it is a duplicate */
    public static function store(
        string $wabaId,
        string $field,
        ?string $eventKey,
        array $payload,
        bool $signatureValid
    ): ?int {
        try {
            return App::i()->db()->insert('wa_webhook_events', [
                'waba_id'         => $wabaId !== '' ? mb_substr($wabaId, 0, 32) : null,
                'field'           => mb_substr($field, 0, 64),
                'event_key'       => $eventKey !== null ? mb_substr($eventKey, 0, 190) : null,
                'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'signature_valid' => $signatureValid ? 1 : 0,
                'processed'       => 0,
                'received_at'     => now_utc(),
            ]);
        } catch (\PDOException $e) {
            // 23000 is the unique-key violation on event_key: Meta redelivered
            // something we already have. That is expected, not an error.
            if (($e->errorInfo[0] ?? '') === '23000') {
                return null;
            }

            Logger::warn('Webhook event could not be stored', ['error' => $e->getMessage()], 'meta');

            return null;
        } catch (\Throwable $e) {
            Logger::warn('Webhook event could not be stored', ['error' => $e->getMessage()], 'meta');

            return null;
        }
    }

    private static function markProcessed(int $eventId, ?string $error): void
    {
        try {
            App::i()->db()->update('wa_webhook_events', [
                'processed'    => $error === null ? 1 : 0,
                'error'        => $error !== null ? mb_substr($error, 0, 500) : null,
                'processed_at' => now_utc(),
            ], 'id = :id', ['id' => $eventId]);
        } catch (\Throwable) {
        }
    }

    /* ------------------------------------------------------------ Dispatch */

    public static function process(string $field, array $payload, string $wabaId): void
    {
        match ($field) {
            'messages' => self::processMessages($payload, $wabaId),
            'message_template_status_update',
            'message_template_quality_update',
            'template_category_update' => self::processTemplate($payload, $wabaId),
            'phone_number_quality_update',
            'phone_number_name_update' => self::processPhoneNumber($payload),
            'account_update',
            'account_review_update' => self::processAccount($payload, $wabaId),
            default => null,
        };
    }

    private static function processMessages(array $payload, string $wabaId): void
    {
        if (isset($payload['status']) && is_array($payload['status'])) {
            self::recordStatus($payload['status'], $payload['metadata'] ?? [], $wabaId);

            return;
        }

        if (isset($payload['message']) && is_array($payload['message'])) {
            self::recordInbound($payload['message'], $payload['metadata'] ?? [], $payload['contacts'] ?? [], $wabaId);
        }
    }

    /* -------------------------------------------------- Delivery & pricing */

    /**
     * A status receipt, and — on the first one of a conversation — the price.
     *
     * Meta bills per 24-hour conversation, not per message, so the `pricing` and
     * `conversation` blocks appear on some receipts and not others. Recording
     * the price only where it is given, and rolling it up onto the conversation,
     * is what makes a billing dashboard match Meta's own invoice.
     */
    public static function recordStatus(array $status, array $metadata, string $wabaId): void
    {
        $wamid = (string) ($status['id'] ?? '');
        $state = strtolower((string) ($status['status'] ?? ''));

        if ($wamid === '' || $state === '') {
            return;
        }

        $db = App::i()->db();
        $account = self::account($metadata, $wabaId);
        $timestamp = self::timestamp($status['timestamp'] ?? null);

        $conversationRowId = self::upsertConversation($status, $metadata, $account);

        $data = ['updated_at' => now_utc()];

        // Never move a message backwards. Meta can deliver `sent` after `read`
        // when receipts are retried, and a chat that flips from read back to
        // sent is worse than one that is simply slow.
        $rank = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
        $existing = null;

        try {
            $existing = $db->one('SELECT id, status FROM wa_messages WHERE wamid = ?', [$wamid]);
        } catch (\Throwable) {
        }

        $currentRank = $rank[(string) ($existing['status'] ?? 'queued')] ?? 0;
        $newRank = $rank[$state] ?? 0;

        if ($state === 'failed') {
            $data['status'] = 'failed';
            $data['failed_at'] = $timestamp;

            $error = $status['errors'][0] ?? null;

            if (is_array($error)) {
                $data['error_code'] = isset($error['code']) ? (int) $error['code'] : null;
                $data['error_title'] = mb_substr((string) ($error['title'] ?? ''), 0, 255);
                $data['error_detail'] = mb_substr(
                    (string) ($error['error_data']['details'] ?? $error['message'] ?? ''),
                    0,
                    500
                );
            }
        } elseif ($newRank > $currentRank) {
            $data['status'] = $state;

            if ($state === 'sent') {
                $data['sent_at'] = $timestamp;
            } elseif ($state === 'delivered') {
                $data['delivered_at'] = $timestamp;
            } elseif ($state === 'read') {
                $data['read_at'] = $timestamp;
            }
        }

        if ($conversationRowId !== null) {
            $data['conversation_row_id'] = $conversationRowId;
        }

        $pricing = is_array($status['pricing'] ?? null) ? $status['pricing'] : [];
        $conversation = is_array($status['conversation'] ?? null) ? $status['conversation'] : [];

        if ($pricing !== []) {
            $data['billing_category'] = mb_substr(
                (string) ($pricing['category'] ?? $conversation['origin']['type'] ?? ''),
                0,
                32
            ) ?: null;
        }

        if (isset($status['pricing']['billable']) && $status['pricing']['billable'] === false) {
            $data['price'] = 0;
        }

        try {
            if ($existing !== null) {
                $db->update('wa_messages', $data, 'id = :id', ['id' => (int) $existing['id']]);

                if ($conversationRowId !== null) {
                    MetaPricingService::applyToConversation($conversationRowId);
                }

                return;
            }
        } catch (\Throwable $e) {
            Logger::warn('Could not apply status receipt', ['error' => $e->getMessage()], 'meta');

            return;
        }

        // A receipt for a message we never recorded — sent from Meta's own UI,
        // or from before this table existed. Record it rather than dropping the
        // billing information on the floor.
        if ($account !== null) {
            try {
                $db->insert('wa_messages', $data + [
                    'waba_account_id'     => (int) $account['id'],
                    'phone_number_id'     => mb_substr((string) ($metadata['phone_number_id'] ?? ''), 0, 32),
                    'wamid'               => $wamid,
                    'direction'           => 'out',
                    'contact_wa_id'       => mb_substr((string) ($status['recipient_id'] ?? ''), 0, 24),
                    'message_type'        => 'unknown',
                    'status'              => in_array($state, ['sent', 'delivered', 'read', 'failed'], true)
                        ? $state
                        : 'sent',
                    'conversation_row_id' => $conversationRowId,
                    'created_at'          => now_utc(),
                ]);

                if ($conversationRowId !== null) {
                    MetaPricingService::applyToConversation($conversationRowId);
                }
            } catch (\Throwable $e) {
                Logger::warn('Could not record orphan status receipt', ['error' => $e->getMessage()], 'meta');
            }
        }
    }

    /**
     * Create or update the billable conversation this receipt belongs to.
     *
     * @return int|null the wa_conversations row id
     */
    private static function upsertConversation(array $status, array $metadata, ?array $account): ?int
    {
        $conversation = is_array($status['conversation'] ?? null) ? $status['conversation'] : [];
        $conversationId = (string) ($conversation['id'] ?? '');

        if ($conversationId === '' || $account === null) {
            return null;
        }

        $pricing = is_array($status['pricing'] ?? null) ? $status['pricing'] : [];
        $db = App::i()->db();

        $category = strtolower((string) (
            $pricing['category'] ?? $conversation['origin']['type'] ?? 'unknown'
        ));

        $allowed = ['marketing', 'utility', 'authentication', 'service', 'referral_conversion'];

        try {
            $db->upsert('wa_conversations', [
                'waba_account_id' => (int) $account['id'],
                'phone_number_id' => mb_substr((string) ($metadata['phone_number_id'] ?? ''), 0, 32),
                'contact_wa_id'   => mb_substr((string) ($status['recipient_id'] ?? ''), 0, 24),
                'conversation_id' => mb_substr($conversationId, 0, 64),
                'category'        => in_array($category, $allowed, true) ? $category : 'unknown',
                'origin_type'     => mb_substr((string) ($conversation['origin']['type'] ?? ''), 0, 32) ?: null,
                'is_billable'     => ($pricing['billable'] ?? true) ? 1 : 0,
                'pricing_model'   => mb_substr((string) ($pricing['pricing_model'] ?? ''), 0, 32) ?: null,
                'country'         => mb_substr((string) ($account['country'] ?? ''), 0, 8) ?: null,
                'currency'        => (string) ($account['currency'] ?? 'INR'),
                'expires_at'      => self::timestamp($conversation['expiration_timestamp'] ?? null),
                'started_at'      => now_utc(),
                'created_at'      => now_utc(),
            ], ['category', 'origin_type', 'is_billable', 'pricing_model', 'expires_at']);

            $row = $db->one('SELECT id FROM wa_conversations WHERE conversation_id = ?', [$conversationId]);

            return $row === null ? null : (int) $row['id'];
        } catch (\Throwable $e) {
            Logger::warn('Could not record conversation', ['error' => $e->getMessage()], 'meta');

            return null;
        }
    }

    /* ------------------------------------------------------------- Inbound */

    /**
     * Store an inbound message in full — every type, with its media ids, reply
     * context and interactive selection intact.
     *
     * The reminder pipeline only needs the text, but a conversation dashboard
     * needs the rest, and none of it can be recovered later: Meta does not let
     * you read back a message you failed to store.
     *
     * @return int|null the wa_messages row id
     */
    public static function recordInbound(array $message, array $metadata, array $contacts, string $wabaId): ?int
    {
        $account = self::account($metadata, $wabaId);

        if ($account === null) {
            return null;
        }

        $type = (string) ($message['type'] ?? 'text');
        $from = ltrim(normalize_phone((string) ($message['from'] ?? '')), '+');

        if ($from === '') {
            return null;
        }

        $media = is_array($message[$type] ?? null) ? $message[$type] : [];
        $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
        $reply = $interactive['button_reply'] ?? $interactive['list_reply'] ?? [];

        $data = [
            'waba_account_id'         => (int) $account['id'],
            'phone_number_id'         => mb_substr((string) ($metadata['phone_number_id'] ?? ''), 0, 32),
            'wamid'                   => mb_substr((string) ($message['id'] ?? ''), 0, 190) ?: null,
            'direction'               => 'in',
            'contact_wa_id'           => $from,
            'message_type'            => mb_substr($type, 0, 24),
            'body'                    => mb_substr(self::inboundText($message, $type), 0, 5000),
            'caption'                 => isset($media['caption']) ? mb_substr((string) $media['caption'], 0, 1024) : null,
            'media_id'                => isset($media['id']) ? mb_substr((string) $media['id'], 0, 190) : null,
            'media_mime'              => isset($media['mime_type']) ? mb_substr((string) $media['mime_type'], 0, 64) : null,
            'media_sha256'            => isset($media['sha256']) ? mb_substr((string) $media['sha256'], 0, 64) : null,
            'filename'                => isset($media['filename']) ? mb_substr((string) $media['filename'], 0, 255) : null,
            'latitude'                => $message['location']['latitude'] ?? null,
            'longitude'               => $message['location']['longitude'] ?? null,
            'context_wamid'           => isset($message['context']['id'])
                ? mb_substr((string) $message['context']['id'], 0, 190)
                : null,
            'interactive_type'        => isset($interactive['type']) ? mb_substr((string) $interactive['type'], 0, 32) : null,
            'interactive_reply_id'    => isset($reply['id']) ? mb_substr((string) $reply['id'], 0, 256) : null,
            'interactive_reply_title' => isset($reply['title']) ? mb_substr((string) $reply['title'], 0, 256) : null,
            'status'                  => 'delivered',
            'payload'                 => json_encode(
                ['message' => $message, 'contacts' => $contacts],
                JSON_UNESCAPED_UNICODE
            ),
            'created_at'              => self::timestamp($message['timestamp'] ?? null) ?? now_utc(),
            'updated_at'              => now_utc(),
        ];

        // A button press on a template carries its label in `button`, not
        // `interactive` — a different shape for the same user action.
        if ($type === 'button' && isset($message['button'])) {
            $data['interactive_type'] = 'template_button';
            $data['interactive_reply_id'] = mb_substr((string) ($message['button']['payload'] ?? ''), 0, 256);
            $data['interactive_reply_title'] = mb_substr((string) ($message['button']['text'] ?? ''), 0, 256);
        }

        try {
            return App::i()->db()->insert('wa_messages', $data);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                return null;   // redelivered; already stored
            }

            Logger::warn('Could not store inbound message', ['error' => $e->getMessage()], 'meta');

            return null;
        } catch (\Throwable $e) {
            Logger::warn('Could not store inbound message', ['error' => $e->getMessage()], 'meta');

            return null;
        }
    }

    /** Every inbound type keeps its text somewhere different. */
    public static function inboundText(array $message, string $type): string
    {
        $text = match ($type) {
            'text'        => $message['text']['body'] ?? '',
            'button'      => $message['button']['text'] ?? '',
            'image'       => $message['image']['caption'] ?? '',
            'video'       => $message['video']['caption'] ?? '',
            'audio'       => '',
            'sticker'     => '',
            'document'    => $message['document']['caption'] ?? ($message['document']['filename'] ?? ''),
            'location'    => trim(
                (string) ($message['location']['name'] ?? '') . ' ' . (string) ($message['location']['address'] ?? '')
            ),
            'contacts'    => (string) ($message['contacts'][0]['name']['formatted_name'] ?? ''),
            'order'       => 'Order with ' . count($message['order']['product_items'] ?? []) . ' item(s)',
            'reaction'    => (string) ($message['reaction']['emoji'] ?? ''),
            'interactive' => $message['interactive']['button_reply']['title']
                             ?? $message['interactive']['list_reply']['title']
                             ?? '',
            default       => '',
        };

        return trim(is_string($text) ? $text : '');
    }

    /* -------------------------------------------------- Templates & health */

    /**
     * Meta's verdict on a template. This is the only way to learn that a
     * template was rejected — there is no callback on submit, and polling every
     * template on every send would burn the rate limit.
     */
    public static function processTemplate(array $value, string $wabaId): void
    {
        $metaId = (string) ($value['message_template_id'] ?? '');
        $name = (string) ($value['message_template_name'] ?? '');
        $language = (string) ($value['message_template_language'] ?? '');

        if ($metaId === '' && $name === '') {
            return;
        }

        $event = strtoupper((string) ($value['event'] ?? ''));

        $status = match ($event) {
            'APPROVED'          => 'APPROVED',
            'REJECTED'          => 'REJECTED',
            'PAUSED', 'FLAGGED' => 'PAUSED',
            'DISABLED'          => 'DISABLED',
            'PENDING_DELETION'  => 'DISABLED',
            'IN_APPEAL'         => 'IN_APPEAL',
            default             => null,
        };

        $data = ['synced_at' => now_utc(), 'updated_at' => now_utc()];

        if ($status !== null) {
            $data['status'] = $status;

            if ($status === 'APPROVED') {
                $data['approved_at'] = now_utc();
                $data['rejected_reason'] = null;
            }
        }

        if (isset($value['reason']) && $value['reason'] !== 'NONE') {
            $data['rejected_reason'] = mb_substr((string) $value['reason'], 0, 255);
        }

        if (isset($value['new_quality_score'])) {
            $data['quality_score'] = mb_substr((string) $value['new_quality_score'], 0, 32);
        }

        if (isset($value['new_category'])) {
            $category = strtoupper((string) $value['new_category']);

            if (in_array($category, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
                $data['category'] = $category;
            }
        }

        try {
            $db = App::i()->db();

            if ($metaId !== '') {
                $updated = $db->update('wa_templates', $data, 'meta_template_id = :mid', ['mid' => $metaId]);

                if ($updated > 0) {
                    return;
                }
            }

            $account = WabaAccountService::findByWabaId($wabaId);

            if ($account === null || $name === '') {
                return;
            }

            // Matched by name because the template was created in Meta's UI and
            // has no local meta_template_id yet.
            if ($metaId !== '') {
                $data['meta_template_id'] = mb_substr($metaId, 0, 32);
            }

            $where = 'waba_account_id = :aid AND name = :name';
            $params = ['aid' => (int) $account['id'], 'name' => $name];

            if ($language !== '') {
                $where .= ' AND language = :lang';
                $params['lang'] = $language;
            }

            $db->update('wa_templates', $data, $where, $params);
        } catch (\Throwable $e) {
            Logger::warn('Could not apply template update', ['error' => $e->getMessage()], 'meta');
        }
    }

    /**
     * A quality downgrade is the earliest warning that deliveries are about to
     * be throttled, and it only ever arrives by webhook.
     */
    public static function processPhoneNumber(array $value): void
    {
        $display = (string) ($value['display_phone_number'] ?? '');

        if ($display === '') {
            return;
        }

        $data = ['updated_at' => now_utc()];

        if (isset($value['current_limit'])) {
            $data['messaging_limit'] = mb_substr((string) $value['current_limit'], 0, 32);
        }

        if (isset($value['event'])) {
            $event = strtoupper((string) $value['event']);

            $quality = match ($event) {
                'FLAGGED'   => 'RED',
                'ONBOARDING', 'UNFLAGGED' => 'GREEN',
                default     => null,
            };

            if ($quality !== null) {
                $data['quality_rating'] = $quality;
            }
        }

        if (count($data) === 1) {
            return;
        }

        try {
            $normalised = ltrim(normalize_phone($display), '+');

            App::i()->db()->update(
                'waba_phone_numbers',
                $data,
                "REPLACE(REPLACE(display_number, '+', ''), ' ', '') = :num",
                ['num' => $normalised]
            );
        } catch (\Throwable $e) {
            Logger::warn('Could not apply phone number update', ['error' => $e->getMessage()], 'meta');
        }
    }

    /** Account restriction or ban — the reason every send suddenly fails. */
    public static function processAccount(array $value, string $wabaId): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));

        $status = match ($event) {
            'DISABLED_UPDATE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION' => 'suspended',
            'ACCOUNT_DELETED' => 'disconnected',
            'VERIFIED_ACCOUNT', 'ACCOUNT_VERIFIED' => 'active',
            default => null,
        };

        if ($status === null || $wabaId === '') {
            return;
        }

        try {
            App::i()->db()->update('waba_accounts', [
                'status'     => $status,
                'last_error' => mb_substr('Meta account event: ' . $event, 0, 500),
                'updated_at' => now_utc(),
            ], 'waba_id = :wid', ['wid' => $wabaId]);

            Logger::warn('WABA account status changed by Meta', [
                'waba_id' => $wabaId,
                'event'   => $event,
                'status'  => $status,
            ], 'meta');
        } catch (\Throwable $e) {
            Logger::warn('Could not apply account update', ['error' => $e->getMessage()], 'meta');
        }
    }

    /* ----------------------------------------------------------- Signature */

    /**
     * Verify Meta's X-Hub-Signature-256 against the right app secret.
     *
     * Meta signs the body with the *app secret* of the app that owns the
     * subscription — not the verify token, and not our own webhook secret. With
     * Embedded Signup that is our platform app for every tenant, but a customer
     * who connected with their own app has their own secret, so the account is
     * resolved from the payload first and the platform secret is the fallback.
     */
    public static function verifySignature(string $rawBody, ?string $header, array $payload): bool
    {
        if (!is_string($header) || $header === '') {
            return false;
        }

        $provided = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;
        $wabaId = (string) ($payload['entry'][0]['id'] ?? '');
        $account = $wabaId !== '' ? WabaAccountService::findByWabaId($wabaId) : null;

        foreach (self::candidateSecrets($account) as $secret) {
            if (hash_equals(hash_hmac('sha256', $rawBody, $secret), $provided)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private static function candidateSecrets(?array $account): array
    {
        $secrets = [];

        if ($account !== null) {
            $secrets[] = WabaAccountService::appSecret($account);
        }

        try {
            $settings = App::i()->settings();
            $secrets[] = (string) $settings->get('meta_app_secret', '');
            $secrets[] = (string) $settings->get('wa_cloud_app_secret', '');
        } catch (\Throwable) {
        }

        return array_values(array_unique(array_filter(array_map('trim', $secrets))));
    }

    /** True when no app secret is configured anywhere — signatures cannot be checked. */
    public static function hasAnySecret(): bool
    {
        return self::candidateSecrets(null) !== [];
    }

    /* --------------------------------------------------------------- Utils */

    /** The account this event belongs to, by phone number id then by WABA id. */
    public static function account(array $metadata, string $wabaId): ?array
    {
        $phoneNumberId = (string) ($metadata['phone_number_id'] ?? '');

        if ($phoneNumberId !== '') {
            $account = WabaAccountService::findByPhoneNumberId($phoneNumberId);

            if ($account !== null) {
                return $account;
            }
        }

        if ($wabaId !== '') {
            $account = WabaAccountService::findByWabaId($wabaId);

            if ($account !== null) {
                return $account;
            }
        }

        // A single-business install may have exactly one account and a phone
        // number that has not been synced yet. Using it is right far more often
        // than dropping the event.
        return WabaAccountService::platform();
    }

    /** Meta timestamps are Unix seconds as a string. */
    public static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $seconds = (int) $value;

        // Sanity: anything before 2020 or more than a day ahead is not a real
        // event time, and a bad one poisons every report built on it.
        if ($seconds < 1577836800 || $seconds > time() + 86400) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $seconds);
    }
}
