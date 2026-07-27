<?php

/**
 * Inbound WhatsApp webhook — serves both providers on one URL.
 *
 * bulk.akdwk.in gateway:
 *   https://reminder.akdwk.in/api/wa_webhook.php?secret=XXXXXXXX
 *
 * Meta WhatsApp Cloud API (Meta → WhatsApp → Configuration → Webhook):
 *   Callback URL:  https://reminder.akdwk.in/api/wa_webhook.php
 *   Verify token:  whatever is set in Admin → WhatsApp → Cloud API
 *   Then subscribe to the `messages` field.
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
use App\Services\MetaCloudService;
use App\Services\WhatsAppService;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$app = App::i();

if (!$app->isInstalled()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Not installed']);
    exit;
}

/* ------------------------------------- 0. Meta subscription handshake ---- */

// Meta GETs this URL once when the webhook is saved and expects hub.challenge
// echoed back as plain text. Nothing else about this endpoint answers GET.
if (Request::method() === 'GET' && (Request::get('hub_mode') !== null || isset($_GET['hub.mode']))) {
    $challenge = MetaCloudService::verifySubscription($_GET);

    if ($challenge === null) {
        RateLimiter::attempt('webhook_bad_' . Request::ip(), 20, 300);
        Logger::warn('Cloud API webhook verification failed', ['ip' => Request::ip()], 'whatsapp');

        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Verification failed']);
        exit;
    }

    Logger::info('Cloud API webhook verified', [], 'whatsapp');

    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

/* ------------------------------------------------------------ 1. Auth ---- */

$expected = (string) $app->config('security.webhook_secret', '');
$given = (string) (Request::get('secret', '') ?? '');
$raw = Request::rawBody();
$signature = Request::header('X-Signature') ?? Request::header('X-Hub-Signature-256');

$payload = json_decode($raw, true);

if (!is_array($payload) || $payload === []) {
    $payload = $_POST;
}

$isCloud = is_array($payload) && MetaCloudService::isCloudPayload($payload);
$authorised = false;

if ($isCloud) {
    // Meta signs the body with the *app secret*, not the verify token and not
    // our own webhook secret, so it gets its own check.
    $authorised = MetaCloudService::verifySignature($raw, is_string($signature) ? $signature : null);

    if (!$authorised && trim((string) $app->settings()->get('wa_cloud_app_secret', '')) === '') {
        // No app secret stored yet: accept, but say so loudly rather than
        // pretending the endpoint is authenticated.
        $authorised = true;
        Logger::warn(
            'Cloud API webhook accepted WITHOUT signature verification — set the app secret in Admin → WhatsApp',
            ['ip' => Request::ip()],
            'whatsapp'
        );
    }
} elseif ($expected !== '') {
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
    Logger::warn('Webhook rejected', ['ip' => Request::ip(), 'cloud' => $isCloud], 'whatsapp');

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised']);
    exit;
}

/* --------------------------------------------------- 2. Answer fast ------ */

// Everything after this point is bookkeeping; the gateway gets its 200 first.

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
    $payload = is_array($payload) ? $payload : [];

    // Meta nests the message inside entry[].changes[].value.messages[], and
    // sends delivery/read receipts to the same URL. Those carry no message and
    // are not an error — they are simply nothing to do.
    $message = $isCloud
        ? MetaCloudService::parseInbound($payload)
        : WhatsAppService::parseInbound($payload);

    if ($message === null) {
        if (!$isCloud) {
            Logger::info('Webhook payload without a sender', ['payload' => mb_substr($raw, 0, 500)], 'whatsapp');
        }

        exit;
    }

    // Cloud API media arrives as an id; exchange it for a URL now, because that
    // URL is short-lived and the id alone is useless to the rest of the app.
    if ($isCloud && $message['media_url'] === null) {
        $mediaId = $payload['entry'][0]['changes'][0]['value']['messages'][0][$message['type']]['id'] ?? null;

        if (is_string($mediaId) && $mediaId !== '') {
            $message['media_url'] = MetaCloudService::mediaUrl($mediaId);
        }
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
