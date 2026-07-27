<?php

/**
 * Inbound WhatsApp webhook.
 *
 * Point the gateway's outbound webhook at:
 *   https://reminder.akdwk.in/api/wa_webhook.php?secret=XXXXXXXX
 *
 * Contract (Section 6.3):
 *   1. Verify the shared secret / HMAC signature, else 401.
 *   2. Answer 200 in well under a second — all real work is queued.
 *   3. Normalise the phone number.
 *   4. Only verified, active numbers are processed; everything else is logged.
 *   5. Deduplicate by the gateway message id.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\WhatsAppService;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$app = App::i();

if (!$app->isInstalled()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Not installed']);
    exit;
}

/* ------------------------------------------------------------ 1. Auth ---- */

$expected = (string) $app->config('security.webhook_secret', '');
$given = (string) (Request::get('secret', '') ?? '');
$raw = Request::rawBody();
$signature = Request::header('X-Signature') ?? Request::header('X-Hub-Signature-256');

$authorised = false;

if ($expected !== '') {
    if ($given !== '' && hash_equals($expected, $given)) {
        $authorised = true;
    } elseif (is_string($signature) && $signature !== '') {
        $computed = Crypto::hmac($raw, $expected);
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $authorised = hash_equals($computed, $provided);
    }
}

if (!$authorised) {
    RateLimiter::attempt('webhook_bad_' . Request::ip(), 20, 300);
    Logger::warn('Webhook rejected', ['ip' => Request::ip()], 'whatsapp');

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised']);
    exit;
}

/* --------------------------------------------------- 2. Answer fast ------ */

// Everything after this point is bookkeeping; the gateway gets its 200 first.
$payload = json_decode($raw, true);

if (!is_array($payload) || $payload === []) {
    $payload = $_POST;
}

http_response_code(200);
echo json_encode(['success' => true]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    // Flush what we have so the gateway is not kept waiting.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

ignore_user_abort(true);

/* ------------------------------------------------- 3-6. Process ---------- */

try {
    $message = WhatsAppService::parseInbound(is_array($payload) ? $payload : []);

    if ($message === null) {
        Logger::info('Webhook payload without a sender', ['payload' => mb_substr($raw, 0, 500)], 'whatsapp');
        exit;
    }

    $db = $app->db();

    // Deduplicate by gateway message id.
    if ($message['message_id'] !== null) {
        $existing = $db->one('SELECT id FROM wa_inbound_raw WHERE gateway_message_id = ?', [$message['message_id']]);

        if ($existing !== null) {
            exit;
        }
    }

    // Ignore our own outbound echoes.
    $senderNumber = normalize_phone((string) $app->settings()->get('wa_sender_number', ''));

    if ($senderNumber !== '' && $message['from'] === $senderNumber) {
        exit;
    }

    // Basic flood protection per number.
    if (!RateLimiter::attempt('inbound_' . $message['from'], 60, 3600)) {
        Logger::warn('Inbound flood', ['number' => $message['from']], 'whatsapp');
        exit;
    }

    $resolved = WhatsAppService::resolveSender($message['from']);

    // --- Unregistered number: log, optional one-time invite, stop. ---------
    if ($resolved === null) {
        WhatsAppService::handleUnknown($message['from'], $message['body']);
        exit;
    }

    $user = $resolved['user'];

    $inboundId = $db->insert('wa_inbound_raw', [
        'user_id'            => (int) $user['id'],
        'from_number'        => $message['from'],
        'gateway_message_id' => $message['message_id'],
        'message_type'       => $message['type'],
        'body'               => mb_substr($message['body'], 0, 5000),
        'media_url'          => $message['media_url'],
        'payload'            => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'processed'          => 0,
        'received_at'        => now_utc(),
    ]);

    // Nothing to parse (e.g. a sticker) — acknowledge and stop.
    if (trim($message['body']) === '' && $message['media_url'] === null) {
        $db->update('wa_inbound_raw', [
            'processed'    => 1,
            'handled_by'   => 'ignored',
            'processed_at' => now_utc(),
        ], 'id = :id', ['id' => $inboundId]);
        exit;
    }

    $db->insert('ai_queue', [
        'user_id'    => (int) $user['id'],
        'inbound_id' => $inboundId,
        'source'     => 'whatsapp',
        'text'       => mb_substr($message['body'], 0, 5000),
        'media_url'  => $message['media_url'],
        'status'     => 'pending',
        'created_at' => now_utc(),
    ]);
} catch (Throwable $e) {
    Logger::exception($e, 'whatsapp');
}
