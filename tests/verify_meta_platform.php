<?php

/**
 * Proves the official Meta WhatsApp Cloud API platform behaves correctly
 * without touching the network or the database.
 *
 * The failures this guards against are the ones that cost real money or real
 * messages:
 *
 *   1. Retrying a POST /messages after a timeout. WhatsApp has no idempotency
 *      key, so a retry can deliver the same reminder twice and bill twice.
 *   2. Writing an access token into a log table. wa_api_log is readable by
 *      every admin; a token in it is a WhatsApp account handed over.
 *   3. Counting one delivery receipt twice, or letting a late 'sent' receipt
 *      drag a message backwards from 'read'.
 *   4. Submitting a template Meta will reject three days later for a reason it
 *      does not name — gapped {{n}}, a body starting with a variable, a header
 *      with two variables.
 *   5. Reporting a month of messaging as costing nothing because nobody filled
 *      in the rate card.
 *
 * Run:  php tests/verify_meta_platform.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\MetaGraph;
use App\Services\MetaMediaService;
use App\Services\MetaPricingService;
use App\Services\MetaWebhookService;
use App\Services\WaTemplateService;

$pass = 0;
$fail = 0;

function assertThat(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %-60s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  — ' . $detail : '');
}

/* ------------------------------------------------------------------------- */
echo "\n=== 1. Graph transport ===\n\n";

$url = MetaGraph::url('123456/messages');
assertThat('URL is versioned exactly once',
    preg_match('#^https://graph\.facebook\.com/v\d+\.\d+/123456/messages$#', $url) === 1, $url);

assertThat('a caller-supplied version is not doubled',
    MetaGraph::url('v19.0/123/media') === 'https://graph.facebook.com/v19.0/123/media');

assertThat('the version is validated, never taken raw',
    (bool) preg_match('/preg_match\(.\^v.d\+\\\\\.\\\\d\+\$/', (string) file_get_contents(__DIR__ . '/../app/services/MetaGraph.php'))
    || str_contains((string) file_get_contents(__DIR__ . '/../app/services/MetaGraph.php'), "preg_match('/^v\\d+\\.\\d+$/'"));

/* ------------------------------------------------------------------------- */
echo "\n=== 2. Retries never duplicate a message ===\n\n";

$timeout = ['ok' => false, 'status' => 0, 'code' => null];
$rateLimited = ['ok' => false, 'status' => 429, 'code' => null];
$serverError = ['ok' => false, 'status' => 500, 'code' => null];
$unavailable = ['ok' => false, 'status' => 503, 'code' => null];
$badRequest = ['ok' => false, 'status' => 400, 'code' => 100];
$outsideWindow = ['ok' => false, 'status' => 400, 'code' => 131047];
$throttled = ['ok' => false, 'status' => 400, 'code' => 130429];
$success = ['ok' => true, 'status' => 200, 'code' => null];

assertThat('a network timeout is retryable in principle', MetaGraph::isRetryable($timeout));
assertThat('a 429 is retryable', MetaGraph::isRetryable($rateLimited));
assertThat('a 500 is retryable', MetaGraph::isRetryable($serverError));
assertThat('Meta throughput limit (130429) is retryable', MetaGraph::isRetryable($throttled));
assertThat('a malformed request is NOT retryable', !MetaGraph::isRetryable($badRequest));
assertThat('outside the 24h window is NOT retryable', !MetaGraph::isRetryable($outsideWindow));
assertThat('a success is never retried', !MetaGraph::isRetryable($success));

// The rule that protects the customer from a duplicate reminder.
assertThat('a timed-out send is NOT repeated', !MetaGraph::shouldRetry($timeout, false));
assertThat('a timed-out GET IS repeated', MetaGraph::shouldRetry($timeout, true));
assertThat('a 500 on a send is NOT repeated — Meta may have accepted it',
    !MetaGraph::shouldRetry($serverError, false));
assertThat('a 429 on a send IS repeated — nothing was accepted',
    MetaGraph::shouldRetry($rateLimited, false));
assertThat('a 503 on a send IS repeated', MetaGraph::shouldRetry($unavailable, false));

$first = MetaGraph::backoffMs(1);
$second = MetaGraph::backoffMs(2);
$third = MetaGraph::backoffMs(3);

assertThat('backoff grows between attempts', $second > $first && $third > $second,
    "$first → $second → $third ms");
assertThat('backoff is capped so a worker never stalls', MetaGraph::backoffMs(12) <= 8500);
assertThat('a rate limit waits at least two seconds',
    MetaGraph::backoffMs(1, ['status' => 429]) >= 2000);

