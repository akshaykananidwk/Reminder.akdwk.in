<?php

namespace App\Services;

use App\Core\App;

/**
 * Analytics for the Reports screen and the exports (CSV / ICS / print-to-PDF).
 */
class ReportService
{
    /**
     * @return array{
     *   totals: array, by_category: array, by_hour: array, daily: array,
     *   avg_delay_minutes: float, best_hour: int|null, worst_hour: int|null
     * }
     */
    public static function build(int $userId, string $fromLocal, string $toLocal, string $tz = 'Asia/Kolkata'): array
    {
        $db = App::i()->db();

        [$fromUtc] = ReminderService::localDayBounds($tz, $fromLocal);
        [, $toUtc] = ReminderService::localDayBounds($tz, $toLocal);

        $totals = $db->one(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN o.status = "done" THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN o.status = "missed" THEN 1 ELSE 0 END) AS missed,
                SUM(CASE WHEN o.status IN ("pending","notified","snoozed") THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN o.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled,
                SUM(o.snooze_count) AS snoozes
              FROM reminder_occurrences o
              JOIN reminders r ON r.id = o.reminder_id
             WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?',
            [$userId, $fromUtc, $toUtc]
        ) ?? [];

        $byCategory = $db->all(
            'SELECT COALESCE(c.code, "other") AS code, c.name_gu, c.name_hi, c.name_en, c.color,
                    COUNT(*) AS total,
                    SUM(CASE WHEN o.status = "done" THEN 1 ELSE 0 END) AS done
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
               LEFT JOIN categories c ON c.id = r.category_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              GROUP BY code, c.name_gu, c.name_hi, c.name_en, c.color
              ORDER BY total DESC',
            [$userId, $fromUtc, $toUtc]
        );

        $byHour = $db->all(
            'SELECT HOUR(CONVERT_TZ(o.due_at, "+00:00", ?)) AS hour,
                    COUNT(*) AS total,
                    SUM(CASE WHEN o.status = "done" THEN 1 ELSE 0 END) AS done
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              GROUP BY hour ORDER BY hour ASC',
            [self::tzOffset($tz), $userId, $fromUtc, $toUtc]
        );

        $daily = $db->all(
            'SELECT DATE(CONVERT_TZ(o.due_at, "+00:00", ?)) AS day,
                    COUNT(*) AS total,
                    SUM(CASE WHEN o.status = "done" THEN 1 ELSE 0 END) AS done,
                    SUM(CASE WHEN o.status = "missed" THEN 1 ELSE 0 END) AS missed
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              GROUP BY day ORDER BY day ASC',
            [self::tzOffset($tz), $userId, $fromUtc, $toUtc]
        );

        $avgDelay = (float) $db->value(
            'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, o.due_at, o.done_at)), 0)
               FROM reminder_occurrences o
              WHERE o.user_id = ? AND o.status = "done" AND o.done_at IS NOT NULL AND o.due_at BETWEEN ? AND ?',
            [$userId, $fromUtc, $toUtc], 0.0
        );

        $best = null;
        $worst = null;
        $bestRate = -1.0;
        $worstRate = 2.0;

        foreach ($byHour as $row) {
            $total = (int) $row['total'];

            if ($total < 2) {
                continue;
            }

            $rate = (int) $row['done'] / $total;

            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = (int) $row['hour'];
            }

            if ($rate < $worstRate) {
                $worstRate = $rate;
                $worst = (int) $row['hour'];
            }
        }

        $total = (int) ($totals['total'] ?? 0);
        $done = (int) ($totals['done'] ?? 0);

