<?php

namespace App\Services;

use App\Core\App;
use DateInterval;
use DateTime;
use DateTimeZone;

/**
 * Expands recurrence rules into concrete occurrence datetimes.
 *
 * Occurrences are materialised ahead of time (30 days by default) so the
 * one-minute dispatcher only ever does an indexed range scan.
 */
class RecurrenceService
{
    private const DAY_CODES = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * Compute occurrence datetimes (UTC 'Y-m-d H:i:s') for a reminder between
     * two UTC instants.
     *
     * @return array<int, string>
     */
    public static function expand(array $reminder, string $fromUtc, string $toUtc, int $limit = 400): array
    {
        $rule = json_field($reminder['recurrence'] ?? null, ['freq' => 'none']);
        $freq = (string) ($rule['freq'] ?? 'none');
        $tz = self::timezoneFor($reminder);

        $start = self::toDate((string) $reminder['start_at']);
        $from = self::toDate($fromUtc);
        $to = self::toDate($toUtc);

        if ($start === null || $from === null || $to === null) {
            return [];
        }

        if ($freq === 'none' || $freq === '') {
            return ($start >= $from && $start <= $to) ? [$start->format('Y-m-d H:i:s')] : [];
        }

        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $until = !empty($rule['until']) ? self::toDate((string) $rule['until']) : null;

        if (!empty($reminder['end_at'])) {
            $endAt = self::toDate((string) $reminder['end_at']);

            if ($endAt !== null && ($until === null || $endAt < $until)) {
                $until = $endAt;
            }
        }

        $maxCount = $rule['count'] ?? ($reminder['recurrence_count'] ?? null);
        $maxCount = $maxCount === null ? null : max(1, (int) $maxCount);

        // Work in the user's local timezone so DST and "10 AM" stay stable.
        $localStart = clone $start;
        $localStart->setTimezone($tz);

        $out = [];
        $emitted = 0;
        $cursor = clone $localStart;
        $guard = 0;

        while ($guard++ < 5000 && count($out) < $limit) {
            $utc = (clone $cursor)->setTimezone(new DateTimeZone('UTC'));

            if ($until !== null && $utc > $until) {
                break;
            }

            if ($utc > $to) {
                break;
            }

            $matches = match ($freq) {
                'weekly'  => self::matchesWeekly($cursor, $localStart, $rule, $interval),
                'monthly' => self::matchesMonthly($cursor, $localStart, $rule, $interval),
                'yearly'  => self::matchesYearly($cursor, $localStart, $interval),
                default   => self::matchesDaily($cursor, $localStart, $interval),
            };

            if ($matches) {
                $emitted++;

                if ($maxCount !== null && $emitted > $maxCount) {
                    break;
                }

                if ($utc >= $from) {
                    $out[] = $utc->format('Y-m-d H:i:s');
                }
            }

            $cursor->add(new DateInterval('P1D'));
        }

        return $out;
    }

    private static function matchesDaily(DateTime $cursor, DateTime $start, int $interval): bool
    {
        $days = (int) $start->diff($cursor)->format('%a');

        return $cursor >= $start && $days % $interval === 0;
    }

    private static function matchesWeekly(DateTime $cursor, DateTime $start, array $rule, int $interval): bool
    {
        if ($cursor < $start) {
            return false;
        }

        $byDay = is_array($rule['by_day'] ?? null) && $rule['by_day'] !== []
            ? $rule['by_day']
            : [array_search((int) $start->format('N'), self::DAY_CODES, true) ?: 'MO'];

        $cursorIso = (int) $cursor->format('N');
        $wanted = array_map(static fn ($code) => self::DAY_CODES[strtoupper((string) $code)] ?? 0, $byDay);

        if (!in_array($cursorIso, $wanted, true)) {
            return false;
        }

        if ($interval <= 1) {
            return true;
        }

        // Compare ISO week numbers from the start of the series.
        $weeksApart = (int) floor(((int) $start->diff($cursor)->format('%a') + ((int) $start->format('N') - 1)) / 7);

        return $weeksApart % $interval === 0;
    }

    private static function matchesMonthly(DateTime $cursor, DateTime $start, array $rule, int $interval): bool
    {
        if ($cursor < $start) {
            return false;
        }

        $targetDay = $rule['by_month_day'] ?? (int) $start->format('j');
        $targetDay = max(1, min(31, (int) $targetDay));

        $daysInMonth = (int) $cursor->format('t');
        $effectiveDay = min($targetDay, $daysInMonth); // 31st in February -> 28th/29th.

        if ((int) $cursor->format('j') !== $effectiveDay) {
            return false;
        }

        if ($interval <= 1) {
            return true;
        }

        $monthsApart = ((int) $cursor->format('Y') - (int) $start->format('Y')) * 12
            + ((int) $cursor->format('n') - (int) $start->format('n'));

        return $monthsApart % $interval === 0;
    }