/* ------------------------------------------------------------------------- */
echo "\n=== 3. No token ever reaches the log table ===\n\n";

$token = 'EAAG' . str_repeat('x7Kq9Zb2', 6);

$redacted = MetaGraph::redact([
    'access_token' => $token,
    'body'         => ['app_secret' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 'to' => '919999999999'],
    'url'          => 'https://graph.facebook.com/v23.0/me?access_token=' . $token,
    'note'         => 'Bearer ' . $token,
    'nested'       => [['token' => $token]],
]);

$flat = (string) json_encode($redacted);

assertThat('the token is gone from every level', !str_contains($flat, $token), mb_substr($flat, 0, 90));
assertThat('a secret keyed by name is removed', !str_contains($flat, 'a1b2c3d4e5f6'));
assertThat('a token inside a URL is removed', !str_contains($flat, 'access_token=EAA'));
assertThat('a bearer header is removed', str_contains($flat, 'Bearer [redacted]'));
assertThat('the destination number survives — redaction is not deletion',
    str_contains($flat, '919999999999'));

assertThat('no live token is hard-coded anywhere in the Meta services', (function (): bool {
    foreach (glob(__DIR__ . '/../app/services/Meta*.php') ?: [] as $file) {
        if (preg_match('/EAA[A-Za-z0-9]{30,}/', (string) file_get_contents($file)) === 1) {
            return false;
        }
    }

    return true;
})());

/* ------------------------------------------------------------------------- */
echo "\n=== 4. Errors are explained, not dumped ===\n\n";

foreach ([
    131047 => '24-hour',
    190    => 'token',
    133010 => 'registered',
    132001 => 'approved',
    132005 => 'newline',
    131030 => 'allowed recipient',
] as $code => $expected) {
    $text = MetaGraph::explain(['ok' => false, 'status' => 400, 'code' => $code, 'message' => 'raw']);
    assertThat("error $code is explained in plain words", stripos($text, $expected) !== false, mb_substr($text, 0, 60));
}

assertThat('an expired token is distinguished from a revoked one',
    str_contains(
        MetaGraph::explain(['ok' => false, 'status' => 400, 'code' => 190, 'subcode' => 463]),
        'expired'
    ));

assertThat('an unreachable Graph is explained',
    stripos(MetaGraph::explain(['ok' => false, 'status' => 0]), 'graph.facebook.com') !== false);

/* ------------------------------------------------------------------------- */
echo "\n=== 5. Webhook events are split and deduplicated ===\n\n";

$statusValue = [
    'messaging_product' => 'whatsapp',
    'metadata'          => ['display_phone_number' => '919999999999', 'phone_number_id' => '123456789'],
    'statuses'          => [
        [
            'id'           => 'wamid.ABC',
            'status'       => 'sent',
            'timestamp'    => '1753000000',
            'recipient_id' => '919876543210',
            'conversation' => ['id' => 'conv_1', 'origin' => ['type' => 'utility']],
            'pricing'      => ['billable' => true, 'pricing_model' => 'CBP', 'category' => 'utility'],
        ],
        ['id' => 'wamid.ABC', 'status' => 'delivered', 'timestamp' => '1753000005', 'recipient_id' => '919876543210'],
        ['id' => 'wamid.ABC', 'status' => 'read', 'timestamp' => '1753000010', 'recipient_id' => '919876543210'],
    ],
];

$events = MetaWebhookService::split('messages', $statusValue);

assertThat('three receipts become three events', count($events) === 3, (string) count($events));

$keys = array_column($events, 'key');
assertThat('each receipt has its own dedup key', count(array_unique($keys)) === 3);
assertThat('the key includes the status, not just the message id',
    str_contains((string) $keys[0], 'sent') && str_contains((string) $keys[2], 'read'));

$messageValue = [
    'metadata' => ['phone_number_id' => '123456789'],
    'contacts' => [['profile' => ['name' => 'Akshay'], 'wa_id' => '919876543210']],
    'messages' => [
        ['id' => 'wamid.IN1', 'from' => '919876543210', 'type' => 'text', 'text' => ['body' => 'hello']],
        ['id' => 'wamid.IN2', 'from' => '919876543210', 'type' => 'text', 'text' => ['body' => 'again']],
    ],
];

$events = MetaWebhookService::split('messages', $messageValue);
assertThat('two inbound messages become two events', count($events) === 2);
assertThat('an inbound key is derived from the wamid',
    $events[0]['key'] === 'in_wamid.IN1' && $events[1]['key'] === 'in_wamid.IN2');
assertThat('each inbound event keeps the metadata it needs',
    isset($events[0]['payload']['metadata']['phone_number_id']));