        return [
            'totals' => [
                'total'     => $total,
                'done'      => $done,
                'missed'    => (int) ($totals['missed'] ?? 0),
                'pending'   => (int) ($totals['pending'] ?? 0),
                'cancelled' => (int) ($totals['cancelled'] ?? 0),
                'snoozes'   => (int) ($totals['snoozes'] ?? 0),
                'rate'      => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            ],
            'by_category'       => $byCategory,
            'by_hour'           => $byHour,
            'daily'             => $daily,
            'avg_delay_minutes' => round($avgDelay, 1),
            'best_hour'         => $best,
            'worst_hour'        => $worst,
        ];
    }

    /* -------------------------------------------------------------- Exports */

    public static function toCsv(array $rows, array $columns): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        // BOM so Excel opens Gujarati text correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($columns));

        foreach ($rows as $row) {
            $line = [];

            foreach (array_keys($columns) as $key) {
                $line[] = $row[$key] ?? '';
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * RFC 5545 calendar export of a user's reminders.
     */
    public static function toIcs(array $reminders, string $tz = 'Asia/Kolkata'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//AK Computer//Krishna Reminder//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Krishna Reminder',
            'X-WR-TIMEZONE:' . $tz,
        ];

        foreach ($reminders as $reminder) {
            $startTs = strtotime((string) $reminder['start_at'] . ' UTC');

            if ($startTs === false) {
                continue;
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:kr-' . (int) $reminder['id'] . '@reminder.akdwk.in';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $startTs);
            $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $startTs + 1800);
            $lines[] = 'SUMMARY:' . self::escapeIcs((string) $reminder['title']);

            if (!empty($reminder['description'])) {
                $lines[] = 'DESCRIPTION:' . self::escapeIcs((string) $reminder['description']);
            }

            if (!empty($reminder['location'])) {
                $lines[] = 'LOCATION:' . self::escapeIcs((string) $reminder['location']);
            }

            $rule = json_field($reminder['recurrence'] ?? null, ['freq' => 'none']);

            if (($rule['freq'] ?? 'none') !== 'none') {
                $parts = ['FREQ=' . strtoupper((string) $rule['freq'])];

                if (($rule['interval'] ?? 1) > 1) {
                    $parts[] = 'INTERVAL=' . (int) $rule['interval'];
                }

                if (!empty($rule['by_day'])) {
                    $parts[] = 'BYDAY=' . implode(',', array_map('strtoupper', (array) $rule['by_day']));
                }

                if (!empty($rule['by_month_day'])) {
                    $parts[] = 'BYMONTHDAY=' . (int) $rule['by_month_day'];
                }

                $lines[] = 'RRULE:' . implode(';', $parts);
            }

            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:-PT10M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:' . self::escapeIcs((string) $reminder['title']);
            $lines[] = 'END:VALARM';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function escapeIcs(string $value): string
    {
        return str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], trim($value));
    }

    /**
     * MySQL CONVERT_TZ needs an offset string when the tz tables are not loaded.
     */
    private static function tzOffset(string $tz): string
    {
        try {
            $zone = new \DateTimeZone($tz);
            $offset = $zone->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            $offset = 19800; // IST
        }

        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    /**
     * Admin-side platform statistics for the dashboard.
     */
    public static function adminStats(): array
    {
        $db = App::i()->db();
        $today = date('Y-m-d 00:00:00');
        $week = date('Y-m-d H:i:s', strtotime('-7 days'));
        $monthStart = date('Y-m-01 00:00:00');

        return [
            'users_total'      => (int) $db->value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL', [], 0),
            'users_active_today' => (int) $db->value('SELECT COUNT(DISTINCT user_id) FROM reminder_occurrences WHERE updated_at >= ?', [$today], 0),
            'users_new_week'   => (int) $db->value('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$week], 0),
            'reminders_total'  => (int) $db->value('SELECT COUNT(*) FROM reminders WHERE deleted_at IS NULL', [], 0),
            'reminders_today'  => (int) $db->value('SELECT COUNT(*) FROM reminders WHERE created_at >= ?', [$today], 0),
            'delivered_today'  => (int) $db->value('SELECT COUNT(*) FROM deliveries WHERE created_at >= ? AND status = "sent"', [$today], 0),
            'missed_today'     => (int) $db->value('SELECT COUNT(*) FROM reminder_occurrences WHERE status = "missed" AND updated_at >= ?', [$today], 0),
            'wa_sent_today'    => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_log WHERE created_at >= ? AND success = 1', [$today], 0),
            'wa_failed_today'  => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_log WHERE created_at >= ? AND success = 0', [$today], 0),
            'ai_tokens_month'  => (int) $db->value('SELECT COALESCE(SUM(total_tokens),0) FROM ai_logs WHERE created_at >= ?', [$monthStart], 0),
            'ai_cost_month'    => (float) $db->value('SELECT COALESCE(SUM(cost),0) FROM ai_logs WHERE created_at >= ?', [$monthStart], 0.0),
            'revenue_month'    => (float) $db->value('SELECT COALESCE(SUM(total),0) FROM invoices WHERE status = "paid" AND paid_at >= ?', [$monthStart], 0.0),
            'expiring_soon'    => (int) $db->value('SELECT COUNT(*) FROM users WHERE plan_expires_at BETWEEN ? AND ?', [now_utc(), date('Y-m-d H:i:s', strtotime('+7 days'))], 0),
            'queue_pending'    => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_queue WHERE status = "queued"', [], 0),
        ];
    }

    /** Daily AI cost series for the admin chart. */
    public static function aiCostSeries(int $days = 30): array
    {
        $rows = App::i()->db()->all(
            'SELECT DATE(created_at) AS day, SUM(total_tokens) AS tokens, SUM(cost) AS cost, COUNT(*) AS calls
               FROM ai_logs WHERE created_at >= ? GROUP BY day ORDER BY day ASC',
            [date('Y-m-d 00:00:00', strtotime("-$days days"))]
        );

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $series[date('Y-m-d', strtotime("-$i days"))] = ['tokens' => 0, 'cost' => 0.0, 'calls' => 0];
        }

        foreach ($rows as $row) {
            $series[(string) $row['day']] = [
                'tokens' => (int) $row['tokens'],
                'cost'   => (float) $row['cost'],
                'calls'  => (int) $row['calls'],
            ];
        }

        return $series;
    }
}
