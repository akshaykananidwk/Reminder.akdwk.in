<?php

namespace App\Jobs;

use App\Services\WhatsAppService;

/**
 * Drains the outbound message queue — WhatsApp and Telegram share it — at the
 * configured per-minute rate, with retries and exponential backoff.
 *
 * Nothing in a request path ever talks to Meta directly; everything the product
 * sends lands in `wa_outbound_queue` and leaves through here. That is what
 * makes a slow Graph API a slow queue rather than a slow website.
 */
class MessageQueueJob extends Job
{
    public static function key(): string
    {
        return 'wa_queue';
    }

    public static function label(): string
    {
        return 'WhatsApp / Telegram queue';
    }

    public static function description(): string
    {
        return 'Sends everything waiting in the outbound message queue, at the configured rate, with retries.';
    }

    public static function group(): string
    {
        return 'messaging';
    }

    public static function priority(): int
    {
        return 2;
    }

    public static function intervalSeconds(): int
    {
        return 60;
    }

    public static function timeoutSeconds(): int
    {
        return 120;
    }

    public function handle(): array
    {
        // At the default 30/minute rate, 40 is comfortably more than one tick.
        $result = WhatsAppService::processQueue(40);

        return [
            'processed' => $result['sent'] + $result['failed'],
            'message'   => sprintf('sent=%d failed=%d', $result['sent'], $result['failed']),
        ];
    }
}