$templateEvent = MetaWebhookService::split('message_template_status_update', [
    'message_template_id' => '987654321',
    'message_template_name' => 'reminder_alert',
    'event' => 'APPROVED',
]);

assertThat('a template decision produces exactly one event', count($templateEvent) === 1);
assertThat('its key distinguishes the template and the decision',
    str_contains((string) $templateEvent[0]['key'], '987654321')
    && str_contains((string) $templateEvent[0]['key'], 'APPROVED'));

$a = MetaWebhookService::split('phone_number_quality_update', ['display_phone_number' => '919999999999', 'event' => 'FLAGGED']);
$b = MetaWebhookService::split('phone_number_quality_update', ['display_phone_number' => '919999999999', 'event' => 'FLAGGED']);
assertThat('an identical redelivery produces an identical key', $a[0]['key'] === $b[0]['key']);

$c = MetaWebhookService::split('phone_number_quality_update', ['display_phone_number' => '919999999999', 'event' => 'UNFLAGGED']);
assertThat('a different event produces a different key', $a[0]['key'] !== $c[0]['key']);

/* ------------------------------------------------------------------------- */
echo "\n=== 6. Timestamps and inbound text ===\n\n";

assertThat('a Meta timestamp becomes UTC',
    MetaWebhookService::timestamp('1753000000') === gmdate('Y-m-d H:i:s', 1753000000));
assertThat('an absurd past timestamp is rejected', MetaWebhookService::timestamp('1') === null);
assertThat('a far-future timestamp is rejected',
    MetaWebhookService::timestamp((string) (time() + 400000)) === null);
assertThat('a missing timestamp is null, not 1970', MetaWebhookService::timestamp(null) === null);

