<?php

namespace App\Services;

use App\Core\App;
use App\Core\Lang;
use App\Core\Logger;

/**
 * Turns one inbound WhatsApp message into reminders, notes or actions, and
 * composes the confirmation reply.
 *
 * Called from cron/ai_queue.php (asynchronously) so the webhook itself always
 * answers in well under a second.
 */
class InboundProcessor
{
    /**
     * @return array{handled_by: string, reply: string, result: array}
     */
    public static function process(string $text, array $user, array $context = []): array
    {
        $lang = (string) ($user['language'] ?? 'gu');
        $source = (string) ($context['source'] ?? 'whatsapp');
        $sourceRef = $context['source_ref'] ?? null;

        // 1. Quick commands — instant, free.
        $command = CommandService::handle($text, $user);

        if ($command['handled']) {
            return [
                'handled_by' => 'command',
                'reply'      => $command['reply'],
                'result'     => ['action' => $command['action']],
            ];
        }

        // 2. Paused account: acknowledge but do not create anything.
        if ((int) ($user['reminders_paused'] ?? 0) === 1) {
            return [
                'handled_by' => 'ignored',
                'reply'      => Lang::get('wa.paused_hint', [], $lang),
                'result'     => ['action' => 'paused'],
            ];
        }

        // 3. AI (or the regex fallback when AI is unavailable).
        $parsed = GeminiService::parse($text, $user, [
            'default_time' => substr((string) (ReminderService::userSettings((int) $user['id'])['default_time'] ?? '09:00:00'), 0, 5),
        ]);

        $envelope = $parsed['data'];

        return self::applyEnvelope($envelope, $user, $source, $sourceRef, $parsed['source'], $text);
    }

