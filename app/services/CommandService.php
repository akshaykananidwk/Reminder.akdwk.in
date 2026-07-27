<?php

namespace App\Services;

use App\Core\App;
use App\Core\Lang;

/**
 * Zero-token WhatsApp quick commands (Section 6.4). Anything that is not a
 * command falls through to Gemini.
 */
class CommandService
{
    /**
     * @return array{handled: bool, reply: string, action: string}
     */
    public static function handle(string $text, array $user): array
    {
        $raw = trim($text);

        if ($raw === '') {
            return self::miss();
        }

        $normalised = FallbackParser::normaliseDigits(mb_strtolower($raw));
        $lang = (string) ($user['language'] ?? 'gu');
        $userId = (int) $user['id'];

        // Commands with an argument: DONE A12, SNOOZE A12 10, PAID A12 5000.
        if (preg_match('/^(done|થઈ\s*ગયું|થઈગયું|हो\s*गया|complete)\s+([a-z0-9]{2,6})/u', $normalised, $m)) {
            return self::markDone($user, $m[2], $lang);
        }

        if (preg_match('/^(snooze|પછી|बाद)\s+([a-z0-9]{2,6})(?:\s+(\d{1,4}))?/u', $normalised, $m)) {
            return self::snooze($user, $m[2], isset($m[3]) ? (int) $m[3] : null, $lang);
        }

        if (preg_match('/^(cancel|રદ|रद्द|रद)\s+([a-z0-9]{2,6})/u', $normalised, $m)) {
            return self::cancel($user, $m[2], $lang);
        }

        if (preg_match('/^(paid|ચૂકવ્યા|चुकाया|चुकाए)\s+([a-z0-9]{2,6})(?:\s+([\d,.]+))?/u', $normalised, $m)) {
            return self::markPaid($user, $m[2], isset($m[3]) ? (float) str_replace(',', '', $m[3]) : null, $lang);
        }

        if (preg_match('/^lang\s+(gu|hi|en)$/u', $normalised, $m)) {
            return self::setLanguage($user, $m[1]);
        }

        // Single-word commands.
        $word = trim($normalised, " \t\n\r.!?*_");

        $map = [
            'list'    => ['list', 'યાદી', 'यादी', 'सूची', 'लिस्ट'],
            'today'   => ['today', 'આજે', 'આજ', 'आज'],
            'pending' => ['pending', 'બાકી', 'बाकी', 'बकाया', 'overdue'],
            'summary' => ['summary', 'સારાંશ', 'સમરી', 'सारांश', 'रिपोर्ट', 'report'],
            'help'    => ['help', 'મદદ', 'मदद', 'menu', 'commands'],
            'stop'    => ['stop', 'બંધ', 'बंद', 'pause'],
            'start'   => ['start', 'ચાલુ', 'चालू', 'resume'],
        ];

        foreach ($map as $command => $words) {
            if (in_array($word, $words, true)) {
                return match ($command) {
                    'list', 'today' => self::listToday($userId, $lang, $user),
                    'pending'       => self::listPending($userId, $lang, $user),
                    'summary'       => self::summary($userId, $lang, $user),
                    'help'          => ['handled' => true, 'reply' => TemplateService::render('help', $lang), 'action' => 'help'],
                    'stop'          => self::togglePause($user, true, $lang),
                    'start'         => self::togglePause($user, false, $lang),
                };
            }
        }

        return self::miss();
    }

    /* ------------------------------------------------------------- Handlers */

    private static function markDone(array $user, string $code, string $lang): array
    {
        $reminder = ReminderService::findByShortCode((int) $user['id'], $code);

        if ($reminder === null) {
            return ['handled' => true, 'reply' => Lang::get('wa.not_found', ['code' => strtoupper($code)], $lang), 'action' => 'done'];
        }

        $occurrence = ReminderService::activeOccurrenceFor((int) $reminder['id']);

        if ($occurrence === null) {
            return ['handled' => true, 'reply' => Lang::get('wa.nothing_pending', ['code' => $reminder['short_code']], $lang), 'action' => 'done'];
        }

        ReminderService::complete((int) $occurrence['id'], (int) $user['id'], 'whatsapp');

        $fresh = App::i()->db()->one('SELECT streak_days FROM users WHERE id = ?', [(int) $user['id']]);

        return [
            'handled' => true,
            'reply'   => TemplateService::render('reminder_done', $lang, [
                'code'   => $reminder['short_code'],
                'title'  => $reminder['title'],
                'streak' => (int) ($fresh['streak_days'] ?? 0),
            ]),
            'action' => 'done',
        ];
    }

