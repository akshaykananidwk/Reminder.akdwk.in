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

echo "\n=== 5. Telegram action buttons ===\n\n";

$kb = TelegramService::reminderKeyboard(42, 'gu');

assertThat('a reminder gets three rows of buttons', count($kb) === 3);
assertThat('Done is first', ($kb[0][0]['callback_data'] ?? '') === 'done:42');
assertThat('three snooze choices', count($kb[1]) === 3);
assertThat('snooze carries its minutes', ($kb[1][1]['callback_data'] ?? '') === 'snooze:42:30');
assertThat('cancel is present', ($kb[2][0]['callback_data'] ?? '') === 'cancel:42');
assertThat('buttons are in the user language', str_contains((string) ($kb[0][0]['text'] ?? ''), 'થઈ ગયું'));

foreach ($kb as $row) {
    foreach ($row as $button) {
        // Telegram hard-rejects anything longer.
        assertThat('callback_data within 64 bytes: ' . $button['callback_data'],
            strlen($button['callback_data']) <= 64);
    }
}

$cb = TelegramService::parseCallback([
    'callback_query' => [
        'id' => 'q1', 'data' => 'snooze:42:30',
        'message' => ['message_id' => 7, 'chat' => ['id' => '111']],
    ],
]);

assertThat('a button tap parses', $cb !== null);
assertThat('action read', ($cb['action'] ?? '') === 'snooze');
assertThat('occurrence read', ($cb['occurrence_id'] ?? 0) === 42);
assertThat('minutes read', ($cb['minutes'] ?? 0) === 30);

assertThat('an ordinary message is not a callback',
    TelegramService::parseCallback(['message' => ['chat' => ['id' => 1], 'text' => 'hi']]) === null);

assertThat('an unknown action is refused',
    TelegramService::parseCallback([
        'callback_query' => ['id' => 'x', 'data' => 'drop_table:1', 'message' => ['message_id' => 1, 'chat' => ['id' => '1']]],
    ]) === null,
    'callback_data comes from the client');

$tgSrc = (string) file_get_contents(__DIR__ . '/../app/services/TelegramService.php');

assertThat('the webhook subscribes to button taps',
    str_contains($tgSrc, "'callback_query'"),
    'without this Telegram never delivers them');

$hook = (string) file_get_contents(__DIR__ . '/../api/tg_webhook.php');

assertThat('a tap is scoped to the tapping user',
    (bool) preg_match('/WHERE o\.id = \? AND o\.user_id = \?/', $hook),
    'the occurrence id arrives from the client');

assertThat('the tap is acknowledged so the spinner stops',
    str_contains($hook, 'answerCallback'));

assertThat('buttons are removed after use',
    str_contains($hook, 'editMessage'),
    'otherwise the same action can be tapped twice');

echo "\n=== 6. The site can be pinned to one language ===\n\n";

App\Core\Lang::forceLocale('en');
App\Core\Lang::setLocale('gu');
assertThat('a forced language beats a per-user choice', App\Core\Lang::locale() === 'en');
assertThat('and reports itself as forced', App\Core\Lang::isForced());

App\Core\Lang::forceLocale(null);
App\Core\Lang::setLocale('gu');
assertThat('unforced, the user keeps their own language', App\Core\Lang::locale() === 'gu');
assertThat('and reports itself as not forced', !App\Core\Lang::isForced());

App\Core\Lang::forceLocale('zz');
assertThat('an unsupported code is ignored, not applied', !App\Core\Lang::isForced());
App\Core\Lang::setLocale('en');

$index = (string) file_get_contents(__DIR__ . '/../index.php');
assertThat('the front controller applies it before anything else',
    strpos($index, 'Lang::forceLocale') < strpos($index, 'Lang::setLocale($locale)'));

echo "\n=== 7. Every text field is styled, in both themes ===\n\n";

$css = (string) file_get_contents(__DIR__ . '/../assets/css/app.css');

