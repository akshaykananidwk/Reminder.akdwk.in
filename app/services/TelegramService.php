<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Logger;

/**
 * Telegram bot channel.
 *
 *   POST https://api.telegram.org/bot{token}/sendMessage
 *
 * A third way to reach the user, beside the bulk WhatsApp gateway and the Meta
 * Cloud API. It is genuinely useful here because Telegram has none of
 * WhatsApp's restrictions: no 24-hour window, no template approval, no
 * per-conversation charge. A bot can message anyone who has started a chat
 * with it, at any time, for free.
 *
 * The catch is the other direction: Telegram will not tell us a user's chat id
 * until they message the bot first. So the user is given a one-time code on the
 * website and sends `/start <code>` to the bot, which links the two.
 */
class TelegramService
{
    private const API = 'https://api.telegram.org/bot';
    private const LINK_CODE_TTL = 900; // 15 minutes

    /* --------------------------------------------------------- Configuration */

    /**
     * @return array{ok: bool, level: string, message: string, hint: string}
     */
    public static function status(): array
    {
        $settings = App::i()->settings();
        $token = trim((string) $settings->get('tg_bot_token', ''));

        if ($token === '') {
            return [
                'ok'      => false,
                'level'   => 'info',
                'message' => 'Telegram is not configured.',
                'hint'    => 'Create a bot with @BotFather on Telegram and paste its token here.',
            ];
        }

        // A bot token always looks like 123456789:AA... — catching a mangled
        // paste here is far kinder than a 404 from Telegram later.
        if (preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $token) !== 1) {
            return [
                'ok'      => false,
                'level'   => 'error',
                'message' => 'That does not look like a bot token.',
                'hint'    => 'It should look like 123456789:AAExampleTokenFromBotFather.',
            ];
        }

        if (!$settings->bool('tg_enabled', false)) {
            return [
                'ok'      => false,
                'level'   => 'warn',
                'message' => 'Telegram is configured but switched off.',
                'hint'    => 'Tick "Telegram enabled" to start sending through it.',
            ];
        }

