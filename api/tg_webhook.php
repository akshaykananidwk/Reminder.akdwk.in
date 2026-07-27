<?php

/**
 * Inbound Telegram webhook.
 *
 * Registered automatically from Admin → Telegram → "Register webhook", which
 * calls setWebhook with a secret token. Telegram echoes that token back in
 * X-Telegram-Bot-Api-Secret-Token on every update, and nothing without it is
 * accepted.
 *
 * Behaves exactly like the WhatsApp webhook after authentication:
 *   1. Verify, else 401.
 *   2. Answer 200 immediately — all real work happens after the response.
 *   3. Only linked, active accounts are processed.
 *   4. Deduplicate by the Telegram message id.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\TelegramService;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$app = App::i();

if (!$app->isInstalled()) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}

/* ------------------------------------------------------------ 1. Auth ---- */

if (!TelegramService::verifySecret(Request::header('X-Telegram-Bot-Api-Secret-Token'))) {
    RateLimiter::attempt('tg_webhook_bad_' . Request::ip(), 20, 300);
    Logger::warn('Telegram webhook rejected', ['ip' => Request::ip()], 'telegram');

    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

/* --------------------------------------------------- 2. Answer fast ------ */

$raw = Request::rawBody();
$update = json_decode($raw, true);

http_response_code(200);
echo json_encode(['ok' => true]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    flush();
}

ignore_user_abort(true);

/* ------------------------------------------------------- 3-4. Process ---- */

try {
    $message = TelegramService::parseUpdate(is_array($update) ? $update : []);

    if ($message === null) {
        exit;
    }

    $db = $app->db();
    $chatId = $message['chat_id'];

    /* --- /start <code>: link this chat to an account ------------------- */
    if ($message['is_start']) {
        if ($message['start_payload'] === '') {
            TelegramService::send(
                $chatId,
                "🙏 *Krishna Reminder*\n\n"
                . "To connect this chat to your account, open the website, go to "
                . "*Settings → Telegram*, and press *Connect Telegram*. "
                . "It will give you a command to send here."
            );
            exit;
        }

        // Guessing a link code should not be cheap.
        if (!RateLimiter::attempt('tg_link_' . $chatId, 8, 900)) {
            TelegramService::send($chatId, 'Too many attempts. Please try again in a few minutes.');
            exit;
        }

        $result = TelegramService::redeemLinkCode($message['start_payload'], $chatId, $message['username']);

        if (!$result['ok']) {
            TelegramService::send($chatId, '⚠️ ' . $result['message']);
            exit;
        }

        $user = $result['user'];

        TelegramService::send(
            $chatId,
            "✅ *Connected!*\n\n"
            . "Hello " . (string) ($user['name'] ?? '') . " — your reminders will now arrive here as well.\n\n"
            . "You can also just message me, for example:\n"
            . "_\"Call the bank tomorrow at 10 am\"_"
        );

        exit;
    }

    /* --- An ordinary message from a linked account --------------------- */
    $user = TelegramService::userByChatId($chatId);

    if ($user === null) {
        TelegramService::send(
            $chatId,
            "This chat is not connected to an account yet.\n\n"
            . "Open the website → *Settings → Telegram* → *Connect Telegram*, "
            . "and send me the command it shows you."
        );

        exit;
    }

    if (trim($message['text']) === '') {
        exit;
    }

    // Deduplicate: Telegram retries an update until it gets a 200, and a slow
    // response would otherwise create the same reminder twice.
    $gatewayId = $message['message_id'] === null ? null : 'tg_' . $chatId . '_' . $message['message_id'];

    if ($gatewayId !== null) {
        $existing = $db->one('SELECT id FROM wa_inbound_raw WHERE gateway_message_id = ?', [$gatewayId]);

        if ($existing !== null) {
            exit;
        }
    }

    if (!RateLimiter::attempt('tg_inbound_' . $chatId, 60, 3600)) {
        Logger::warn('Telegram inbound flood', ['chat_id' => $chatId], 'telegram');
        exit;
    }

    $inboundId = $db->insert('wa_inbound_raw', [
        'user_id'            => (int) $user['id'],
        // The chat id stands in for the number on this channel.
        'from_number'        => mb_substr($chatId, 0, 20),
        'gateway_message_id' => $gatewayId,
        'message_type'       => 'text',
        'body'               => mb_substr($message['text'], 0, 5000),
        'media_url'          => null,
        'payload'            => json_encode($update, JSON_UNESCAPED_UNICODE),
        'processed'          => 0,
        'received_at'        => now_utc(),
    ]);

    $db->insert('ai_queue', [
        'user_id'    => (int) $user['id'],
        'inbound_id' => $inboundId,
        'source'     => 'telegram',
        'text'       => mb_substr($message['text'], 0, 5000),
        'media_url'  => null,
        'status'     => 'pending',
        'created_at' => now_utc(),
    ]);
} catch (Throwable $e) {
    Logger::exception($e, 'telegram');
}
