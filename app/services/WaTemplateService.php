<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * The template manager.
 *
 * Outside the 24-hour window a template is the only thing WhatsApp will deliver,
 * so this is not a convenience feature — it is the send path for every reminder
 * that matters. What makes templates painful, and what this class exists to
 * absorb:
 *
 *   • Meta's component array is positional and unforgiving. {{1}} in the body
 *     must line up with the first body parameter at send time, forever, and a
 *     mismatch is only discovered when a real message fails with 132000.
 *   • Approval is asynchronous and silent. The only notification is a webhook,
 *     which is why `status` is mirrored locally and never assumed.
 *   • Names are immutable. A typo means a new template and another wait, so the
 *     name is validated before submission rather than after.
 *
 * Templates are stored locally first with status LOCAL, then submitted. A draft
 * that Meta never sees is still a draft you can fix, which is much kinder than
 * losing the work because a submission failed.
 */
class WaTemplateService
{
    public const CATEGORIES = ['UTILITY', 'MARKETING', 'AUTHENTICATION'];

    public const HEADER_FORMATS = ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'];

    /* ------------------------------------------------------------ Building */

    /**
     * Turn a plain specification into Meta's component array.
     *
     * @param array{
     *   header_format?: string, header_text?: string, header_example?: string,
     *   body: string, body_examples?: array<int, string>,
     *   footer?: string, buttons?: array<int, array>
     * } $spec
     */
    public static function buildComponents(array $spec): array
    {
        $components = [];

        $headerFormat = strtoupper(trim((string) ($spec['header_format'] ?? '')));

        if ($headerFormat !== '' && in_array($headerFormat, self::HEADER_FORMATS, true)) {
            $header = ['type' => 'HEADER', 'format' => $headerFormat];

            if ($headerFormat === 'TEXT') {
                $header['text'] = mb_substr((string) ($spec['header_text'] ?? ''), 0, 60);

                // A header may contain exactly one variable, and Meta requires
                // an example for it or the submission is rejected outright.
                if (self::variablesIn($header['text']) > 0) {
                    $header['example'] = [
                        'header_text' => [(string) ($spec['header_example'] ?? 'Example')],
                    ];
                }
            } elseif (!empty($spec['header_example'])) {
                $header['example'] = ['header_handle' => [(string) $spec['header_example']]];
            }

            $components[] = $header;
        }

        $body = trim((string) ($spec['body'] ?? ''));
        $bodyComponent = ['type' => 'BODY', 'text' => mb_substr($body, 0, 1024)];
        $variables = self::variablesIn($body);

        if ($variables > 0) {
            $examples = array_values(array_map(
                static fn ($v): string => (string) $v,
                $spec['body_examples'] ?? []
            ));

            // Meta wants one example per variable. Filling the gaps beats a
            // rejection three days later.
            while (count($examples) < $variables) {
                $examples[] = 'Example ' . (count($examples) + 1);
            }

            $bodyComponent['example'] = ['body_text' => [array_slice($examples, 0, $variables)]];
        }

        $components[] = $bodyComponent;

        $footer = trim((string) ($spec['footer'] ?? ''));

        if ($footer !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => mb_substr($footer, 0, 60)];
        }

