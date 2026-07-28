<?php

/**
 * cron/ai_queue.php — every 1 minute.
 *
 * Processes pending inbound WhatsApp messages: quick command, Gemini parse or
 * regex fallback, then queues the confirmation reply.
 *
 *   * * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/ai_queue.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\GeminiService;
use App\Services\InboundProcessor;
use App\Services\TelegramService;
use App\Services\WhatsAppService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('ai_queue', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$db = App::i()->db();
$processed = 0;
$failed = 0;
$status = 'ok';
$message = '';

try {
    $jobs = $db->all(
        'SELECT * FROM ai_queue WHERE status = "pending" AND attempts < 3 ORDER BY id ASC LIMIT 25'
    );

    foreach ($jobs as $job) {
        $jobId = (int) $job['id'];

        // Claim the job.
        $claimed = $db->query(
            'UPDATE ai_queue SET status = "processing", attempts = attempts + 1 WHERE id = ? AND status = "pending"',
            [$jobId]
        )->rowCount();

        if ($claimed === 0) {
            continue;
        }

        try {
            $user = $db->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $job['user_id']]);

            if ($user === null) {
                $db->update('ai_queue', ['status' => 'failed', 'error' => 'User not found', 'processed_at' => now_utc()], 'id = :id', ['id' => $jobId]);
                continue;
            }

            \App\Core\Lang::setLocale((string) $user['language']);

            $mediaUrl = (string) ($job['media_url'] ?? '');

            if ($mediaUrl !== '') {
                // Bill photo -> Gemini Vision -> payment reminder.
                $parsed = GeminiService::parseImage($mediaUrl, $user);

                $outcome = $parsed['ok']
                    ? InboundProcessor::applyEnvelope($parsed['data'], $user, (string) $job['source'], (string) $job['inbound_id'], 'gemini', (string) $job['text'])
                    : ['handled_by' => 'fallback', 'reply' => \App\Core\Lang::get('wa.not_understood'), 'result' => []];
            } else {
                $outcome = InboundProcessor::process((string) $job['text'], $user, [
                    'source'     => (string) $job['source'],
                    'source_ref' => $job['inbound_id'] === null ? null : (string) $job['inbound_id'],
                ]);
            }

            /*
             * Answer on the channel the message arrived on.
             *
             * This used to be `source === 'whatsapp'` only, so a Telegram
             * message created the reminder and then got no reply at all —
             * nothing to confirm what was understood, or even that anything had
             * happened. Replying on the wrong channel would be just as bad: the
             * user is sitting in Telegram, not staring at WhatsApp.
             */
            if ($outcome['reply'] !== '') {
                if ((string) $job['source'] === 'telegram') {
                    // Sent straight out rather than queued: a chat expects an
                    // answer now, and Telegram has no rate limit to respect.
                    // If it fails, fall back to the queue so it is not lost.
                    $sent = TelegramService::sendToUser((int) $user['id'], $outcome['reply']);

                    if (!$sent['ok']) {
                        Logger::warn('Telegram reply failed, queueing instead', [
                            'user_id' => (int) $user['id'],
                            'error'   => $sent['response'],
                        ], 'telegram');

                        WhatsAppService::queueTelegram((int) $user['id'], $outcome['reply'], null, 3);
                    }
                } else {
                    WhatsAppService::queue(
                        (string) $user['phone'],
                        $outcome['reply'],
                        (int) $user['id'],
                        null,
                        3
                    );
                }
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

            $processed++;
        } catch (Throwable $e) {
            Logger::exception($e, 'ai_queue');

            $db->update('ai_queue', [
                'status' => (int) $job['attempts'] >= 2 ? 'failed' : 'pending',
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ], 'id = :id', ['id' => $jobId]);

            $failed++;
        }
    }

    $message = sprintf('processed=%d failed=%d', $processed, $failed);
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'ai_queue');
}

CronService::finish($processed, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[ai_queue] ' . $message . PHP_EOL;
}