        return ['ok' => true, 'level' => 'success', 'message' => 'Telegram is ready.', 'hint' => ''];
    }

    public static function isConfigured(): bool
    {
        return self::status()['ok'];
    }

    private static function call(string $method, array $payload, int $timeout = 20): array
    {
        $token = (string) App::i()->settings()->get('tg_bot_token', '');

        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'body' => 'Telegram is not configured', 'json' => null, 'error' => 'not_configured', 'latency_ms' => 0];
        }

        return HttpClient::postJson(self::API . $token . '/' . $method, $payload, [], $timeout);
    }

    /* ---------------------------------------------------------------- Send */

    /**
     * Send to one chat id.
     *
     * @return array{ok: bool, status: int, response: string, latency_ms: int}
     */
    public static function send(string $chatId, string $message, ?string $mediaUrl = null, ?array $keyboard = null): array
    {
        $chatId = trim($chatId);

        if ($chatId === '' || trim($message) === '') {
            return ['ok' => false, 'status' => 0, 'response' => 'Missing chat id or message', 'latency_ms' => 0];
        }

        if ($mediaUrl !== null && $mediaUrl !== '') {
            $payload = [
                'chat_id'    => $chatId,
                'photo'      => $mediaUrl,
                'caption'    => self::toHtml(mb_substr($message, 0, 1024)),
                'parse_mode' => 'HTML',
            ];
        } else {
            $payload = [
                'chat_id'                  => $chatId,
                'text'                     => self::toHtml(mb_substr($message, 0, 4096)),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ];
        }

        if ($keyboard !== null && $keyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $result = self::call($mediaUrl !== null && $mediaUrl !== '' ? 'sendPhoto' : 'sendMessage', $payload);

        $ok = $result['ok'] && ($result['json']['ok'] ?? false) === true;

        return [
            'ok'         => $ok,
            'status'     => $result['status'],
            'response'   => $ok
                ? 'message_id ' . (string) ($result['json']['result']['message_id'] ?? '?')
                : self::explain($result['status'], $result['json']),
            'latency_ms' => $result['latency_ms'],
        ];
    }

    /** Send to a user by id, if they have linked Telegram. */
    public static function sendToUser(int $userId, string $message, ?string $mediaUrl = null, ?array $keyboard = null): array
    {
        $chatId = App::i()->db()->value('SELECT telegram_chat_id FROM users WHERE id = ?', [$userId]);

        if (!is_string($chatId) || $chatId === '') {
            return ['ok' => false, 'status' => 0, 'response' => 'This user has not linked Telegram', 'latency_ms' => 0];
        }

        return self::send($chatId, $message, $mediaUrl, $keyboard);
    }

    /* -------------------------------------------------------------- Buttons */

    /**
     * The buttons under a reminder. Tapping one acts immediately — no typing,
     * no switching to the app.
     *
     * callback_data is capped at 64 bytes by Telegram, so it carries only the
     * action and the occurrence id; everything else is looked up server-side.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public static function reminderKeyboard(int $occurrenceId, string $lang = 'en'): array
    {
        $done = match ($lang) {
            'gu' => '✅ થઈ ગયું',
            'hi' => '✅ हो गया',
            default => '✅ Done',
        };

        $snooze = static fn (int $min): string => match ($lang) {
            'gu' => '⏰ ' . $min . ' મિનિટ',
            'hi' => '⏰ ' . $min . ' मिनट',
            default => '⏰ ' . $min . ' min',
        };

        $cancel = match ($lang) {
            'gu' => '✖️ રદ કરો',
            'hi' => '✖️ रद्द करें',
            default => '✖️ Cancel',
        };

        return [
            [
                ['text' => $done, 'callback_data' => 'done:' . $occurrenceId],
            ],
            [
                ['text' => $snooze(10), 'callback_data' => 'snooze:' . $occurrenceId . ':10'],
                ['text' => $snooze(30), 'callback_data' => 'snooze:' . $occurrenceId . ':30'],
                ['text' => $snooze(60), 'callback_data' => 'snooze:' . $occurrenceId . ':60'],
            ],
            [
                ['text' => $cancel, 'callback_data' => 'cancel:' . $occurrenceId],
            ],
        ];
    }

    /** Answer a button tap so Telegram stops showing its loading spinner. */
    public static function answerCallback(string $callbackId, string $text = '', bool $alert = false): void
    {
        if ($callbackId === '') {
            return;
        }

        self::call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => mb_substr($text, 0, 200),
            'show_alert'        => $alert,
        ], 10);
    }

    /**
     * Replace a message's buttons — used to strike out the row once an action
     * has been taken, so the same button cannot be tapped twice.
     */
    public static function editMessage(string $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        self::call('editMessageText', array_filter([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => self::toHtml(mb_substr($text, 0, 4096)),
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard === null ? null : ['inline_keyboard' => $keyboard],
        ], static fn ($v): bool => $v !== null), 15);
    }

    /**
     * The product's messages are written with WhatsApp's *bold* and _italic_.
     * Telegram uses HTML here, so convert rather than showing the asterisks.
     */
    public static function toHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escaped = (string) preg_replace('/\*([^*\n]+)\*/u', '<b>$1</b>', $escaped);
        $escaped = (string) preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '<i>$1</i>', $escaped);
        $escaped = (string) preg_replace('/```(.+?)```/us', '<pre>$1</pre>', $escaped);
        $escaped = (string) preg_replace('/`([^`\n]+)`/u', '<code>$1</code>', $escaped);

        return $escaped;
    }

    public static function explain(int $status, ?array $json): string
    {
        $description = is_array($json) ? (string) ($json['description'] ?? '') : '';
        $code = is_array($json) ? (int) ($json['error_code'] ?? 0) : 0;

        return match (true) {
            $status === 0 =>
                'Could not reach api.telegram.org — check outbound HTTPS from the server.',
            $code === 401 =>
                'The bot token is wrong or has been revoked. Get a fresh one from @BotFather.',
            $code === 403 =>
                'The user has blocked the bot, or has never sent it a message. '
                . 'They must open the bot and press Start first.',
            $code === 400 && str_contains($description, 'chat not found') =>
                'That chat id does not exist. The user needs to link Telegram again.',
            $code === 429 =>
                'Telegram is rate limiting the bot. The message will be retried.',
            $description !== '' => $description,
            default => 'HTTP ' . $status,
        };
    }

    /* --------------------------------------------------------------- Linking */

    /**
     * Issue a one-time code. The user sends `/start <code>` to the bot and the
     * webhook links their chat id.
     */
    public static function createLinkCode(int $userId): string
    {
        $db = App::i()->db();

        // Only one live code per user, so an old one cannot linger.
        $db->query('DELETE FROM telegram_link_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);

        $code = strtoupper(Crypto::randomToken(4));

        $db->insert('telegram_link_codes', [
            'user_id'    => $userId,
            'code'       => $code,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::LINK_CODE_TTL),
            'created_at' => now_utc(),
        ]);

        return $code;
    }

    /**
     * Redeem a code and attach the chat id to that account.
     *
     * @return array{ok: bool, user: array|null, message: string}
     */
    public static function redeemLinkCode(string $code, string $chatId, ?string $username): array
    {
        $db = App::i()->db();
        $code = strtoupper(trim($code));

        if ($code === '' || $chatId === '') {
            return ['ok' => false, 'user' => null, 'message' => 'Missing code.'];
        }

        $row = $db->one(
            'SELECT * FROM telegram_link_codes WHERE code = ? AND used_at IS NULL LIMIT 1',
            [$code]
        );

        if ($row === null) {
            return ['ok' => false, 'user' => null, 'message' => 'That code is not valid. Get a fresh one from the website.'];
        }

        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return ['ok' => false, 'user' => null, 'message' => 'That code has expired. Get a fresh one from the website.'];
        }

        $userId = (int) $row['user_id'];

        // One Telegram account per user: if this chat is already attached
        // somewhere else, detach it first rather than ending up with two
        // accounts pointing at the same chat.
        $db->query('UPDATE users SET telegram_chat_id = NULL WHERE telegram_chat_id = ? AND id <> ?', [$chatId, $userId]);

        $db->update('users', [
            'telegram_chat_id'   => $chatId,
            'telegram_username'  => $username !== null && $username !== '' ? mb_substr($username, 0, 64) : null,
            'telegram_linked_at' => now_utc(),
        ], 'id = :id', ['id' => $userId]);

        $db->update('telegram_link_codes', ['used_at' => now_utc()], 'id = :id', ['id' => (int) $row['id']]);

        Logger::info('Telegram linked', ['user_id' => $userId], 'telegram');

        return [
            'ok'      => true,
            'user'    => $db->one('SELECT * FROM users WHERE id = ?', [$userId]),
            'message' => 'Linked.',
        ];
    }

    public static function unlink(int $userId): void
    {
        App::i()->db()->update('users', [
            'telegram_chat_id'   => null,
            'telegram_username'  => null,
            'telegram_linked_at' => null,
        ], 'id = :id', ['id' => $userId]);

        AuditService::log('user.telegram_unlinked', 'user', $userId);
    }

    /* --------------------------------------------------------------- Webhook */

    /**
     * Telegram echoes a secret token we choose back in this header on every
     * update, which is how the endpoint knows the request is really Telegram's.
     */
    public static function verifySecret(?string $header): bool
    {
        $expected = trim((string) App::i()->settings()->get('tg_webhook_secret', ''));

        return $expected !== '' && is_string($header) && hash_equals($expected, $header);
    }

    /**
     * Normalise an update into the same shape the WhatsApp path produces.
     *
     * @return array{chat_id: string, username: string|null, text: string, message_id: string|null, is_start: bool, start_payload: string}|null
     */
    /**
     * A button tap, if this update is one.
     *
     * @return array{id: string, chat_id: string, message_id: int, action: string, occurrence_id: int, minutes: int}|null
     */
    public static function parseCallback(array $update): ?array
    {
        $query = $update['callback_query'] ?? null;

        if (!is_array($query)) {
            return null;
        }

        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $data = (string) ($query['data'] ?? '');

        if ($chatId === '' || $data === '') {
            return null;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        if (!in_array($action, ['done', 'snooze', 'cancel'], true)) {
            return null;
        }

        return [
            'id'            => (string) ($query['id'] ?? ''),
            'chat_id'       => $chatId,
            'message_id'    => (int) ($query['message']['message_id'] ?? 0),
            'action'        => $action,
            'occurrence_id' => (int) ($parts[1] ?? 0),
            'minutes'       => (int) ($parts[2] ?? 10),
        ];
    }

    public static function parseUpdate(array $update): ?array
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (!is_array($message)) {
            return null;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($chatId === '') {
            return null;
        }

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $isStart = str_starts_with($text, '/start');

        return [
            'chat_id'       => $chatId,
            'username'      => isset($message['from']['username']) ? (string) $message['from']['username'] : null,
            'text'          => $text,
            'message_id'    => isset($message['message_id']) ? (string) $message['message_id'] : null,
            'is_start'      => $isStart,
            'start_payload' => $isStart ? trim(substr($text, 6)) : '',
        ];
    }

    public static function userByChatId(string $chatId): ?array
    {
        if ($chatId === '') {
            return null;
        }

        return App::i()->db()->one(
            'SELECT * FROM users WHERE telegram_chat_id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
            [$chatId]
        );
    }

    /* ----------------------------------------------------------------- Setup */

    /** Point Telegram's webhook at this site. */
    public static function registerWebhook(): array
    {
        $settings = App::i()->settings();
        $secret = trim((string) $settings->get('tg_webhook_secret', ''));

        if ($secret === '') {
            $secret = Crypto::randomToken(16);
            $settings->set('tg_webhook_secret', $secret, false, 'telegram');
        }

        $result = self::call('setWebhook', [
            'url'             => App::i()->url('/api/tg_webhook.php'),
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            // Old updates queued while the webhook was unset are stale by now
            // and would fire a burst of duplicate replies.
            'drop_pending_updates' => true,
        ]);

        $ok = $result['ok'] && ($result['json']['ok'] ?? false) === true;

        return [
            'ok'      => $ok,
            'message' => $ok
                ? 'Webhook registered with Telegram.'
                : self::explain($result['status'], $result['json']),
        ];
    }

    public static function deleteWebhook(): array
    {
        $result = self::call('deleteWebhook', ['drop_pending_updates' => false]);
        $ok = $result['ok'] && ($result['json']['ok'] ?? false) === true;

        return ['ok' => $ok, 'message' => $ok ? 'Webhook removed.' : self::explain($result['status'], $result['json'])];
    }

    /**
     * Ask Telegram who the bot is. Sends nothing to anyone.
     *
     * @return array{ok: bool, message: string, hint: string, username: string}
     */
    public static function testConnection(): array
    {
        $token = trim((string) App::i()->settings()->get('tg_bot_token', ''));

        if ($token === '') {
            return ['ok' => false, 'message' => 'No bot token saved.', 'hint' => 'Create a bot with @BotFather.', 'username' => ''];
        }

        $result = self::call('getMe', []);
        $ok = $result['ok'] && ($result['json']['ok'] ?? false) === true;

        if (!$ok) {
            return [
                'ok'      => false,
                'message' => 'Telegram rejected the token.',
                'hint'    => self::explain($result['status'], $result['json']),
                'username' => '',
            ];
        }

        $bot = $result['json']['result'] ?? [];
        $username = (string) ($bot['username'] ?? '');

        // Remember it so the link instructions can name the bot.
        if ($username !== '') {
            App::i()->settings()->set('tg_bot_username', $username, false, 'telegram');
        }

        return [
            'ok'       => true,
            'message'  => 'Connected to @' . $username . ' (' . (string) ($bot['first_name'] ?? '') . ').',
            'hint'     => '',
            'username' => $username,
        ];
    }
}