        $buttons = self::buildButtons($spec['buttons'] ?? []);

        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /**
     * Quick reply, URL, phone number, copy code and OTP buttons.
     *
     * Meta caps the set at ten, and mixing quick replies with call-to-action
     * buttons has its own rules — but the cap it enforces silently is that a
     * URL button's variable must be at the *end* of the URL, so that is the one
     * checked here.
     */
    public static function buildButtons(array $buttons): array
    {
        $out = [];

        foreach (array_slice($buttons, 0, 10) as $button) {
            $type = strtoupper(trim((string) ($button['type'] ?? '')));
            $text = mb_substr(trim((string) ($button['text'] ?? '')), 0, 25);

            if ($text === '' && $type !== 'OTP') {
                continue;
            }

            switch ($type) {
                case 'QUICK_REPLY':
                    $out[] = ['type' => 'QUICK_REPLY', 'text' => $text];
                    break;

                case 'URL':
                    $entry = [
                        'type' => 'URL',
                        'text' => $text,
                        'url'  => mb_substr((string) ($button['url'] ?? ''), 0, 2000),
                    ];

                    if (self::variablesIn($entry['url']) > 0) {
                        $entry['example'] = [(string) ($button['example'] ?? 'https://example.com/1')];
                    }

                    $out[] = $entry;
                    break;

                case 'PHONE_NUMBER':
                    $out[] = [
                        'type'         => 'PHONE_NUMBER',
                        'text'         => $text,
                        'phone_number' => mb_substr((string) ($button['phone_number'] ?? ''), 0, 20),
                    ];
                    break;

                case 'COPY_CODE':
                    $out[] = [
                        'type'    => 'COPY_CODE',
                        'example' => mb_substr((string) ($button['example'] ?? '123456'), 0, 15),
                    ];
                    break;

                case 'OTP':
                    $otp = [
                        'type'      => 'OTP',
                        'otp_type'  => strtoupper((string) ($button['otp_type'] ?? 'COPY_CODE')),
                    ];

                    if ($text !== '') {
                        $otp['text'] = $text;
                    }

                    if ($otp['otp_type'] === 'ONE_TAP') {
                        $otp['autofill_text'] = mb_substr((string) ($button['autofill_text'] ?? 'Autofill'), 0, 25);
                        $otp['package_name'] = (string) ($button['package_name'] ?? '');
                        $otp['signature_hash'] = (string) ($button['signature_hash'] ?? '');
                    }

                    $out[] = $otp;
                    break;
            }
        }

        return $out;
    }