assertThat('inputs are matched without needing a type attribute',
    str_contains($css, 'input:not([type=checkbox])'),
    '<input name="x"> is a text field but never matched input[type=text]');

assertThat('checkboxes and radios are excluded',
    str_contains($css, ':not([type=radio])') && str_contains($css, ':not([type=file])'));

assertThat('the browser is told which theme is active',
    str_contains($css, 'color-scheme: dark') && str_contains($css, 'color-scheme: light'),
    'native selects, date pickers and autofill ignore CSS colours otherwise');

assertThat('the phone sidebar is a drawer, not display:none',
    str_contains($css, '.app-shell.nav-open .sidebar'),
    'half the admin was unreachable on a phone');

foreach (['admin', 'client'] as $layout) {
    $src = (string) file_get_contents(__DIR__ . '/../app/views/layouts/' . $layout . '.php');

    assertThat($layout . ' layout has a menu button', str_contains($src, 'data-action="toggle-nav"'));
    assertThat($layout . ' layout has a scrim to close it', str_contains($src, 'data-action="close-nav"'));
}

$js = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');

assertThat('the drawer opens and closes', str_contains($js, "action === 'toggle-nav'"));
assertThat('Escape closes it', str_contains($js, "event.key !== 'Escape'"));
assertThat('the page behind it cannot scroll', str_contains($js, 'nav-locked'));

echo "\n=== 8. A reply goes back on the channel it came from ===\n\n";

$aiCron = (string) file_get_contents(__DIR__ . '/../cron/ai_queue.php');

assertThat('a Telegram message is answered on Telegram',
    str_contains($aiCron, "\$job['source'] === 'telegram'")
    && str_contains($aiCron, 'TelegramService::sendToUser'));

assertThat('the reply is no longer WhatsApp-only',
    !preg_match("/if \\(\\\$outcome\\['reply'\\] !== '' && \\\$job\\['source'\\] === 'whatsapp'\\)/", $aiCron),
    'that gate meant Telegram users got no confirmation at all');

assertThat('WhatsApp still replies over WhatsApp',
    str_contains($aiCron, "WhatsAppService::queue("));

assertThat('a failed instant reply falls back to the queue',
    str_contains($aiCron, 'queueTelegram') && str_contains($aiCron, 'Telegram reply failed'),
    'a confirmation must not be silently lost');

$hook2 = (string) file_get_contents(__DIR__ . '/../api/tg_webhook.php');

assertThat('/today is answered immediately, not queued',
    str_contains($hook2, "str_starts_with(\$text, '/')")
    && str_contains($hook2, 'CommandService::handle'));

assertThat('an unknown slash command is not filed as a reminder',
    str_contains($hook2, "I don't know that command"));

assertThat('the bot publishes a command menu',
    str_contains($tgSource, 'setMyCommands'));

assertThat('reminders reach Telegram without waiting for the WhatsApp fallback',
    str_contains((string) file_get_contents(__DIR__ . '/../app/services/SchedulerService.php'), 'queueTelegram'));

echo "\n=== 9. The Android API interface is one Retrofit can accept ===\n\n";

$apiService = (string) file_get_contents(
    __DIR__ . '/../android/app/src/main/java/com/akdwk/krishnareminder/data/api/ApiService.kt'
);

/*
 * Kotlin compiles Map<String, Any?> to Java's Map<String, ?>, because Map's
 * value type is declared `out`. Retrofit refuses a wildcard in a @Body and
 * throws the moment the method is first called:
 *
 *   Parameter type must not include a type variable or wildcard:
 *   java.util.Map<java.lang.String, ?> (parameter #1)
 *
 * It compiles cleanly, so only a real phone finds it. Every write in the app
 * — add, edit, quick-add, done/snooze, pay, offline sync push — was dead.
 */
preg_match_all('/@Body\s+\w+:\s*Map<String,\s*([^>]+)>/', $apiService, $bodies);