    private static function matchesYearly(DateTime $cursor, DateTime $start, int $interval): bool
    {
        if ($cursor < $start) {
            return false;
        }

        if ($cursor->format('m-d') !== $start->format('m-d')) {
            // Handle 29 Feb on non-leap years by firing on 28 Feb.
            if ($start->format('m-d') === '02-29' && $cursor->format('m-d') === '02-28' && !self::isLeap((int) $cursor->format('Y'))) {
                // fall through
            } else {
                return false;
            }
        }

        $yearsApart = (int) $cursor->format('Y') - (int) $start->format('Y');

        return $yearsApart % max(1, $interval) === 0;
    }

    /**
     * Create missing occurrence rows for a reminder up to $daysAhead.
     *
     * @return int number of rows inserted
     */
    public static function materialise(array $reminder, int $daysAhead = 30): int
    {
        $db = App::i()->db();
        $reminderId = (int) $reminder['id'];

        if (($reminder['status'] ?? 'active') !== 'active' || !empty($reminder['deleted_at'])) {
            return 0;
        }

        $from = now_utc();
        $to = date('Y-m-d H:i:s', time() + ($daysAhead * 86400));

        // Include the start time when it is still in the future or very recent
        // so a reminder created for "in 5 minutes" is never missed.
        $start = (string) $reminder['start_at'];

        if (strtotime($start) < strtotime($from)) {
            $from = date('Y-m-d H:i:s', strtotime($start));
        }

        $dates = self::expand($reminder, $from, $to);

        if ($dates === []) {
            return 0;
        }

        $existing = $db->all(
            'SELECT due_at FROM reminder_occurrences WHERE reminder_id = ? AND due_at BETWEEN ? AND ?',
            [$reminderId, $from, $to]
        );

        $known = [];
        foreach ($existing as $row) {
            $known[substr((string) $row['due_at'], 0, 19)] = true;
        }

        $sequence = (int) $db->value('SELECT COALESCE(MAX(sequence), 0) FROM reminder_occurrences WHERE reminder_id = ?', [$reminderId], 0);
        $inserted = 0;

        foreach ($dates as $dueAt) {
            if (isset($known[$dueAt])) {
                continue;
            }

            try {
                $db->insert('reminder_occurrences', [
                    'reminder_id'     => $reminderId,
                    'user_id'         => (int) $reminder['user_id'],
                    'due_at'          => $dueAt,
                    'original_due_at' => $dueAt,
                    'sequence'        => ++$sequence,
                    'status'          => 'pending',
                    'created_at'      => now_utc(),
                ]);
                $inserted++;
            } catch (\Throwable) {
                // Unique key collision — another run created it first.
            }
        }

        $db->update('reminders', ['materialised_until' => $to], 'id = :id', ['id' => $reminderId]);

        return $inserted;
    }

    /** Human-readable rule text for the UI, translated. */
    public static function describe(array $rule, string $lang = 'gu'): string
    {
        $freq = (string) ($rule['freq'] ?? 'none');

        if ($freq === 'none' || $freq === '') {
            return \App\Core\Lang::get('recurrence.none', [], $lang);
        }

        $interval = max(1, (int) ($rule['interval'] ?? 1));

        if ($freq === 'weekly' && !empty($rule['by_day'])) {
            $names = array_map(
                static fn ($code) => \App\Core\Lang::get('weekday.' . strtolower((string) $code), [], $lang),
                $rule['by_day']
            );

            return \App\Core\Lang::get('recurrence.weekly_on', ['days' => implode(', ', $names)], $lang);
        }

        if ($freq === 'monthly' && !empty($rule['by_month_day'])) {
            return \App\Core\Lang::get('recurrence.monthly_on', ['day' => (int) $rule['by_month_day']], $lang);
        }

        $key = $interval > 1 ? 'recurrence.every_n_' . $freq : 'recurrence.' . $freq;

        return \App\Core\Lang::get($key, ['n' => $interval], $lang);
    }

    private static function timezoneFor(array $reminder): DateTimeZone
    {
        $tz = $reminder['timezone'] ?? null;

        if (!is_string($tz) || $tz === '') {
            $tz = App::i()->config('app.timezone', 'Asia/Kolkata');
        }

        try {
            return new DateTimeZone($tz);
        } catch (\Throwable) {
            return new DateTimeZone('Asia/Kolkata');
        }
    }

    private static function toDate(?string $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Values from the database are UTC without an offset.
            return new DateTime($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isLeap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }
}