    private static function snooze(array $user, string $code, ?int $minutes, string $lang): array
    {
        $reminder = ReminderService::findByShortCode((int) $user['id'], $code);

        if ($reminder === null) {
            return ['handled' => true, 'reply' => Lang::get('wa.not_found', ['code' => strtoupper($code)], $lang), 'action' => 'snooze'];
        }

        $occurrence = ReminderService::activeOccurrenceFor((int) $reminder['id']);

        if ($occurrence === null) {
            return ['handled' => true, 'reply' => Lang::get('wa.nothing_pending', ['code' => $reminder['short_code']], $lang), 'action' => 'snooze'];
        }

        $result = ReminderService::snooze((int) $occurrence['id'], (int) $user['id'], $minutes, 'whatsapp');

        if (!$result['ok']) {
            return ['handled' => true, 'reply' => (string) $result['message'], 'action' => 'snooze'];
        }

        return [
            'handled' => true,
            'reply'   => TemplateService::render('reminder_snoozed', $lang, [
                'code'    => $reminder['short_code'],
                'minutes' => $result['minutes'],
                'time'    => ReminderService::formatWhen((string) $result['due_at'], $lang, (string) $user['timezone']),
            ]),
            'action' => 'snooze',
        ];
    }

    private static function cancel(array $user, string $code, string $lang): array
    {
        $reminder = ReminderService::findByShortCode((int) $user['id'], $code);

        if ($reminder === null) {
            return ['handled' => true, 'reply' => Lang::get('wa.not_found', ['code' => strtoupper($code)], $lang), 'action' => 'cancel'];
        }

        ReminderService::cancelReminder((int) $reminder['id'], (int) $user['id']);

        return [
            'handled' => true,
            'reply'   => Lang::get('wa.cancelled', ['code' => $reminder['short_code'], 'title' => $reminder['title']], $lang),
            'action'  => 'cancel',
        ];
    }

    private static function markPaid(array $user, string $code, ?float $amount, string $lang): array
    {
        $reminder = ReminderService::findByShortCode((int) $user['id'], $code);

        if ($reminder === null) {
            return ['handled' => true, 'reply' => Lang::get('wa.not_found', ['code' => strtoupper($code)], $lang), 'action' => 'paid'];
        }

        $db = App::i()->db();
        $payment = $db->one('SELECT * FROM payments WHERE reminder_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [
            (int) $reminder['id'], (int) $user['id'],
        ]);

        $amount ??= (float) ($payment['amount'] ?? $reminder['amount'] ?? 0);

        if ($payment === null) {
            $paymentId = $db->insert('payments', [
                'user_id'     => (int) $user['id'],
                'reminder_id' => (int) $reminder['id'],
                'party_name'  => (string) ($reminder['person_name'] ?: $reminder['title']),
                'direction'   => 'payable',
                'amount'      => $amount,
                'currency'    => (string) $reminder['currency'],
                'due_date'    => substr((string) $reminder['start_at'], 0, 10),
                'status'      => 'unpaid',
                'created_at'  => now_utc(),
            ]);
            $payment = $db->one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        }

        PaymentService::recordPayment((int) $payment['id'], (int) $user['id'], $amount, 'whatsapp');

        $occurrence = ReminderService::activeOccurrenceFor((int) $reminder['id']);

        if ($occurrence !== null) {
            ReminderService::complete((int) $occurrence['id'], (int) $user['id'], 'whatsapp');
        }