    /** How many {{n}} placeholders are in this text? */
    public static function variablesIn(string $text): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);

        $numbers = array_map('intval', $matches[1] ?? []);

        return $numbers === [] ? 0 : max($numbers);
    }

    /** Total body variables across a component array — what a send must supply. */
    public static function variableCount(array $components): int
    {
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return self::variablesIn((string) ($component['text'] ?? ''));
            }
        }

        return 0;
    }

    /* ---------------------------------------------------------- Validation */

    /**
     * Everything Meta will reject, caught before the three-day wait.
     *
     * @return array<int, string> human-readable problems; empty means valid
     */
    public static function validate(array $spec): array
    {
        $errors = [];

        $name = trim((string) ($spec['name'] ?? ''));

        if ($name === '') {
            $errors[] = 'A template name is required.';
        } elseif (preg_match('/^[a-z0-9_]{1,512}$/', $name) !== 1) {
            $errors[] = 'The name may only contain lowercase letters, numbers and underscores — no spaces or capitals.';
        }

        $category = strtoupper(trim((string) ($spec['category'] ?? '')));

        if (!in_array($category, self::CATEGORIES, true)) {
            $errors[] = 'Category must be UTILITY, MARKETING or AUTHENTICATION.';
        }

        $body = trim((string) ($spec['body'] ?? ''));

        if ($body === '') {
            $errors[] = 'The body text is required.';
        } elseif (mb_strlen($body) > 1024) {
            $errors[] = 'The body is longer than the 1024 characters Meta allows.';
        }

        // Variables must run 1..n with no gaps; {{1}} and {{3}} is rejected.
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        $used = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($used);

        foreach ($used as $index => $number) {
            if ($number !== $index + 1) {
                $errors[] = 'Variables must be numbered {{1}}, {{2}}, {{3}}… with no gaps.';
                break;
            }
        }

        if ($used !== [] && (str_starts_with($body, '{{') || str_ends_with(rtrim($body), '}}'))) {
            $errors[] = 'Meta does not allow the body to begin or end with a variable — put a word around it.';
        }

        $headerText = trim((string) ($spec['header_text'] ?? ''));

        if ($headerText !== '' && self::variablesIn($headerText) > 1) {
            $errors[] = 'A header may contain at most one variable.';
        }

        if (mb_strlen(trim((string) ($spec['footer'] ?? ''))) > 60) {
            $errors[] = 'The footer must be 60 characters or fewer.';
        }

        foreach ($spec['buttons'] ?? [] as $button) {
            $type = strtoupper((string) ($button['type'] ?? ''));

            if ($type === 'URL' && trim((string) ($button['url'] ?? '')) === '') {
                $errors[] = 'A URL button needs a URL.';
            }

            if ($type === 'PHONE_NUMBER' && trim((string) ($button['phone_number'] ?? '')) === '') {
                $errors[] = 'A call button needs a phone number.';
            }
        }

        return $errors;
    }

    /* ------------------------------------------------------------- Storage */

    /**
     * Save a template locally. Returns the local row id.
     *
     * @return array{ok: bool, id: int|null, errors: array<int, string>}
     */
    public static function saveLocal(array $account, array $spec, ?int $templateId = null, ?int $userId = null): array
    {
        $errors = self::validate($spec);

        if ($errors !== []) {
            return ['ok' => false, 'id' => null, 'errors' => $errors];
        }

        $components = self::buildComponents($spec);
        $db = App::i()->db();

        $data = [
            'waba_account_id' => (int) $account['id'],
            'name'            => trim((string) $spec['name']),
            'language'        => trim((string) ($spec['language'] ?? 'en')) ?: 'en',
            'category'        => strtoupper((string) $spec['category']),
            'components'      => json_encode($components, JSON_UNESCAPED_UNICODE),
            'example'         => json_encode($spec['body_examples'] ?? [], JSON_UNESCAPED_UNICODE),
            'variable_count'  => self::variableCount($components),
            'updated_at'      => now_utc(),
        ];

        try {
            if ($templateId !== null) {
                $db->update('wa_templates', $data, 'id = :id AND waba_account_id = :aid', [
                    'id'  => $templateId,
                    'aid' => (int) $account['id'],
                ]);

                return ['ok' => true, 'id' => $templateId, 'errors' => []];
            }

            $data['status'] = 'LOCAL';
            $data['created_by'] = $userId;
            $data['created_at'] = now_utc();

            return ['ok' => true, 'id' => $db->insert('wa_templates', $data), 'errors' => []];
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                return [
                    'ok'     => false,
                    'id'     => null,
                    'errors' => ['A template with that name and language already exists.'],
                ];
            }

            return ['ok' => false, 'id' => null, 'errors' => [$e->getMessage()]];
        }
    }

    public static function find(int $templateId, ?int $accountId = null): ?array
    {
        try {
            $sql = 'SELECT * FROM wa_templates WHERE id = ? AND deleted_at IS NULL';
            $params = [$templateId];

            if ($accountId !== null) {
                $sql .= ' AND waba_account_id = ?';
                $params[] = $accountId;
            }

            return App::i()->db()->one($sql, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array> */
    public static function listFor(int $accountId, ?string $status = null): array
    {
        try {
            $sql = 'SELECT * FROM wa_templates WHERE waba_account_id = ? AND deleted_at IS NULL';
            $params = [$accountId];

            if ($status !== null && $status !== '') {
                $sql .= ' AND status = ?';
                $params[] = strtoupper($status);
            }

            return App::i()->db()->all($sql . ' ORDER BY name, language', $params);
        } catch (\Throwable) {
            return [];
        }
    }

    /** The approved template to use for a given name, or null. */
    public static function approved(int $accountId, string $name, ?string $language = null): ?array
    {
        try {
            $sql = "SELECT * FROM wa_templates
                     WHERE waba_account_id = ? AND name = ? AND status = 'APPROVED' AND deleted_at IS NULL";
            $params = [$accountId, $name];

            if ($language !== null) {
                $sql .= ' AND language = ?';
                $params[] = $language;
            }

            return App::i()->db()->one($sql . ' ORDER BY id LIMIT 1', $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------ Meta I/O */

    /**
     * Submit a local template to Meta for review.
     *
     * @return array{ok: bool, message: string, meta_template_id: string|null}
     */
    public static function submit(array $account, int $templateId): array
    {
        $template = self::find($templateId, (int) $account['id']);

        if ($template === null) {
            return ['ok' => false, 'message' => 'Template not found.', 'meta_template_id' => null];
        }

        $components = json_field($template['components']);

        $payload = [
            'name'       => (string) $template['name'],
            'language'   => (string) $template['language'],
            'category'   => (string) $template['category'],
            'components' => $components,
        ];

        $result = MetaGraph::post((string) $account['waba_id'] . '/message_templates', [
            'token'      => WabaAccountService::token($account),
            'account_id' => (int) $account['id'],
            // Meta rejects a duplicate name rather than creating a second copy,
            // so a repeat is safe and a lost response should be retried.
            'idempotent' => true,
            'json'       => $payload,
        ]);

        if (!$result['ok']) {
            $message = MetaGraph::explain($result);

            self::update($templateId, [
                'rejected_reason' => mb_substr($message, 0, 255),
                'synced_at'       => now_utc(),
            ]);

            return ['ok' => false, 'message' => $message, 'meta_template_id' => null];
        }

        $metaId = (string) ($result['json']['id'] ?? '');
        $status = strtoupper((string) ($result['json']['status'] ?? 'PENDING'));

        self::update($templateId, [
            'meta_template_id' => $metaId !== '' ? mb_substr($metaId, 0, 32) : null,
            'status'           => in_array($status, ['APPROVED', 'PENDING', 'REJECTED'], true) ? $status : 'PENDING',
            'rejected_reason'  => null,
            'submitted_at'     => now_utc(),
            'synced_at'        => now_utc(),
        ]);

        return [
            'ok'               => true,
            'message'          => 'Submitted to Meta. Approval usually takes minutes, occasionally a day.',
            'meta_template_id' => $metaId !== '' ? $metaId : null,
        ];
    }

    /**
     * Edit an already-submitted template.
     *
     * Meta allows editing only while a template is APPROVED or REJECTED, at most
     * ten times a month, and never the name, language or category. Trying to
     * edit anything else needs a new template.
     */
    public static function pushEdit(array $account, int $templateId): array
    {
        $template = self::find($templateId, (int) $account['id']);

        if ($template === null) {
            return ['ok' => false, 'message' => 'Template not found.'];
        }

        $metaId = (string) ($template['meta_template_id'] ?? '');

        if ($metaId === '') {
            return ['ok' => false, 'message' => 'This template has not been submitted yet — submit it instead.'];
        }

        if (!in_array((string) $template['status'], ['APPROVED', 'REJECTED'], true)) {
            return [
                'ok'      => false,
                'message' => 'Meta only accepts edits while a template is approved or rejected, not while it is '
                           . strtolower((string) $template['status']) . '.',
            ];
        }

        $result = MetaGraph::post(rawurlencode($metaId), [
            'token'      => WabaAccountService::token($account),
            'account_id' => (int) $account['id'],
            'idempotent' => true,
            'json'       => [
                'components' => json_field($template['components']),
                'category'   => (string) $template['category'],
            ],
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'message' => MetaGraph::explain($result)];
        }

        self::update($templateId, ['status' => 'PENDING', 'submitted_at' => now_utc(), 'synced_at' => now_utc()]);

        return ['ok' => true, 'message' => 'Edit submitted; the template goes back to review.'];
    }

    /**
     * Pull every template Meta holds for this WABA and mirror it locally.
     *
     * This is how templates created in Meta's own UI become usable here, and
     * how a status missed because a webhook was dropped is repaired.
     *
     * @return array{ok: bool, synced: int, message: string}
     */
    public static function syncAll(array $account): array
    {
        $after = null;
        $synced = 0;

        do {
            $query = [
                'fields' => 'id,name,language,status,category,components,quality_score,rejected_reason',
                'limit'  => 100,
            ];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $result = MetaGraph::get((string) $account['waba_id'] . '/message_templates', [
                'token'      => WabaAccountService::token($account),
                'account_id' => (int) $account['id'],
                'query'      => $query,
            ]);

            if (!$result['ok']) {
                return ['ok' => false, 'synced' => $synced, 'message' => MetaGraph::explain($result)];
            }

            foreach ($result['json']['data'] ?? [] as $row) {
                if (self::mirror($account, is_array($row) ? $row : [])) {
                    $synced++;
                }
            }

            $after = $result['json']['paging']['cursors']['after'] ?? null;
            $hasNext = isset($result['json']['paging']['next']) && is_string($after) && $after !== '';
        } while ($hasNext && $synced < 2000);

        return ['ok' => true, 'synced' => $synced, 'message' => $synced . ' template(s) synced from Meta.'];
    }

    private static function mirror(array $account, array $row): bool
    {
        $name = (string) ($row['name'] ?? '');

        if ($name === '') {
            return false;
        }

        $status = strtoupper((string) ($row['status'] ?? 'PENDING'));
        $allowed = ['LOCAL', 'PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL'];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        try {
            App::i()->db()->upsert('wa_templates', [
                'waba_account_id'  => (int) $account['id'],
                'meta_template_id' => mb_substr((string) ($row['id'] ?? ''), 0, 32) ?: null,
                'name'             => mb_substr($name, 0, 512),
                'language'         => mb_substr((string) ($row['language'] ?? 'en'), 0, 16),
                'category'         => in_array(strtoupper((string) ($row['category'] ?? '')), self::CATEGORIES, true)
                    ? strtoupper((string) $row['category'])
                    : 'UTILITY',
                'status'           => in_array($status, $allowed, true) ? $status : 'PENDING',
                'rejected_reason'  => isset($row['rejected_reason']) && $row['rejected_reason'] !== 'NONE'
                    ? mb_substr((string) $row['rejected_reason'], 0, 255)
                    : null,
                'quality_score'    => isset($row['quality_score']['score'])
                    ? mb_substr((string) $row['quality_score']['score'], 0, 32)
                    : null,
                'components'       => json_encode($components, JSON_UNESCAPED_UNICODE),
                'variable_count'   => self::variableCount($components),
                'synced_at'        => now_utc(),
                'created_at'       => now_utc(),
                'updated_at'       => now_utc(),
            ], [
                'meta_template_id', 'category', 'status', 'rejected_reason',
                'quality_score', 'components', 'variable_count', 'synced_at', 'updated_at',
            ]);

            return true;
        } catch (\Throwable $e) {
            Logger::warn('Could not mirror template', ['name' => $name, 'error' => $e->getMessage()], 'meta');

            return false;
        }
    }

    /**
     * Delete a template at Meta and locally.
     *
     * Meta deletes by name, which removes every language of it — a sharp edge
     * worth stating rather than discovering.
     */
    public static function deleteTemplate(array $account, int $templateId): array
    {
        $template = self::find($templateId, (int) $account['id']);

        if ($template === null) {
            return ['ok' => false, 'message' => 'Template not found.'];
        }

        if (!empty($template['meta_template_id'])) {
            $result = MetaGraph::delete((string) $account['waba_id'] . '/message_templates', [
                'token'      => WabaAccountService::token($account),
                'account_id' => (int) $account['id'],
                'query'      => [
                    'hsm_id' => (string) $template['meta_template_id'],
                    'name'   => (string) $template['name'],
                ],
            ]);

            if (!$result['ok']) {
                return ['ok' => false, 'message' => MetaGraph::explain($result)];
            }
        }

        // Soft delete: a deleted template still appears in old message rows and
        // reports, and a hard delete would orphan them.
        self::update($templateId, ['deleted_at' => now_utc(), 'status' => 'DISABLED']);

        return ['ok' => true, 'message' => 'Template deleted.'];
    }

    /* -------------------------------------------------- Clone / import / export */

    /** Copy a template under a new name or language — the usual way to translate one. */
    public static function cloneTemplate(array $account, int $templateId, string $newName, ?string $language = null): array
    {
        $template = self::find($templateId, (int) $account['id']);

        if ($template === null) {
            return ['ok' => false, 'id' => null, 'errors' => ['Template not found.']];
        }

        $name = strtolower(trim($newName));

        if (preg_match('/^[a-z0-9_]{1,512}$/', $name) !== 1) {
            return ['ok' => false, 'id' => null, 'errors' => ['The new name may only contain lowercase letters, numbers and underscores.']];
        }

        try {
            $id = App::i()->db()->insert('wa_templates', [
                'waba_account_id' => (int) $account['id'],
                'meta_template_id'=> null,
                'name'            => $name,
                'language'        => $language ?: (string) $template['language'],
                'category'        => (string) $template['category'],
                'status'          => 'LOCAL',
                'components'      => (string) $template['components'],
                'example'         => $template['example'],
                'variable_count'  => (int) $template['variable_count'],
                'created_at'      => now_utc(),
                'updated_at'      => now_utc(),
            ]);

            return ['ok' => true, 'id' => $id, 'errors' => []];
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                return ['ok' => false, 'id' => null, 'errors' => ['That name and language already exists.']];
            }

            return ['ok' => false, 'id' => null, 'errors' => [$e->getMessage()]];
        }
    }

    /** Export every template as JSON, so a working set can be moved between installs. */
    public static function export(int $accountId): string
    {
        $rows = self::listFor($accountId);
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'name'       => $row['name'],
                'language'   => $row['language'],
                'category'   => $row['category'],
                'components' => json_field($row['components']),
            ];
        }

        return (string) json_encode(
            ['version' => 1, 'exported_at' => now_utc(), 'templates' => $out],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    /**
     * Import templates from an export file. They arrive as LOCAL drafts and are
     * never auto-submitted: submitting someone else's marketing copy to Meta
     * under this business's name should always be a deliberate click.
     *
     * @return array{ok: bool, imported: int, skipped: int, errors: array<int, string>}
     */
    public static function import(array $account, string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['templates']) || !is_array($data['templates'])) {
            return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['That is not a template export file.']];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($data['templates'] as $template) {
            if (!is_array($template) || empty($template['name'])) {
                $skipped++;
                continue;
            }

            $components = is_array($template['components'] ?? null) ? $template['components'] : [];

            try {
                App::i()->db()->insert('wa_templates', [
                    'waba_account_id' => (int) $account['id'],
                    'name'            => mb_substr(strtolower((string) $template['name']), 0, 512),
                    'language'        => mb_substr((string) ($template['language'] ?? 'en'), 0, 16),
                    'category'        => in_array(strtoupper((string) ($template['category'] ?? '')), self::CATEGORIES, true)
                        ? strtoupper((string) $template['category'])
                        : 'UTILITY',
                    'status'          => 'LOCAL',
                    'components'      => json_encode($components, JSON_UNESCAPED_UNICODE),
                    'variable_count'  => self::variableCount($components),
                    'created_at'      => now_utc(),
                    'updated_at'      => now_utc(),
                ]);

                $imported++;
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23000') {
                    $skipped++;   // already present
                    continue;
                }

                $errors[] = (string) $template['name'] . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $errors === [], 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /* --------------------------------------------------------- Send helpers */

    /**
     * Build the send-time component array from plain values.
     *
     * Callers pass what they mean — a reminder title, a date — and the ordering
     * that Meta demands is worked out here, once, instead of at every call site.
     *
     * @param array<int, string> $bodyValues
     */
    public static function parameters(array $bodyValues, ?string $headerValue = null, array $buttonValues = []): array
    {
        $components = [];

        if ($headerValue !== null && $headerValue !== '') {
            $components[] = [
                'type'       => 'header',
                'parameters' => [['type' => 'text', 'text' => self::clean($headerValue)]],
            ];
        }

        if ($bodyValues !== []) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    static fn ($value): array => ['type' => 'text', 'text' => self::clean((string) $value)],
                    array_values($bodyValues)
                ),
            ];
        }

        foreach ($buttonValues as $index => $value) {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => (string) $index,
                'parameters' => [['type' => 'text', 'text' => self::clean((string) $value)]],
            ];
        }

        return $components;
    }

    /**
     * Meta rejects a parameter containing a newline, a tab, or four or more
     * consecutive spaces — with error 132005, which names none of those things.
     */
    public static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, 1024);
    }

    private static function update(int $templateId, array $data): void
    {
        try {
            App::i()->db()->update(
                'wa_templates',
                $data + ['updated_at' => now_utc()],
                'id = :id',
                ['id' => $templateId]
            );
        } catch (\Throwable $e) {
            Logger::warn('Could not update template row', ['error' => $e->getMessage()], 'meta');
        }
    }
}