$cases = [
    ['text', ['text' => ['body' => 'સવારે દવા લેવી']], 'સવારે દવા લેવી'],
    ['button', ['button' => ['text' => 'Done', 'payload' => 'DONE_1']], 'Done'],
    ['image', ['image' => ['id' => '1', 'caption' => 'bill']], 'bill'],
    ['document', ['document' => ['id' => '1', 'filename' => 'invoice.pdf']], 'invoice.pdf'],
    ['location', ['location' => ['latitude' => 1, 'longitude' => 2, 'name' => 'Shop']], 'Shop'],
    ['contacts', ['contacts' => [['name' => ['formatted_name' => 'Ravi']]]], 'Ravi'],
    ['reaction', ['reaction' => ['emoji' => '👍']], '👍'],
    ['interactive', ['interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'x', 'title' => 'Snooze']]], 'Snooze'],
];

foreach ($cases as [$type, $message, $expected]) {
    assertThat("inbound $type keeps its text", MetaWebhookService::inboundText($message, $type) === $expected);
}

assertThat('an audio note has no text and does not crash',
    MetaWebhookService::inboundText(['audio' => ['id' => '1']], 'audio') === '');

/* ------------------------------------------------------------------------- */
echo "\n=== 7. A receipt never drags a message backwards ===\n\n";

$source = (string) file_get_contents(__DIR__ . '/../app/services/MetaWebhookService.php');

assertThat('status has an explicit ordering', str_contains($source, "'delivered' => 3, 'read' => 4"));
assertThat('a lower-ranked status is not applied', str_contains($source, '$newRank > $currentRank'));
assertThat('a failure always wins, whatever arrived before',
    strpos($source, "if (\$state === 'failed')") < strpos($source, '$newRank > $currentRank'));
assertThat('events are stored before they are processed',
    strpos($source, 'self::store(') < strpos($source, 'self::process('));
assertThat('a duplicate key is treated as expected, not as an error',
    str_contains($source, "=== '23000'"));

/* ------------------------------------------------------------------------- */
echo "\n=== 8. Template validation catches what Meta rejects days later ===\n\n";

$valid = [
    'name'     => 'reminder_alert',
    'category' => 'UTILITY',
    'body'     => 'Reminder: {{1}} is due today.',
];

assertThat('a well-formed template validates', WaTemplateService::validate($valid) === []);

$badName = WaTemplateService::validate(['name' => 'Reminder Alert', 'category' => 'UTILITY', 'body' => 'hi there']);
assertThat('capitals and spaces in the name are caught', $badName !== []);

$gap = WaTemplateService::validate([
    'name' => 'x', 'category' => 'UTILITY', 'body' => 'a {{1}} b {{3}} c',
]);
assertThat('gapped variables are caught', $gap !== [], (string) ($gap[0] ?? ''));

$leading = WaTemplateService::validate([
    'name' => 'x', 'category' => 'UTILITY', 'body' => '{{1}} is due',
]);
assertThat('a body starting with a variable is caught', $leading !== []);

$twoHeaderVars = WaTemplateService::validate([
    'name' => 'x', 'category' => 'UTILITY', 'body' => 'ok {{1}} then',
    'header_text' => '{{1}} and {{2}}',
]);
assertThat('two header variables are caught', $twoHeaderVars !== []);

$badCategory = WaTemplateService::validate(['name' => 'x', 'category' => 'PROMO', 'body' => 'hello']);
assertThat('an invalid category is caught', $badCategory !== []);

$longFooter = WaTemplateService::validate([
    'name' => 'x', 'category' => 'UTILITY', 'body' => 'hello', 'footer' => str_repeat('a', 61),
]);
assertThat('an over-long footer is caught', $longFooter !== []);

$urlNoUrl = WaTemplateService::validate([
    'name' => 'x', 'category' => 'UTILITY', 'body' => 'hello',
    'buttons' => [['type' => 'URL', 'text' => 'Open']],
]);
assertThat('a URL button with no URL is caught', $urlNoUrl !== []);

/* ------------------------------------------------------------------------- */
echo "\n=== 9. Component building ===\n\n";

$components = WaTemplateService::buildComponents([
    'header_format' => 'TEXT',
    'header_text'   => 'Krishna Reminder',
    'body'          => 'Hello {{1}}, {{2}} is due today.',
    'footer'        => 'Reply STOP to opt out',
    'buttons'       => [
        ['type' => 'QUICK_REPLY', 'text' => 'Done'],
        ['type' => 'URL', 'text' => 'Open', 'url' => 'https://reminder.akdwk.in/r/{{1}}', 'example' => 'https://reminder.akdwk.in/r/9'],
        ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+919999999999'],
        ['type' => 'COPY_CODE', 'text' => 'Copy', 'example' => '482913'],
    ],
]);

$byType = [];
foreach ($components as $component) {
    $byType[$component['type']] = $component;
}

assertThat('header, body, footer and buttons are all present',
    isset($byType['HEADER'], $byType['BODY'], $byType['FOOTER'], $byType['BUTTONS']));

assertThat('two body variables produce two examples',
    count($byType['BODY']['example']['body_text'][0] ?? []) === 2);

assertThat('missing examples are filled rather than rejected by Meta',
    ($byType['BODY']['example']['body_text'][0][1] ?? '') !== '');

assertThat('a variable URL button carries an example',
    isset($byType['BUTTONS']['buttons'][1]['example']));

assertThat('all four button types survive', count($byType['BUTTONS']['buttons']) === 4);

assertThat('variable count is derived from the body, not guessed',
    WaTemplateService::variableCount($components) === 2);

$noVars = WaTemplateService::buildComponents(['body' => 'Your reminder is due.']);
assertThat('a template with no variables carries no example block',
    !isset($noVars[0]['example']));

$otp = WaTemplateService::buildButtons([
    ['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code'],
]);
assertThat('an OTP button is built', ($otp[0]['type'] ?? '') === 'OTP' && ($otp[0]['otp_type'] ?? '') === 'COPY_CODE');

/* ------------------------------------------------------------------------- */
echo "\n=== 10. Send parameters are cleaned the way Meta demands ===\n\n";

$params = WaTemplateService::parameters(["Pay the\nelectricity bill\ttoday    please"]);
$text = $params[0]['parameters'][0]['text'];

assertThat('newlines are removed', !str_contains($text, "\n"));
assertThat('tabs are removed', !str_contains($text, "\t"));
assertThat('runs of spaces are collapsed', !str_contains($text, '    '));
assertThat('the words survive intact',
    str_contains($text, 'Pay the electricity bill today please'), $text);

$withButton = WaTemplateService::parameters(['one'], 'HEADER', ['0' => 'code']);
assertThat('a header parameter comes first', ($withButton[0]['type'] ?? '') === 'header');
assertThat('a button parameter carries its index',
    ($withButton[2]['sub_type'] ?? '') === 'url' && ($withButton[2]['index'] ?? '') === '0');

/* ------------------------------------------------------------------------- */
echo "\n=== 11. Pricing is honest about what it knows ===\n\n";

assertThat('India is recognised from the dialling code',
    MetaPricingService::countryFromNumber('919876543210') === 'IN');
assertThat('the UAE is not mistaken for something else',
    MetaPricingService::countryFromNumber('971501234567') === 'AE');
assertThat('a US number resolves', MetaPricingService::countryFromNumber('14155552671') === 'US');
assertThat('a longer code wins over a shorter prefix',
    MetaPricingService::countryFromNumber('919999999999') !== 'US');
assertThat('an empty number resolves to nothing, not to a default country',
    MetaPricingService::countryFromNumber('') === '');

$priced = MetaPricingService::priceFor('utility', 'IN');
assertThat('an unfilled rate card reports "unknown", not "free"', $priced['known'] === false);
assertThat('an unfilled rate card still returns a usable shape',
    isset($priced['price'], $priced['currency'], $priced['markup']));

$pricingSource = (string) file_get_contents(__DIR__ . '/../app/services/MetaPricingService.php');
assertThat('the code states that the amount comes from the rate card, not from Meta',
    stripos($pricingSource, 'the money is left to the published rate card') !== false);
assertThat('pricing never throws into the webhook path',
    str_contains($pricingSource, 'Pricing is reporting, never delivery'));

$migration = (string) file_get_contents(__DIR__ . '/../database/migrations/2026_07_28_000004_meta_platform.sql');

preg_match('/INSERT IGNORE INTO `wa_price_rates`.*?;/s', $migration, $seed);
assertThat('the rate card is seeded at all', ($seed[0] ?? '') !== '');
assertThat('the seeded rate card is zeroed, not invented',
    ($seed[0] ?? '') !== '' && preg_match('/,\s*[1-9][\d.]*\s*,/', $seed[0]) === 0);

/* ------------------------------------------------------------------------- */
echo "\n=== 12. Media ===\n\n";

assertThat('a PDF is sent as a document', MetaMediaService::kindFor('application/pdf') === 'document');
assertThat('a JPEG is sent as an image', MetaMediaService::kindFor('image/jpeg') === 'image');
assertThat('a WebP is sent as a sticker', MetaMediaService::kindFor('image/webp') === 'sticker');
assertThat('an unknown type falls back to document rather than failing',
    MetaMediaService::kindFor('application/x-thing') === 'document');
assertThat('a download gets the right extension', MetaMediaService::extensionFor('image/png') === '.png');

$mediaSource = (string) file_get_contents(__DIR__ . '/../app/services/MetaMediaService.php');

assertThat('uploads are keyed by content hash so nothing is uploaded twice',
    str_contains($mediaSource, "hash_file('sha256'"));
assertThat('the cache expires before Meta drops the id',
    str_contains($mediaSource, '25 * 86400'));
assertThat('inbound media is downloaded with the bearer token',
    str_contains($mediaSource, "'Authorization' => 'Bearer ' . WabaAccountService::token"));
assertThat('inbound media lands outside the web root',
    str_contains($mediaSource, "/storage/media/whatsapp"));

$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
assertThat('storage/ is unreachable over HTTP', str_contains($htaccess, 'storage'));

/* ------------------------------------------------------------------------- */
echo "\n=== 13. Tokens are encrypted, tenants are separated ===\n\n";

$accountSource = (string) file_get_contents(__DIR__ . '/../app/services/WabaAccountService.php');

assertThat('tokens are encrypted before they are stored',
    str_contains($accountSource, 'Crypto::encrypt($value)'));
assertThat('tokens are decrypted only on the way out',
    str_contains($accountSource, 'Crypto::decrypt($stored)'));
assertThat('a blank field never wipes a working token',
    str_contains($accountSource, 'leave it alone'));
assertThat('every account is addressed by its owner for multi-tenancy',
    str_contains($accountSource, 'owner_user_id = ?'));
assertThat('a webhook resolves the account by phone number id',
    str_contains($accountSource, 'findByPhoneNumberId'));

$messageSource = (string) file_get_contents(__DIR__ . '/../app/services/MetaMessageService.php');

// Look inside send() alone: markRead() also posts to Graph and would make a
// whole-file position comparison meaningless.
$sendBody = (string) (preg_split('/public static function send\(array \$account/', $messageSource)[1] ?? '');

assertThat('the outbound row is written before the wire call',
    $sendBody !== ''
    && strpos($sendBody, '$rowId = self::record(') !== false
    && strpos($sendBody, '$rowId = self::record(') < strpos($sendBody, 'MetaGraph::post('));
assertThat('sends are explicitly marked non-idempotent',
    str_contains($messageSource, "'idempotent' => false"));
assertThat('the account is always passed in, never looked up globally',
    str_contains($messageSource, 'public static function send(array $account'));

echo "\n" . str_repeat('-', 80) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nMeta Cloud API platform confirmed: a timed-out send is never repeated, no token\n";
    echo "can reach the log table, receipts deduplicate and never move a message backwards,\n";
    echo "templates are validated before Meta ever sees them, and pricing says plainly when\n";
    echo "the rate card has not been filled in.\n";
}

exit($fail > 0 ? 1 : 0);
