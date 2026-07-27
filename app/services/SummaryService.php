<?php

namespace App\Services;

use App\Core\App;
use App\Core\Lang;

/**
 * Morning brief and night summary (Section 12), stored so the app can show the
 * same text the user received on WhatsApp.
 */
class SummaryService
{
    /**
     * Build (and persist) the night summary body for a user.
     */
    public static function buildNightSummary(int $userId, ?array $user = null, ?string $localDate = null): string
    {
        $db = App::i()->db();
        $user ??= $db->one('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            return '';
        }

        $lang = (string) $user['language'];
        $tz = (string) $user['timezone'];
        $localDate ??= (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');

        [$dayStart, $dayEnd] = ReminderService::localDayBounds($tz, $localDate);
        $tomorrow = date('Y-m-d', strtotime($localDate . ' +1 day'));
        [$tomorrowStart, $tomorrowEnd] = ReminderService::localDayBounds($tz, $tomorrow);

        $done = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = ? AND status = "done" AND done_at BETWEEN ? AND ?',
            [$userId, $dayStart, $dayEnd], 0
        );

        $pending = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = ? AND status IN ("pending","notified","snoozed") AND due_at BETWEEN ? AND ?',
            [$userId, $dayStart, $dayEnd], 0
        );

        $missed = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = ? AND status = "missed" AND due_at BETWEEN ? AND ?',
            [$userId, $dayStart, $dayEnd], 0
        );

        $paid = (float) $db->value(
            'SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE user_id = ? AND paid_at BETWEEN ? AND ?',
            [$userId, $dayStart, $dayEnd], 0.0
        );

        $tomorrowRows = $db->all(
            'SELECT o.due_at, r.title, r.amount, r.currency, r.short_code
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL
                AND o.status IN ("pending","snoozed")
                AND o.due_at BETWEEN ? AND ?
              ORDER BY o.due_at ASC LIMIT 15',
            [$userId, $tomorrowStart, $tomorrowEnd]
        );

        $list = [];
        foreach ($tomorrowRows as $row) {
            $amount = !empty($row['amount']) ? ' — ' . money((float) $row['amount'], (string) $row['currency']) : '';
            $list[] = sprintf('• %s — %s%s', to_user_time((string) $row['due_at'], 'H:i', $tz), str_limit((string) $row['title'], 55), $amount);
        }

        $body = TemplateService::render('night_summary', $lang, [
            'date'           => self::localisedDate($localDate, $lang),
            'done'           => $done,
            'pending'        => $pending,
            'missed'         => $missed,
            'amount'         => money($paid, 'INR'),
            'tomorrow_count' => count($tomorrowRows),
            'tomorrow_list'  => $list === [] ? Lang::get('summary.nothing_tomorrow', [], $lang) : implode("\n", $list),
            'streak'         => (int) $user['streak_days'],
        ]);

        self::persist($userId, $localDate, 'night', $done, $pending, $missed, $paid, $body);

        return $body;
    }

    public static function buildMorningBrief(int $userId, ?array $user = null, ?string $localDate = null): string
    {
        $db = App::i()->db();
        $user ??= $db->one('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            return '';
        }

        $lang = (string) $user['language'];
        $tz = (string) $user['timezone'];
        $localDate ??= (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');

        [$dayStart, $dayEnd] = ReminderService::localDayBounds($tz, $localDate);

        $rows = $db->all(
            'SELECT o.due_at, r.title, r.amount, r.currency, r.short_code, r.type
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL
                AND o.status IN ("pending","snoozed")
                AND o.due_at BETWEEN ? AND ?
              ORDER BY o.due_at ASC LIMIT 25',
            [$userId, $dayStart, $dayEnd]
        );

        if ($rows === []) {
            return '';
        }

        $lines = [];
        foreach ($rows as $row) {
            $amount = !empty($row['amount']) ? ' — ' . money((float) $row['amount'], (string) $row['currency']) : '';
            $lines[] = sprintf('• %s — %s%s  [%s]', to_user_time((string) $row['due_at'], 'H:i', $tz), str_limit((string) $row['title'], 50), $amount, (string) $row['short_code']);
        }

        $body = TemplateService::render('morning_brief', $lang, [
            'name'  => (string) $user['name'],
            'date'  => self::localisedDate($localDate, $lang),
            'list'  => implode("\n", $lines),
            'count' => count($rows),
        ]);

        self::persist($userId, $localDate, 'morning', 0, count($rows), 0, 0.0, $body);

        return $body;
    }

    /**
     * Users whose configured local time matches the current tick.
     *
     * @return array<int, array>
     */
    public static function usersDueFor(string $kind, int $toleranceMinutes = 5): array
    {
        $column = $kind === 'morning' ? 'morning_brief_time' : 'night_summary_time';
        $flag = $kind === 'morning' ? 'morning_brief_enabled' : 'night_summary_enabled';

        $rows = App::i()->db()->all(
            'SELECT u.*, s.' . $column . ' AS target_time
               FROM users u
               JOIN user_settings s ON s.user_id = u.id
              WHERE u.is_active = 1 AND u.deleted_at IS NULL AND s.' . $flag . ' = 1'
        );

        $due = [];
        $today = [];

        foreach ($rows as $row) {
            try {
                $now = new \DateTime('now', new \DateTimeZone((string) $row['timezone']));
            } catch (\Throwable) {
                continue;
            }

            $localDate = $now->format('Y-m-d');
            $target = (string) $row['target_time'];
            $targetMinutes = ((int) substr($target, 0, 2)) * 60 + (int) substr($target, 3, 2);
            $nowMinutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');
            $delta = $nowMinutes - $targetMinutes;

            if ($delta < 0 || $delta > $toleranceMinutes) {
                continue;
            }

            // Skip anyone who already received today's message.
            $already = App::i()->db()->value(
                'SELECT id FROM summaries WHERE user_id = ? AND summary_date = ? AND kind = ? AND sent_at IS NOT NULL',
                [(int) $row['id'], $localDate, $kind]
            );

            if ($already !== null) {
                continue;
            }

            $row['local_date'] = $localDate;
            $due[] = $row;
            $today[] = (int) $row['id'];
        }

        return $due;
    }

    public static function markSent(int $userId, string $localDate, string $kind): void
    {
        try {
            App::i()->db()->query(
                'UPDATE summaries SET sent_at = ? WHERE user_id = ? AND summary_date = ? AND kind = ?',
                [now_utc(), $userId, $localDate, $kind]
            );
        } catch (\Throwable) {
            // Non-fatal.
        }
    }

    private static function persist(int $userId, string $date, string $kind, int $done, int $pending, int $missed, float $paid, string $body): void
    {
        try {
            App::i()->db()->upsert('summaries', [
                'user_id'       => $userId,
                'summary_date'  => $date,
                'kind'          => $kind,
                'done_count'    => $done,
                'pending_count' => $pending,
                'missed_count'  => $missed,
                'paid_amount'   => $paid,
                'body'          => $body,
                'created_at'    => now_utc(),
            ], ['done_count', 'pending_count', 'missed_count', 'paid_amount', 'body']);
        } catch (\Throwable) {
            // Summary text still gets sent even if it cannot be stored.
        }
    }

    private static function localisedDate(string $date, string $lang): string
    {
        $ts = strtotime($date);

        if ($ts === false) {
            return $date;
        }

        $month = Lang::get('months.' . strtolower(date('M', $ts)), [], $lang);

        return date('j', $ts) . ' ' . ($month !== '' ? $month : date('F', $ts));
    }
}