        return [
            'handled' => true,
            'reply'   => TemplateService::render('payment_received', $lang, [
                'name'   => (string) $payment['party_name'],
                'amount' => money($amount, (string) $payment['currency']),
                'time'   => ReminderService::formatWhen(now_utc(), $lang, (string) $user['timezone']),
            ]),
            'action' => 'paid',
        ];
    }

    private static function listToday(int $userId, string $lang, array $user): array
    {
        $rows = ReminderService::todayOccurrences($userId, (string) $user['timezone'], ['pending', 'notified', 'snoozed', 'missed']);

        if ($rows === []) {
            return ['handled' => true, 'reply' => Lang::get('wa.no_tasks_today', [], $lang), 'action' => 'list'];
        }

        $lines = [Lang::get('wa.today_header', ['count' => count($rows)], $lang), ''];

        foreach ($rows as $row) {
            $lines[] = self::formatLine($row, $lang, (string) $user['timezone']);
        }

        $lines[] = '';
        $lines[] = Lang::get('wa.list_footer', [], $lang);

        return ['handled' => true, 'reply' => implode("\n", $lines), 'action' => 'list'];
    }

    private static function listPending(int $userId, string $lang, array $user): array
    {
        $overdue = ReminderService::overdue($userId, 15);
        $upcoming = ReminderService::upcoming($userId, 15);

        if ($overdue === [] && $upcoming === []) {
            return ['handled' => true, 'reply' => Lang::get('wa.nothing_pending_all', [], $lang), 'action' => 'pending'];
        }

        $lines = [];

        if ($overdue !== []) {
            $lines[] = Lang::get('wa.overdue_header', ['count' => count($overdue)], $lang);

            foreach ($overdue as $row) {
                $lines[] = self::formatLine($row, $lang, (string) $user['timezone']);
            }

            $lines[] = '';
        }

        if ($upcoming !== []) {
            $lines[] = Lang::get('wa.upcoming_header', ['count' => count($upcoming)], $lang);

            foreach ($upcoming as $row) {
                $lines[] = self::formatLine($row, $lang, (string) $user['timezone']);
            }
        }

        return ['handled' => true, 'reply' => implode("\n", $lines), 'action' => 'pending'];
    }

    private static function summary(int $userId, string $lang, array $user): array
    {
        $body = SummaryService::buildNightSummary($userId, $user);

        return ['handled' => true, 'reply' => $body !== '' ? $body : Lang::get('wa.no_tasks_today', [], $lang), 'action' => 'summary'];
    }

    private static function setLanguage(array $user, string $lang): array
    {
        App::i()->db()->update('users', ['language' => $lang], 'id = :id', ['id' => (int) $user['id']]);

        return [
            'handled' => true,
            'reply'   => Lang::get('wa.language_changed', ['lang' => Lang::nativeName($lang)], $lang),
            'action'  => 'lang',
        ];
    }

    private static function togglePause(array $user, bool $paused, string $lang): array
    {
        App::i()->db()->update('users', ['reminders_paused' => $paused ? 1 : 0], 'id = :id', ['id' => (int) $user['id']]);

        return [
            'handled' => true,
            'reply'   => Lang::get($paused ? 'wa.paused' : 'wa.resumed', [], $lang),
            'action'  => $paused ? 'stop' : 'start',
        ];
    }

    /* -------------------------------------------------------------- Helpers */

    private static function formatLine(array $row, string $lang, string $tz): string
    {
        $time = to_user_time((string) $row['due_at'], 'h:i A', $tz);
        $icon = match ((string) ($row['status'] ?? 'pending')) {
            'done'    => '✅',
            'missed'  => '❌',
            'snoozed' => '⏰',
            default   => '•',
        };

        $amount = !empty($row['amount']) ? ' — ' . money((float) $row['amount'], (string) ($row['currency'] ?? 'INR')) : '';

        return sprintf('%s %s — %s%s  [%s]', $icon, $time, str_limit((string) $row['title'], 60), $amount, (string) $row['short_code']);
    }

    private static function miss(): array
    {
        return ['handled' => false, 'reply' => '', 'action' => 'none'];
    }
}
