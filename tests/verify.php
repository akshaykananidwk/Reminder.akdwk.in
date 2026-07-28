<?php

/**
 * Offline verification harness.
 *
 * Exercises the pure (database-free) parts of the engine: the Gujarati/Hindi/
 * English fallback parser, the recurrence expander, phone normalisation and the
 * inbound webhook payload parser.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\FallbackParser;
use App\Services\RecurrenceService;
use App\Services\WhatsAppService;

date_default_timezone_set('Asia/Kolkata');

$pass = 0;
$fail = 0;
$rows = [];

function check(string $name, bool $ok, string $expected, string $actual): void
{
    global $pass, $fail, $rows;

    $ok ? $pass++ : $fail++;
    $rows[] = [$name, $expected, $actual, $ok ? 'PASS' : 'FAIL'];
}

$user = [
    'id' => 1,
    'name' => 'Ashok',
    'language' => 'gu',
    'timezone' => 'Asia/Kolkata',
    'default_time' => '09:00:00',
];

$now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
echo "NOW (IST): " . $now->format('D d M Y H:i') . "\n\n";

/* ============================ 1. Gujarati / Hindi / English NL parsing ==== */

$cases = [
    // [sentence, expectation description, assertion]
    ['કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે', 'tomorrow 10:00, type=call',
        fn ($i, $d) => $d->format('H:i') === '10:00' && $d->format('Y-m-d') === (new DateTime('+1 day'))->format('Y-m-d') && $i['type'] === 'call'],

    ['આજે સાંજે 6 વાગ્યે દવા લેવી', 'today 18:00, type=medicine',
        fn ($i, $d) => $d->format('H:i') === '18:00' && $i['type'] === 'medicine'],

    ['પરમ દિવસે બપોરે 3 વાગ્યે મીટિંગ', 'day-after 15:00, type=meeting',
        fn ($i, $d) => $d->format('H:i') === '15:00' && $d->format('Y-m-d') === (new DateTime('+2 days'))->format('Y-m-d') && $i['type'] === 'meeting'],

    ['દર સોમવારે સવારે 11 વાગ્યે સ્ટાફ મીટિંગ', 'weekly MO 11:00',
        fn ($i, $d) => $i['recurrence']['freq'] === 'weekly' && $i['recurrence']['by_day'] === ['MO'] && $d->format('H:i') === '11:00'],

    ['દરરોજ રાત્રે 9 વાગ્યે દવા', 'daily 21:00',
        fn ($i, $d) => $i['recurrence']['freq'] === 'daily' && $d->format('H:i') === '21:00'],

    ['દર મહિને 5 તારીખે લાઇટ બિલ ભરવું', 'monthly on day 5',
        fn ($i, $d) => $i['recurrence']['freq'] === 'monthly' && $i['recurrence']['by_month_day'] === 5 && (int) $d->format('j') === 5],

    ['15 મિનિટ પછી રમેશભાઈને ફોન કરવો', '~15 minutes ahead',
        fn ($i, $d) => abs($d->getTimestamp() - (time() + 900)) < 90],

    ['બે કલાક પછી બેંક જવાનું', '~2 hours ahead',
        fn ($i, $d) => abs($d->getTimestamp() - (time() + 7200)) < 120],

    ['આવતા શુક્રવારે સવારે 10 વાગ્યે GST ફાઇલિંગ', 'a future Friday 10:00',
        fn ($i, $d) => $d->format('N') === '5' && $d->format('H:i') === '10:00' && $d->getTimestamp() > time()],

    ['5 તારીખે રમેશભાઈને 5000 રૂપિયા આપવા', 'day 5, amount 5000, type=payment',
        fn ($i, $d) => (int) $d->format('j') === 5 && (int) $i['amount'] === 5000 && $i['type'] === 'payment'],

    ['કાલે 5 હજાર રૂપિયા ઉઘરાણી કરવાની છે', 'amount 5000, type=payment',
        fn ($i, $d) => (int) $i['amount'] === 5000 && $i['type'] === 'payment'],

    // The day is deliberately not asserted. "28 July" is in the past for most
    // of 28 July, and the parser then moves it forward — correctly. Pinning the
    // day made this case pass or fail depending on the time of day it was run.
    // What it is here to prove is that Gujarati numerals and month names are
    // read at all, so it asserts the month and the time.
    ['૨૮ જુલાઈ સવારે ૮ વાગ્યે ટ્રેન', 'Gujarati numerals → July, 08:00',
        fn ($i, $d) => (int) $d->format('n') === 7 && $d->format('H:i') === '08:00'],

    // A date that cannot already have passed, so the day itself is checked too.
    [(static function (): string {
        $future = (new DateTimeImmutable('+40 days', new DateTimeZone('Asia/Kolkata')));
        $digits = ['0' => '૦', '1' => '૧', '2' => '૨', '3' => '૩', '4' => '૪',
                   '5' => '૫', '6' => '૬', '7' => '૭', '8' => '૮', '9' => '૯'];
        $months = [1 => 'જાન્યુઆરી', 'ફેબ્રુઆરી', 'માર્ચ', 'એપ્રિલ', 'મે', 'જૂન',
                   'જુલાઈ', 'ઓગસ્ટ', 'સપ્ટેમ્બર', 'ઓક્ટોબર', 'નવેમ્બર', 'ડિસેમ્બર'];

        return strtr($future->format('j'), $digits) . ' ' . $months[(int) $future->format('n')]
            . ' સવારે ૯ વાગ્યે ડોક્ટર';
    })(), 'Gujarati numerals → exact future date, 09:00',
        fn ($i, $d) => $d->format('Y-m-d H:i')
            === (new DateTimeImmutable('+40 days', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d') . ' 09:00'],

    ['3 દિવસ પછી ડોક્ટરને મળવાનું', '~3 days ahead',
        fn ($i, $d) => $d->format('Y-m-d') === (new DateTime('+3 days'))->format('Y-m-d')],

    ['તાત્કાલિક કાલે સવારે 7 વાગ્યે નીકળવાનું', 'priority urgent',
        fn ($i, $d) => $i['priority'] === 'urgent' && $d->format('H:i') === '07:00'],

    ['કાલે સવારે 10 વાગ્યે જન્મદિવસની શુભેચ્છા મોકલવી', 'type=birthday',
        fn ($i, $d) => $i['type'] === 'birthday'],

    // Hindi
    ['कल सुबह 10 बजे बैंक जाना है', 'HI: tomorrow 10:00',
        fn ($i, $d) => $d->format('H:i') === '10:00' && $d->format('Y-m-d') === (new DateTime('+1 day'))->format('Y-m-d')],

    ['हर सोमवार शाम 6 बजे स्टाफ मीटिंग', 'HI: weekly MO 18:00',
        fn ($i, $d) => $i['recurrence']['freq'] === 'weekly' && $i['recurrence']['by_day'] === ['MO'] && $d->format('H:i') === '18:00'],

    ['परसों दोपहर 2 बजे दवा लेनी है', 'HI: day-after 14:00',
        fn ($i, $d) => $d->format('H:i') === '14:00' && $d->format('Y-m-d') === (new DateTime('+2 days'))->format('Y-m-d')],

    // English
    ['tomorrow at 10 am call the bank', 'EN: tomorrow 10:00',
        fn ($i, $d) => $d->format('H:i') === '10:00' && $d->format('Y-m-d') === (new DateTime('+1 day'))->format('Y-m-d')],

    ['every monday 9 am staff meeting', 'EN: weekly MO 09:00',
        fn ($i, $d) => $i['recurrence']['freq'] === 'weekly' && $i['recurrence']['by_day'] === ['MO']],

    ['pay Ramesh 12000 on 5', 'EN: amount 12000, payment',
        fn ($i, $d) => (int) $i['amount'] === 12000 && $i['type'] === 'payment'],
];

echo "=== 1. Natural-language parsing (fallback parser, no AI) ===\n\n";

foreach ($cases as [$sentence, $expectation, $assert]) {
    $result = FallbackParser::parse($sentence, $user);
    $item = $result['items'][0] ?? null;

    if ($item === null || empty($item['due_at'])) {
        check(mb_substr($sentence, 0, 46), false, $expectation, 'no item parsed');
        continue;
    }

    $due = new DateTime($item['due_at']);
    $due->setTimezone(new DateTimeZone('Asia/Kolkata'));

    $ok = (bool) $assert($item, $due);

    $actual = $due->format('D d M H:i')
        . ' · ' . $item['type']
        . ' · ' . $item['recurrence']['freq']
        . ($item['recurrence']['by_day'] ? '[' . implode(',', $item['recurrence']['by_day']) . ']' : '')
        . ($item['recurrence']['by_month_day'] ? '[d' . $item['recurrence']['by_month_day'] . ']' : '')
        . ($item['amount'] !== null ? ' · ₹' . (int) $item['amount'] : '')
        . ' · ' . $item['priority'];

    check(mb_substr($sentence, 0, 46), $ok, $expectation, $actual);
}

/* ================================================ 2. Recurrence expansion == */

echo "\n=== 2. Recurrence expansion ===\n\n";

$base = gmdate('Y-m-d H:i:s', strtotime('tomorrow 04:30 UTC'));

$recurrenceCases = [
    ['daily 3 days', ['freq' => 'daily', 'interval' => 1], 3, 3],
    ['every 2 days over 10 days', ['freq' => 'daily', 'interval' => 2], 10, 5],
    ['weekly over 28 days', ['freq' => 'weekly', 'interval' => 1], 28, 4],
    ['weekly MO+TH over 28 days', ['freq' => 'weekly', 'interval' => 1, 'by_day' => ['MO', 'TH']], 28, 8],
    ['monthly day 5 over 90 days', ['freq' => 'monthly', 'interval' => 1, 'by_month_day' => 5], 90, 3],
    ['none', ['freq' => 'none'], 30, 1],
    ['daily with count=4', ['freq' => 'daily', 'interval' => 1, 'count' => 4], 30, 4],
];

foreach ($recurrenceCases as [$label, $rule, $days, $expectedCount]) {
    $reminder = [
        'id' => 1,
        'user_id' => 1,
        'start_at' => $base,
        'end_at' => null,
        'recurrence' => json_encode($rule),
        'recurrence_count' => null,
        'timezone' => 'Asia/Kolkata',
    ];

    $dates = RecurrenceService::expand(
        $reminder,
        gmdate('Y-m-d H:i:s', strtotime($base) - 60),
        gmdate('Y-m-d H:i:s', strtotime($base) + ($days * 86400))
    );

    $count = count($dates);

    // Weekly-by-day series can legitimately vary by ±1 depending on the weekday
    // the window opens on.
    $ok = abs($count - $expectedCount) <= (str_contains($label, 'MO+TH') ? 1 : 0);

    check('recurrence: ' . $label, $ok, "$expectedCount occurrence(s)", "$count occurrence(s)");
}

/* ============================================== 3. Phone normalisation ==== */

echo "\n=== 3. Phone normalisation ===\n\n";

$phones = [
    ['+91 99781 23146', '919978123146'],
    ['09978123146', '919978123146'],
    ['9978123146', '919978123146'],
    ['919978123146@c.us', '919978123146'],
    ['whatsapp:+919978123146', '919978123146'],
    ['91-99781-23146', '919978123146'],
    ['0091 9978123146', '919978123146'],
    ['919978123146@s.whatsapp.net', '919978123146'],
];

foreach ($phones as [$input, $expected]) {
    $actual = normalize_phone($input);
    check('phone: ' . $input, $actual === $expected, $expected, $actual);
}

/* ========================================== 4. Inbound webhook payloads === */

echo "\n=== 4. Inbound webhook payload shapes ===\n\n";

$payloads = [
    ['flat from/message', ['from' => '919876543210', 'message' => 'કાલે બેંક', 'id' => 'abc1']],
    ['sender/body', ['sender' => '+91 98765 43210', 'body' => 'test', 'message_id' => 'abc2']],
    ['nested data', ['data' => ['number' => '919876543210@c.us', 'text' => 'hi', 'msg_id' => 'abc3']]],
    ['wa_id/content', ['wa_id' => '919876543210', 'content' => 'hello', 'messageId' => 'abc4']],
    ['remoteJid', ['remoteJid' => '919876543210@s.whatsapp.net', 'text' => 'namaste']],
];

foreach ($payloads as [$label, $payload]) {
    $parsed = WhatsAppService::parseInbound($payload);

    $ok = $parsed !== null && $parsed['from'] === '919876543210';

    check('webhook: ' . $label, $ok, 'from=919876543210', $parsed === null ? 'null' : ('from=' . $parsed['from'] . ' body="' . $parsed['body'] . '"'));
}

// A payload with no sender must be rejected, not guessed at.
$parsed = WhatsAppService::parseInbound(['message' => 'orphan']);
check('webhook: no sender rejected', $parsed === null, 'null', $parsed === null ? 'null' : 'parsed');

/* ================================================================ Report == */

echo "\n";
printf("%-50s %-34s %-40s %s\n", 'CASE', 'EXPECTED', 'ACTUAL', 'RESULT');
echo str_repeat('-', 140) . "\n";

foreach ($rows as [$name, $expected, $actual, $result]) {
    printf("%-50s %-34s %-40s %s\n",
        mb_strimwidth($name, 0, 49, '…'),
        mb_strimwidth($expected, 0, 33, '…'),
        mb_strimwidth($actual, 0, 39, '…'),
        $result
    );
}

echo str_repeat('-', 140) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

exit($fail > 0 ? 1 : 0);
