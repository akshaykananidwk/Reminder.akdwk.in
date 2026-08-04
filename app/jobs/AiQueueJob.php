<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Lang;
use App\Core\Logger;
use App\Services\GeminiService;
use App\Services\InboundProcessor;
use App\Services\TelegramService;
use App\Services\WhatsAppService;

/**
 * Turns inbound messages into reminders, notes and payments.
 *
 * A quick command, a Gemini parse, or the regex fallback — then the reply goes
 * out on the channel the message arrived on. Replying on the wrong channel is
 * as bad as not replying: the user is sitting in Telegram, not staring at
 * WhatsApp.
 */
class AiQueueJob extends Job
{
    public static function key(): string
    {
        return 'ai_queue';
    }

    public static function label(): string
    {
        return 'Inbound message processing';
    }

    public static function description(): string
    {
        return 'Understands incoming WhatsApp and Telegram messages and replies with what was created.';
    }

    public static function group(): string
    {
        return 'messaging';
    }

    public static function priority(): int
    {
        return 3;
    }

    public static function intervalSeconds(): int
    {
        return 60;
    }

    public static function timeoutSeconds(): int
    {
        return 240;
    }

    public function handle(): array
    {
        $db = App::i()->db();
        $processed = 0;
        $failed = 0;

        $jobs = $db->all(
            'SELECT * FROM ai_queue WHERE status = "pending" AND attempts < 3 ORDER BY id ASC LIMIT 25'
        );

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];

            // Claim the row so a parallel run cannot answer the same message twice.
            $claimed = $db->query(
                'UPDATE ai_queue SET status = "processing", attempts = attempts + 1 WHERE id = ? AND status = "pending"',
                [$jobId]
            )->rowCount();

            if ($claimed === 0) {
                continue;
            }

            try {
                $this->processOne($job, $jobId);
                $processed++;
            } catch (\Throwable $e) {
                Logger::exception($e, 'ai_queue');

                $db->update('ai_queue', [
                    'status' => (int) $job['attempts'] >= 2 ? 'failed' : 'pending',
                    'error'  => mb_substr($e->getMessage(), 0, 500),
                ], 'id = :id', ['id' => $jobId]);

                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'message'   => sprintf('processed=%d failed=%d', $processed, $failed),
        ];
    }

    private function processOne(array $job, int $jobId): void
    {
        $db = App::i()->db();
        $user = $db->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $job['user_id']]);

        if ($user === null) {
            $db->update('ai_queue', [
                'status'       => 'failed',
                'error'        => 'User not found',
                'processed_at' => now_utc(),
            ], 'id = :id', ['id' => $jobId]);

            return;
        }

        Lang::setLocale((string) $user['language']);

        $mediaUrl = (string) ($job['media_url'] ?? '');

        if ($mediaUrl !== '') {
            // Bill photo → Gemini Vision → payment reminder.
            $parsed = GeminiService::parseImage($mediaUrl, $user);

            $outcome = $parsed['ok']
                ? InboundProcessor::applyEnvelope(
                    $parsed['data'],
                    $user,
                    (string) $job['source'],
                    (string) $job['inbound_id'],
                    'gemini',
                    (string) $job['text']
                )
                : ['handled_by' => 'fallback', 'reply' => Lang::get('wa.not_understood'), 'result' => []];
        } else {
            $outcome = InboundProcessor::process((string) $job['text'], $user, [
                'source'     => (string) $job['source'],
                'source_ref' => $job['inbound_id'] === null ? null : (string) $job['inbound_id'],
            ]);
        }

        if ($outcome['reply'] !== '') {
            $this->reply($job, $user, $outcome['reply']);
        }

        $db->update('ai_queue', [
            'status'       => 'done',
            'result'       => json_encode($outcome['result'], JSON_UNESCAPED_UNICODE),
            'processed_at' => now_utc(),
        ], 'id = :id', ['id' => $jobId]);

        if ($job['inbound_id'] !== null) {
            $db->update('wa_inbound_raw', [
                'processed'    => 1,
                'handled_by'   => $outcome['handled_by'],
                'ai_result'    => json_encode($outcome['result'], JSON_UNESCAPED_UNICODE),
                'reply_sent'   => mb_substr($outcome['reply'], 0, 4000),
                'processed_at' => now_utc(),
            ], 'id = :id', ['id' => (int) $job['inbound_id']]);
        }
    }

    /** Answer on the channel the message arrived on. */
    private function reply(array $job, array $user, string $reply): void
    {
        if ((string) $job['source'] !== 'telegram') {
            WhatsAppService::queue((string) $user['phone'], $reply, (int) $user['id'], null, 3);

            return;
        }

        // Sent straight out rather than queued: a chat expects an answer now,
        // and Telegram has no rate limit to respect. If it fails, fall back to
        // the queue so the reply is not lost.
        $sent = TelegramService::sendToUser((int) $user['id'], $reply);

        if (!$sent['ok']) {
            Logger::warn('Telegram reply failed, queueing instead', [
                'user_id' => (int) $user['id'],
                'error'   => $sent['response'],
            ], 'telegram');

            WhatsAppService::queueTelegram((int) $user['id'], $reply, null, 3);
        }
    }
}
