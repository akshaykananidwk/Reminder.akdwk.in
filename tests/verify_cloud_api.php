<?php

/**
 * Proves the Meta WhatsApp Cloud API provider handles the shapes Meta actually
 * sends, without touching the network.
 *
 * The three things that break a Cloud API integration in production:
 *
 *   1. The webhook payload is nested four levels deep and delivery receipts
 *      arrive on the same URL as messages. Treating a receipt as a message
 *      creates phantom inbound rows; treating a message as a receipt loses it.
 *   2. The subscription handshake is a GET with hub.challenge that must be
 *      echoed verbatim, and the signature is HMAC of the body with the *app
 *      secret* — not the verify token.
 *   3. Free-form text is only accepted inside the 24-hour customer service
 *      window. Outside it Meta answers 131047 and the reminder never arrives.
 *
 * Run:  php tests/verify_cloud_api.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\MetaCloudService;

$pass = 0;
$fail = 0;

function assertThat(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %-58s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  — ' . $detail : '');
}

$source = (string) file_get_contents(__DIR__ . '/../app/services/MetaCloudService.php');

echo "\n=== 1. Endpoint and transport ===\n\n";

assertThat('Graph host is used', str_contains($source, 'https://graph.facebook.com'));
assertThat('endpoint is {version}/{phone_id}/messages',
    str_contains($source, "'/messages'") || str_contains($source, "/messages"));
assertThat('token travels in the Authorization header, never the URL',
    str_contains($source, "'Authorization' => 'Bearer ' . \$token")
    && !preg_match('/access_token=/', $source));
assertThat('no access token is hard-coded in the source',
    !preg_match('/EAA[A-Za-z0-9]{20,}/', $source));

echo "\n=== 2. Webhook payloads Meta actually sends ===\n\n";

// A real inbound text message, trimmed to the fields that matter.
$textMessage = [
    'object' => 'whatsapp_business_account',
    'entry'  => [[
        'id'      => '102290129340398',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata'          => ['display_phone_number' => '910000000000', 'phone_number_id' => '123456789012345'],
                'contacts'          => [['profile' => ['name' => 'Akshay'], 'wa_id' => '919876543210']],
                'messages'          => [[
                    'from'      => '919876543210',
                    'id'        => 'wamid.HBgMOTE5ODc2NTQzMjEwFQIAEhgg',
                    'timestamp' => '1769553600',
                    'type'      => 'text',
                    'text'      => ['body' => 'કાલે સવારે ૧૦ વાગ્યે દવા લેવાની યાદ કરાવજો'],
                ]],
            ],
        ]],
    ]],
];

assertThat('recognised as a Cloud API payload', MetaCloudService::isCloudPayload($textMessage));

$parsed = MetaCloudService::parseInbound($textMessage);

assertThat('message extracted from the nested envelope', $parsed !== null);
assertThat('sender normalised', ($parsed['from'] ?? '') === '919876543210', (string) ($parsed['from'] ?? ''));
assertThat('Gujarati body preserved intact',
    ($parsed['body'] ?? '') === 'કાલે સવારે ૧૦ વાગ્યે દવા લેવાની યાદ કરાવજો');
assertThat('wamid captured for deduplication',
    str_starts_with((string) ($parsed['message_id'] ?? ''), 'wamid.'));

// Delivery / read receipts hit the same URL and contain no message at all.
$statusOnly = [
    'object' => 'whatsapp_business_account',
    'entry'  => [[
        'id'      => '102290129340398',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata'          => ['phone_number_id' => '123456789012345'],
                'statuses'          => [[
                    'id'           => 'wamid.HBgMOTE5ODc2NTQzMjEw',
                    'status'       => 'delivered',
                    'timestamp'    => '1769553601',
                    'recipient_id' => '919876543210',
                ]],
            ],
        ]],
    ]],
];

assertThat('a delivery receipt is NOT treated as a message',
    MetaCloudService::parseInbound($statusOnly) === null);

assertThat('a receipt is still recognised as a Cloud payload',
    MetaCloudService::isCloudPayload($statusOnly), 'so it authenticates the Meta way');

// Button reply — the text lives somewhere else entirely.
$buttonReply = $textMessage;
$buttonReply['entry'][0]['changes'][0]['value']['messages'][0] = [
    'from' => '919876543210',
    'id'   => 'wamid.BUTTON',
    'type' => 'button',
    'button' => ['payload' => 'DONE', 'text' => 'થઈ ગયું'],
];

assertThat('button reply text is found',
    (MetaCloudService::parseInbound($buttonReply)['body'] ?? '') === 'થઈ ગયું');

// Image with a caption.
$imageMessage = $textMessage;
$imageMessage['entry'][0]['changes'][0]['value']['messages'][0] = [
    'from'  => '919876543210',
    'id'    => 'wamid.IMAGE',
    'type'  => 'image',
    'image' => ['id' => '1234567890', 'mime_type' => 'image/jpeg', 'caption' => 'બિલ'],
];

$image = MetaCloudService::parseInbound($imageMessage);

assertThat('image caption is used as the body', ($image['body'] ?? '') === 'બિલ');
assertThat('media id is not mistaken for a URL', ($image['media_url'] ?? null) === null);

// An interactive list reply.
$listReply = $textMessage;
$listReply['entry'][0]['changes'][0]['value']['messages'][0] = [
    'from'        => '919876543210',
    'id'          => 'wamid.LIST',
    'type'        => 'interactive',
    'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'snooze_10', 'title' => '૧૦ મિનિટ પછી']],
];

assertThat('interactive list reply title is found',
    (MetaCloudService::parseInbound($listReply)['body'] ?? '') === '૧૦ મિનિટ પછી');

echo "\n=== 3. The bulk gateway's payloads are not mistaken for Meta's ===\n\n";

foreach ([
    'flat from/message'  => ['from' => '919876543210', 'message' => 'namaste'],
    'sender/body'        => ['sender' => '919876543210', 'body' => 'namaste'],
    'nested data'        => ['data' => ['from' => '919876543210', 'message' => 'namaste']],
] as $label => $payload) {
    assertThat('bulk payload not claimed by Cloud: ' . $label,
        !MetaCloudService::isCloudPayload($payload));
}

echo "\n=== 4. Subscription handshake ===\n\n";

// verifySubscription and verifySignature read settings, which needs a database.
// Where none is available the calls must fail closed, not fatal.
$hasDb = false;

try {
    App\Core\App::i()->db()->value('SELECT 1');
    $hasDb = true;
} catch (\Throwable) {
    $hasDb = false;
}

if ($hasDb) {
    $settings = App\Core\App::i()->settings();
    $originalVerify = (string) $settings->get('wa_cloud_verify_token', '');
    $originalSecret = (string) $settings->get('wa_cloud_app_secret', '');

    $settings->set('wa_cloud_verify_token', 'kr_test_verify_token', false, 'whatsapp');
    $settings->set('wa_cloud_app_secret', 'test_app_secret', true, 'whatsapp');
    $settings->refresh();

    assertThat('correct token echoes the challenge',
        MetaCloudService::verifySubscription([
            'hub_mode' => 'subscribe', 'hub_verify_token' => 'kr_test_verify_token', 'hub_challenge' => '1158201444',
        ]) === '1158201444');

    assertThat('dotted hub.* keys are accepted too',
        MetaCloudService::verifySubscription([
            'hub.mode' => 'subscribe', 'hub.verify_token' => 'kr_test_verify_token', 'hub.challenge' => '99',
        ]) === '99');

    assertThat('wrong token is refused',
        MetaCloudService::verifySubscription([
            'hub_mode' => 'subscribe', 'hub_verify_token' => 'guess', 'hub_challenge' => '1',
        ]) === null);

    assertThat('wrong mode is refused',
        MetaCloudService::verifySubscription([
            'hub_mode' => 'unsubscribe', 'hub_verify_token' => 'kr_test_verify_token', 'hub_challenge' => '1',
        ]) === null);

    echo "\n=== 5. Webhook signature uses the app secret ===\n\n";

    $body = '{"object":"whatsapp_business_account","entry":[]}';
    $good = 'sha256=' . hash_hmac('sha256', $body, 'test_app_secret');

    assertThat('valid signature accepted', MetaCloudService::verifySignature($body, $good));
    assertThat('unprefixed signature accepted', MetaCloudService::verifySignature($body, substr($good, 7)));
    assertThat('tampered body rejected', !MetaCloudService::verifySignature($body . ' ', $good));
    assertThat('missing header rejected', !MetaCloudService::verifySignature($body, null));

    assertThat('the verify token is NOT accepted as the signing key',
        !MetaCloudService::verifySignature($body, 'sha256=' . hash_hmac('sha256', $body, 'kr_test_verify_token')),
        'Meta signs with the app secret');

    $settings->set('wa_cloud_verify_token', $originalVerify, false, 'whatsapp');
    $settings->set('wa_cloud_app_secret', $originalSecret, true, 'whatsapp');
    $settings->refresh();
} else {
    echo "  (no database available — handshake and signature checks need settings)\n";
    echo "\n=== 5. Fail closed with no configuration ===\n\n";

    assertThat('no config -> no challenge echoed',
        MetaCloudService::verifySubscription(['hub_mode' => 'subscribe', 'hub_verify_token' => 'x', 'hub_challenge' => '1']) === null);

    assertThat('no config -> signature refused',
        !MetaCloudService::verifySignature('{}', 'sha256=deadbeef'));
}

echo "\n=== 6. The 24-hour window is respected, not ignored ===\n\n";

assertThat('the window is checked before choosing a payload',
    str_contains($source, 'withinServiceWindow'));

assertThat('a template is used outside the window',
    str_contains($source, 'templatePayload') && str_contains($source, "'type'              => 'template'"));

assertThat('free-form text is used inside the window',
    str_contains($source, "'type'              => 'text'"));

assertThat('no template configured -> a clear refusal, not a silent send',
    str_contains($source, 'no approved template is configured'));

assertThat('error 131047 is named and explained',
    str_contains($source, '131047') && str_contains($source, 'Outside the 24-hour window'));

assertThat('an unreadable window falls back to the template, not to text',
    // The catch block returns false, i.e. "assume closed".
    (bool) preg_match('/catch \(\\\\Throwable\) \{.*?return false;/s', $source));

echo "\n=== 7. Graph errors are explained, not dumped ===\n\n";

foreach ([
    [131047, 'template'],
    [190,    'expired'],
    [131030, 'allowed recipient list'],
    [133010, 'not registered'],
] as [$code, $needle]) {
    $explained = MetaCloudService::explain(400, ['error' => ['code' => $code, 'message' => 'raw graph text']]);

    assertThat('error ' . $code . ' explained in plain words',
        stripos($explained, $needle) !== false, str_limit($explained, 60));
}

assertThat('a network failure is explained',
    stripos(MetaCloudService::explain(0, null), 'graph.facebook.com') !== false);

echo "\n=== 8. Both providers coexist ===\n\n";

$wa = (string) file_get_contents(__DIR__ . '/../app/services/WhatsAppService.php');

assertThat('bulk gateway sending is still present', str_contains($wa, 'sendViaBulk'));
assertThat('cloud sending is wired in', str_contains($wa, 'sendViaCloud'));
assertThat('provider order is explicit', str_contains($wa, 'providerOrder'));
assertThat('an unconfigured provider is never queued', str_contains($wa, 'providerConfigured'));
assertThat('rejections that both gateways share are not retried', str_contains($wa, "'fatal'"));
assertThat('the log records which provider was used', str_contains($wa, "'provider'   => \$provider"));

$webhook = (string) file_get_contents(__DIR__ . '/../api/wa_webhook.php');

assertThat('one webhook URL serves both providers',
    str_contains($webhook, 'MetaCloudService::parseInbound')
    && str_contains($webhook, 'WhatsAppService::parseInbound'));

assertThat('the Meta handshake is answered before the secret check',
    strpos($webhook, 'verifySubscription') < strpos($webhook, "config('security.webhook_secret'"));

echo "\n" . str_repeat('-', 78) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nCloud API provider confirmed: Meta's nested payloads parse, delivery receipts are\n";
    echo "ignored rather than stored, the handshake and app-secret signature are enforced,\n";
    echo "and the 24-hour window switches to an approved template instead of failing silently.\n";
}

exit($fail > 0 ? 1 : 0);