foreach ($bodies[1] as $valueType) {
    $valueType = trim($valueType);

    // A final type such as String emits no wildcard and is safe as-is.
    $safe = $valueType === 'String'
        || str_contains($valueType, '@JvmSuppressWildcards');

    assertThat('@Body Map value type is wildcard-free: ' . $valueType, $safe,
        'Retrofit rejects Map<String, ?> at call time');
}

assertThat('at least one @Body map is checked', $bodies[1] !== []);

assertThat('no bare Any? survives in a @Body map',
    !preg_match('/@Body\s+\w+:\s*Map<String,\s*Any\??>/', $apiService));

$viewModel = (string) file_get_contents(
    __DIR__ . '/../android/app/src/main/java/com/akdwk/krishnareminder/ui/AppViewModel.kt'
);

$home = (string) file_get_contents(
    __DIR__ . '/../android/app/src/main/java/com/akdwk/krishnareminder/ui/screens/HomeScreen.kt'
);

assertThat('the home screen actually displays the sync message',
    str_contains($home, 'viewModel.message.collectAsState()')
    && str_contains($home, 'syncMessage?.let'),
    'it was being set and never shown, so every failure was invisible');

assertThat('a sync that returns nothing says so',
    str_contains($viewModel, 'the server returned no reminders'),
    'an empty list otherwise looks the same as a failed sync');

assertThat('a failed sync is labelled as a failure',
    str_contains($viewModel, 'Sync failed'));

assertThat('the message can be dismissed', str_contains($viewModel, 'fun clearMessage'));

echo "\n=== 10. The bearer token survives the web server ===\n\n";

/*
 * Apache does not pass Authorization to CGI/FastCGI, which is how PHP-FPM runs
 * on aaPanel. The token is dropped between Apache and PHP, so signing in works
 * — that is a POST body — and every authenticated call then answers 401. The
 * app showed exactly that: "Sync failed (401)".
 */
$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');

assertThat('.htaccess restores the header via mod_rewrite',
    str_contains($htaccess, 'E=HTTP_AUTHORIZATION:%1'));

assertThat('.htaccess also covers mod_setenvif',
    str_contains($htaccess, 'SetEnvIf Authorization'));

assertThat('.htaccess also covers CGIPassAuth',
    str_contains($htaccess, 'CGIPassAuth On'));

assertThat('the rewrite runs before the HTTPS redirect',
    strpos($htaccess, 'E=HTTP_AUTHORIZATION') < strpos($htaccess, 'R=301'),
    'a redirect would end the request first');

// Every place the token can legitimately land must be read.
$originalServer = $_SERVER;

foreach ([
    'HTTP_AUTHORIZATION'          => 'mod_php or SetEnvIf',
    'REDIRECT_HTTP_AUTHORIZATION' => 'the mod_rewrite fallback',
] as $key => $label) {
    $_SERVER = [$key => 'Bearer tok_' . $key];

    assertThat('token found via ' . $label,
        \App\Core\Request::bearerToken() === 'tok_' . $key);
}

$_SERVER = ['HTTP_AUTHORIZATION' => 'bearer lower_case_scheme'];
assertThat('the scheme is matched case-insensitively',
    \App\Core\Request::bearerToken() === 'lower_case_scheme');

$_SERVER = [];
assertThat('no header means no token, not a crash',
    \App\Core\Request::bearerToken() === null);

$_SERVER = ['HTTP_AUTHORIZATION' => 'Bearer'];
assertThat('a malformed header yields nothing',
    \App\Core\Request::bearerToken() === null);

$_SERVER = $originalServer;

$apiIndex = (string) file_get_contents(__DIR__ . '/../api/v1/index.php');

assertThat('health reports whether the header arrived',
    str_contains($apiIndex, "'auth_header_received'"),
    'so this is checkable with curl instead of guessed at');

echo "\n" . str_repeat('-', 78) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nApp PIN, Telegram, paged lists and the English default all check out.\n";
}

exit($fail > 0 ? 1 : 0);
