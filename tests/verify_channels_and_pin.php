<?php

/**
 * Covers the four changes made in this round, offline:
 *
 *   1. The 4-digit app PIN — what it refuses, and that it fails closed.
 *   2. Telegram — formatting, update parsing, error translation.
 *   3. The paged admin lists — that a table name can never come from the
 *      request, because the query builder interpolates it.
 *   4. That switching the site to English left Gujarati and Hindi intact.
 *
 * Run:  php tests/verify_channels_and_pin.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AppPinService;
use App\Services\TelegramService;

$pass = 0;
$fail = 0;

function assertThat(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %-58s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  — ' . $detail : '');
}

echo "\n=== 1. App PIN rules ===\n\n";

assertThat('a normal PIN is accepted', AppPinService::validate('4713')['ok'] === true);

foreach (['0000', '1111', '1234', '4321', '2580', '1212'] as $weak) {
    assertThat('guessable PIN refused: ' . $weak, AppPinService::validate($weak)['ok'] === false);
}

foreach (['123', '12345', '', 'abcd', '12a4', '12 4', '12.4'] as $bad) {
    assertThat(
        'not four digits refused: ' . ($bad === '' ? '(empty)' : $bad),
        AppPinService::validate($bad)['ok'] === false
    );
}

assertThat('PIN length is 4', AppPinService::LENGTH === 4);

$pinSource = (string) file_get_contents(__DIR__ . '/../app/services/AppPinService.php');

assertThat('stored with password_hash, never plain text',
    str_contains($pinSource, 'password_hash($pin, PASSWORD_BCRYPT)')
    && !preg_match("/'app_pin_hash'\s*=>\s*\\\$pin\b/", $pinSource));

assertThat('a wrong PIN locks the account after repeated tries',
    str_contains($pinSource, 'app_pin_locked_until'));

assertThat('an unknown number still runs a hash comparison',
    str_contains($pinSource, 'password_verify($pin, \'$2y$10$usesomesillystring'),
    'so timing does not reveal which numbers exist');

assertThat('unknown number and wrong PIN return the same message',
    substr_count($pinSource, '$generic') >= 3);

$api = (string) file_get_contents(__DIR__ . '/../api/v1/index.php');

assertThat('the PIN endpoint exists', str_contains($api, "['auth', 'pin']"));
assertThat('a per-IP limiter sits on top of the per-account lockout',
    str_contains($api, "RateLimiter::attempt('pin_ip_'"));

$settingsController = (string) file_get_contents(__DIR__ . '/../app/controllers/client/SettingsController.php');

assertThat('setting the PIN requires the account password',
    (bool) preg_match('/function saveAppPin.*?verifyPassword/s', $settingsController));

echo "\n=== 2. Telegram ===\n\n";

assertThat('WhatsApp *bold* becomes Telegram <b>',
    TelegramService::toHtml('*Reminder*') === '<b>Reminder</b>');

assertThat('_italic_ becomes <i>',
    TelegramService::toHtml('_soon_') === '<i>soon</i>');

assertThat('HTML in user text is escaped, not executed',
    TelegramService::toHtml('<script>alert(1)</script>')
        === '&lt;script&gt;alert(1)&lt;/script&gt;');

assertThat('Gujarati passes through untouched',
    TelegramService::toHtml('કાલે સવારે ૧૦ વાગ્યે') === 'કાલે સવારે ૧૦ વાગ્યે');

assertThat('an underscore inside a word is not italics',
    TelegramService::toHtml('short_code_here') === 'short_code_here');

$update = [
    'update_id' => 1,
    'message'   => [
        'message_id' => 42,
        'from'       => ['id' => 111, 'username' => 'akshay'],
        'chat'       => ['id' => 111, 'type' => 'private'],
        'text'       => 'કાલે સવારે ૧૦ વાગ્યે દવા લેવાની',
    ],
];

$parsed = TelegramService::parseUpdate($update);

assertThat('a message update parses', $parsed !== null);
assertThat('chat id captured', ($parsed['chat_id'] ?? '') === '111');
assertThat('username captured', ($parsed['username'] ?? '') === 'akshay');
assertThat('Gujarati body intact', ($parsed['text'] ?? '') === 'કાલે સવારે ૧૦ વાગ્યે દવા લેવાની');
assertThat('not a /start', ($parsed['is_start'] ?? true) === false);

$start = $update;
$start['message']['text'] = '/start AB12CD34';
$startParsed = TelegramService::parseUpdate($start);

assertThat('/start is recognised', ($startParsed['is_start'] ?? false) === true);
assertThat('link code extracted', ($startParsed['start_payload'] ?? '') === 'AB12CD34');

$bare = $update;
$bare['message']['text'] = '/start';
assertThat('a bare /start yields no payload',
    (TelegramService::parseUpdate($bare)['start_payload'] ?? 'x') === '');

// Things that are not messages must not be treated as one.
assertThat('an empty update is ignored', TelegramService::parseUpdate([]) === null);
assertThat('a callback-only update is ignored',
    TelegramService::parseUpdate(['update_id' => 2, 'callback_query' => ['id' => 'x']]) === null);
assertThat('a message with no chat is ignored',
    TelegramService::parseUpdate(['message' => ['message_id' => 1, 'text' => 'hi']]) === null);

$edited = ['edited_message' => ['message_id' => 9, 'chat' => ['id' => 222], 'text' => 'changed']];
assertThat('an edited message still parses',
    (TelegramService::parseUpdate($edited)['chat_id'] ?? '') === '222');

foreach ([
    [401, 'token'],
    [403, 'blocked'],
    [429, 'rate limiting'],
] as [$code, $needle]) {
    $explained = TelegramService::explain(200, ['ok' => false, 'error_code' => $code, 'description' => 'raw']);
    assertThat('Telegram error ' . $code . ' explained', stripos($explained, $needle) !== false,
        str_limit($explained, 55));
}

assertThat('a network failure is explained',
    stripos(TelegramService::explain(0, null), 'api.telegram.org') !== false);

$tgSource = (string) file_get_contents(__DIR__ . '/../app/services/TelegramService.php');

assertThat('the bot token never travels in a URL query string',
    !str_contains($tgSource, 'token=') && str_contains($tgSource, "self::API . \$token . '/'"));

assertThat('no bot token is hard-coded',
    !preg_match('/\d{8,}:[A-Za-z0-9_-]{30,}/', $tgSource));

assertThat('the webhook is verified by a secret header',
    str_contains($tgSource, 'verifySecret') && str_contains($tgSource, 'hash_equals'));

$tgHook = (string) file_get_contents(__DIR__ . '/../api/tg_webhook.php');

assertThat('the webhook rejects an unsigned request',
    strpos($tgHook, 'verifySecret') < strpos($tgHook, 'http_response_code(200)'));

assertThat('inbound Telegram is deduplicated',
    str_contains($tgHook, 'gateway_message_id'));

$wa = (string) file_get_contents(__DIR__ . '/../app/services/WhatsAppService.php');

assertThat('Telegram shares the outbound queue', str_contains($wa, 'queueTelegram'));
assertThat('the worker dispatches by channel', str_contains($wa, "=== 'telegram'"));
assertThat('the log records the channel', str_contains($wa, "'channel'    => \$channel"));

$schema = (string) file_get_contents(__DIR__ . '/../database/schema.sql');

// A row that cannot be inserted is the classic way a new channel half-works.
foreach (['ai_queue' => "'whatsapp','telegram','app','web','api'"] as $table => $enum) {
    assertThat($table . '.source accepts telegram', str_contains($schema, $enum));
}

assertThat('reminders.source accepts telegram',
    str_contains($schema, "'whatsapp','telegram','app','web','google','api','system'"));

assertThat('the queue and log carry a channel column',
    substr_count($schema, "`channel` VARCHAR(16) NOT NULL DEFAULT 'whatsapp'") >= 2);

echo "\n=== 3. Paged lists and delete ===\n\n";

$config = (string) file_get_contents(__DIR__ . '/../app/controllers/admin/ConfigController.php');

assertThat('10 rows per page', str_contains($config, 'PER_PAGE = 10'));

assertThat('an allow-list decides which tables are reachable',
    str_contains($config, 'LIST_TABLES'));

// The table name is interpolated into SQL, so it must never come from input.
foreach (['page', 'deleteRow', 'clearList'] as $method) {
    assertThat(
        $method . '() checks the allow-list before building SQL',
        (bool) preg_match('/function ' . $method . '\(.*?isset\(self::LIST_TABLES/s', $config)
    );
}

assertThat('the row id is cast to int', str_contains($config, "\$id = (int) Request::post('id', 0)"));

assertThat('clearing a list needs the name typed out',
    str_contains($config, "\$confirm !== \$table"));

// Match a real statement, not the comment that explains the choice.
assertThat('clear runs DELETE, and no TRUNCATE is ever executed',
    str_contains($config, "query('DELETE FROM `' . \$table . '`')")
    && preg_match('/query\(\s*[\'"]\s*TRUNCATE/i', $config) !== 1,
    'TRUNCATE resets AUTO_INCREMENT and ignores foreign keys');

assertThat('deletes are audited',
    str_contains($config, "AuditService::log('admin.row_deleted'")
    && str_contains($config, "AuditService::log('admin.list_cleared'"));

$routes = (string) file_get_contents(__DIR__ . '/../app/routes.php');

foreach (['/lists/delete', '/lists/clear'] as $route) {
    assertThat('CSRF required on ' . $route,
        (bool) preg_match('#' . preg_quote($route, '#') . "'.*'csrf'#", $routes));
}

$pager = (string) file_get_contents(__DIR__ . '/../app/views/partials/pager.php');

assertThat('each list pages on its own parameter', str_contains($pager, "\$query[\$param] = \$target"));
assertThat('other query parameters survive paging', str_contains($pager, '$query = $_GET'));
assertThat('the page number is escaped into the link', str_contains($pager, 'e($pageUrl('));

echo "\n=== 4. English default, other languages intact ===\n\n";

$seed = (string) file_get_contents(__DIR__ . '/../database/seed.sql');

assertThat("default_language seeds as 'en'", str_contains($seed, "('default_language','en','general',0)"));

$langCore = (string) file_get_contents(__DIR__ . '/../app/core/Lang.php');

assertThat('Lang falls back to English', str_contains($langCore, "private static string \$locale = 'en'"));
assertThat('all three languages still supported',
    str_contains($langCore, "SUPPORTED = ['en', 'gu', 'hi']"));

foreach (['en', 'gu', 'hi'] as $locale) {
    $strings = require __DIR__ . '/../lang/' . $locale . '.php';

    assertThat($locale . '.php loads as an array', is_array($strings));
    assertThat($locale . ' has the PIN strings', isset($strings['pin']['title']));
    assertThat($locale . ' keeps its auth strings', isset($strings['auth']));
}

// Gujarati and Hindi WhatsApp templates must not have been dropped.
foreach (['gu', 'hi', 'en'] as $locale) {
    assertThat(
        "'welcome' template still seeded for " . $locale,
        str_contains($seed, "('welcome','" . $locale . "','whatsapp'")
    );
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nApp PIN, Telegram, paged lists and the English default all check out.\n";
}

exit($fail > 0 ? 1 : 0);