    /**
     * Execute the intent described by a parsed envelope.
     */
    public static function applyEnvelope(array $envelope, array $user, string $source, ?string $sourceRef, string $parserSource, string $originalText): array
    {
        $lang = (string) ($envelope['language'] ?? $user['language'] ?? 'gu');
        $userId = (int) $user['id'];
        $intent = (string) ($envelope['intent'] ?? 'unknown');

        // The model may answer a question instead of acting.
        if (!empty($envelope['needs_confirmation']) && empty($envelope['items'][0]['due_at'])) {
            self::saveAsNote($userId, $originalText, $sourceRef);

            return [
                'handled_by' => $parserSource === 'gemini' ? 'ai' : 'fallback',
                'reply'      => (string) ($envelope['question'] ?: Lang::get('wa.need_time', [], $lang)),
                'result'     => ['action' => 'question', 'envelope' => $envelope],
            ];
        }

        switch ($intent) {
            case 'list':
                $reply = CommandService::handle('LIST', $user)['reply'];

                return ['handled_by' => 'ai', 'reply' => $reply, 'result' => ['action' => 'list']];

            case 'summary':
                $reply = SummaryService::buildNightSummary($userId, $user);

                return ['handled_by' => 'ai', 'reply' => $reply, 'result' => ['action' => 'summary']];

            case 'help':
                return ['handled_by' => 'ai', 'reply' => TemplateService::render('help', $lang), 'result' => ['action' => 'help']];

            case 'complete':
            case 'snooze':
            case 'cancel':
                $code = $envelope['reference']['short_code'] ?? null;

                if ($code !== null && $code !== '') {
                    $verb = match ($intent) {
                        'complete' => 'DONE',
                        'snooze'   => 'SNOOZE',
                        default    => 'CANCEL',
                    };

                    $result = CommandService::handle($verb . ' ' . $code, $user);

                    if ($result['handled']) {
                        return ['handled_by' => 'ai', 'reply' => $result['reply'], 'result' => ['action' => $intent]];
                    }
                }

                return [
                    'handled_by' => 'ai',
                    'reply'      => Lang::get('wa.which_one', [], $lang),
                    'result'     => ['action' => $intent],
                ];

            case 'note':
                self::saveAsNote($userId, $originalText, $sourceRef);

                return [
                    'handled_by' => $parserSource === 'gemini' ? 'ai' : 'fallback',
                    'reply'      => Lang::get('wa.note_saved', [], $lang),
                    'result'     => ['action' => 'note'],
                ];
        }

        // create / payment / update -> build reminders
        $items = $envelope['items'] ?? [];

        if ($items === []) {
            self::saveAsNote($userId, $originalText, $sourceRef);

            return [
                'handled_by' => 'fallback',
                'reply'      => Lang::get('wa.not_understood', [], $lang),
                'result'     => ['action' => 'note'],
            ];
        }

        $created = [];
        $duplicates = [];

        foreach ($items as $item) {
            if (empty($item['due_at'])) {
                self::saveAsNote($userId, (string) $item['title'], $sourceRef);
                continue;
            }

            $startUtc = ReminderService::isoToUtc((string) $item['due_at']);

            if ($startUtc === null) {
                continue;
            }

            $duplicate = ReminderService::findDuplicate($userId, (string) $item['title'], $startUtc);

            if ($duplicate !== null) {
                $duplicates[] = $duplicate;
                continue;
            }

            $reminder = ReminderService::createFromParsed($item, $user, $source, $sourceRef);

            if ($reminder !== null) {
                $created[] = $reminder;
            }
        }

        if ($created === [] && $duplicates !== []) {
            $reminder = $duplicates[0];

            return [
                'handled_by' => 'ai',
                'reply'      => Lang::get('wa.duplicate', [
                    'code'  => (string) $reminder['short_code'],
                    'title' => (string) $reminder['title'],
                    'time'  => ReminderService::formatWhen((string) $reminder['start_at'], $lang, (string) $user['timezone']),
                ], $lang),
                'result' => ['action' => 'duplicate', 'reminder_id' => (int) $reminder['id']],
            ];
        }

        if ($created === []) {
            return [
                'handled_by' => 'fallback',
                'reply'      => Lang::get('wa.quota_or_error', [], $lang),
                'result'     => ['action' => 'none'],
            ];
        }

        return [
            'handled_by' => $parserSource === 'gemini' ? 'ai' : 'fallback',
            'reply'      => self::buildConfirmation($created, $user, $lang, (string) ($envelope['reply_text'] ?? '')),
            'result'     => [
                'action'       => 'created',
                'reminder_ids' => array_map(static fn ($r) => (int) $r['id'], $created),
            ],
        ];
    }

    /**
     * Short confirmation exactly as specified in Section 6.5.
     */
    private static function buildConfirmation(array $reminders, array $user, string $lang, string $aiReply): string
    {
        $tz = (string) ($user['timezone'] ?? 'Asia/Kolkata');

        if (count($reminders) === 1) {
            $reminder = $reminders[0];

            $body = TemplateService::render('reminder_created', $lang, [
                'code'  => (string) $reminder['short_code'],
                'title' => (string) $reminder['title'],
                'time'  => ReminderService::formatWhen((string) $reminder['start_at'], $lang, $tz),
            ]);

            return $body !== '' ? $body : ($aiReply !== '' ? $aiReply : (string) $reminder['title']);
        }

        $lines = [Lang::get('wa.created_multiple', ['count' => count($reminders)], $lang), ''];

        foreach ($reminders as $reminder) {
            $lines[] = sprintf(
                '• %s — %s  [%s]',
                ReminderService::formatWhen((string) $reminder['start_at'], $lang, $tz),
                str_limit((string) $reminder['title'], 60),
                (string) $reminder['short_code']
            );
        }

        return implode("\n", $lines);
    }

    private static function saveAsNote(int $userId, string $body, ?string $sourceRef): void
    {
        $body = trim($body);

        if ($body === '') {
            return;
        }

        try {
            App::i()->db()->insert('notes', [
                'user_id'    => $userId,
                'title'      => mb_substr($body, 0, 80),
                'body'       => mb_substr($body, 0, 5000),
                'source'     => 'whatsapp',
                'source_ref' => $sourceRef,
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Failed to save note', ['error' => $e->getMessage()], 'inbound');
        }
    }
}
