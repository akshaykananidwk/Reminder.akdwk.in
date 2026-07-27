<?php

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Lang;
use App\Core\Logger;

/**
 * Reminder domain logic: creation from any source, occurrence lifecycle
 * (done / snooze / reschedule / cancel), payments linkage and streaks.
 */
class ReminderService
{
    /* ------------------------------------------------------------- Creating */

    /**
     * Create a reminder from a normalised AI/fallback item.
     *
     * @return array|null the created reminder row
     */
    public static function createFromParsed(array $item, array $user, string $source = 'whatsapp', ?string $sourceRef = null): ?array
    {
        $userId = (int) $user['id'];
        $tz = (string) ($user['timezone'] ?? 'Asia/Kolkata');

        if (!PlanService::withinReminderQuota($userId)) {
            Logger::info('Reminder quota exceeded', ['user_id' => $userId], 'reminders');

            return null;
        }

        $dueIso = $item['due_at'] ?? null;

        if ($dueIso === null) {
            return null;
        }

        $startUtc = self::isoToUtc($dueIso);

        if ($startUtc === null) {
            return null;
        }

        $recurrence = $item['recurrence'] ?? ['freq' => 'none'];

        $data = [
            'title'              => (string) $item['title'],
            'description'        => (string) ($item['description'] ?? ''),
            'type'               => (string) ($item['type'] ?? 'task'),
            'priority'           => (string) ($item['priority'] ?? 'normal'),
            'start_at'           => $startUtc,
            'all_day'            => !empty($item['all_day']) ? 1 : 0,
            'recurrence'         => $recurrence,
            'call_reminder'      => !empty($item['call_reminder']) ? 1 : 0,
            'advance_alerts'     => $item['advance_alerts_min'] ?? [],
            'snooze_default_min' => (int) ($item['snooze_default_min'] ?? 5),
            'amount'             => $item['amount'] ?? null,
            'currency'           => (string) ($item['currency'] ?? 'INR'),
            'person_name'        => $item['person']['name'] ?? null,
            'person_phone'       => $item['person']['phone'] ?? null,
            'location'           => $item['location'] ?? null,
            'source'             => $source,
            'source_ref'         => $sourceRef,
            'ai_confidence'      => $item['confidence'] ?? null,
            'tags'               => $item['tags'] ?? [],
            'timezone'           => $tz,
        ];

        return self::create($userId, $data);
    }

    /**
     * Create a reminder and materialise its first occurrences.
     */
    public static function create(int $userId, array $data): ?array
    {
        $db = App::i()->db();

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $startAt = (string) ($data['start_at'] ?? now_utc());
        $type = (string) ($data['type'] ?? 'task');

        $categoryId = $data['category_id'] ?? self::categoryForType($type);

        $reminderId = $db->insert('reminders', [
            'user_id'            => $userId,
            'short_code'         => self::generateShortCode($userId),
            'title'              => mb_substr($title, 0, 255),
            'description'        => mb_substr((string) ($data['description'] ?? ''), 0, 5000),
            'type'               => $type,
            'priority'           => (string) ($data['priority'] ?? 'normal'),
            'category_id'        => $categoryId,
            'contact_id'         => $data['contact_id'] ?? null,
            'start_at'           => $startAt,
            'end_at'             => $data['end_at'] ?? null,
            'all_day'            => !empty($data['all_day']) ? 1 : 0,
            'recurrence'         => json_encode($data['recurrence'] ?? ['freq' => 'none'], JSON_UNESCAPED_UNICODE),
            'recurrence_count'   => $data['recurrence_count'] ?? null,
            'call_reminder'      => array_key_exists('call_reminder', $data) ? (int) (bool) $data['call_reminder'] : 1,
            'advance_alerts'     => json_encode(array_values((array) ($data['advance_alerts'] ?? [])), JSON_UNESCAPED_UNICODE),
            'snooze_default_min' => max(1, (int) ($data['snooze_default_min'] ?? 5)),
            'amount'             => $data['amount'] ?? null,
            'currency'           => (string) ($data['currency'] ?? 'INR'),
            'person_name'        => $data['person_name'] ?? null,
            'person_phone'       => $data['person_phone'] ?? null,
            'location'           => $data['location'] ?? null,
            'color'              => $data['color'] ?? null,
            'source'             => (string) ($data['source'] ?? 'web'),
            'source_ref'         => $data['source_ref'] ?? null,
            'assigned_to'        => $data['assigned_to'] ?? null,
            'parent_id'          => $data['parent_id'] ?? null,
            'status'             => 'active',
            'ai_confidence'      => $data['ai_confidence'] ?? null,
            'created_at'         => now_utc(),
        ]);

        $reminder = $db->one('SELECT * FROM reminders WHERE id = ?', [$reminderId]);

        if ($reminder === null) {
            return null;
        }

        $reminder['timezone'] = $data['timezone'] ?? self::userTimezone($userId);

        self::syncTags($reminderId, $userId, (array) ($data['tags'] ?? []));

        RecurrenceService::materialise($reminder, 30);

        if ($type === 'payment' && !empty($data['amount'])) {
            self::createPaymentFor($reminder, (float) $data['amount'], (string) ($data['person_name'] ?? ''), $data['direction'] ?? 'payable');
        }

        if (!empty($data['assigned_to'])) {
            self::assign($reminderId, $userId, (int) $data['assigned_to']);
        }

        GoogleService::queuePush($userId, $reminderId);

        return $reminder;
    }

    /**
     * Update an existing reminder and rebuild its future occurrences.
     */
    public static function update(int $reminderId, int $userId, array $data): bool
    {
        $db = App::i()->db();

        $reminder = $db->one('SELECT * FROM reminders WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$reminderId, $userId]);

        if ($reminder === null) {
            return false;
        }

        $fields = [];

        foreach ([
            'title', 'description', 'type', 'priority', 'category_id', 'contact_id', 'start_at', 'end_at',
            'all_day', 'call_reminder', 'snooze_default_min', 'amount', 'currency', 'person_name',
            'person_phone', 'location', 'color', 'assigned_to', 'status', 'recurrence_count',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $data[$field];
            }
        }

        if (array_key_exists('recurrence', $data)) {
            $fields['recurrence'] = json_encode($data['recurrence'], JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('advance_alerts', $data)) {
            $fields['advance_alerts'] = json_encode(array_values((array) $data['advance_alerts']), JSON_UNESCAPED_UNICODE);
        }

        if ($fields === []) {
            return true;
        }

        $fields['updated_at'] = now_utc();
        $db->update('reminders', $fields, 'id = :id AND user_id = :uid', ['id' => $reminderId, 'uid' => $userId]);

        if (array_key_exists('tags', $data)) {
            self::syncTags($reminderId, $userId, (array) $data['tags']);
        }

        $timeChanged = array_key_exists('start_at', $data) || array_key_exists('recurrence', $data) || array_key_exists('end_at', $data);

        if ($timeChanged) {
            // Drop untouched future occurrences and rebuild from the new rule.
            $db->query(
                'DELETE FROM reminder_occurrences WHERE reminder_id = ? AND status IN ("pending","notified") AND due_at > ?',
                [$reminderId, now_utc()]
            );

            $fresh = $db->one('SELECT * FROM reminders WHERE id = ?', [$reminderId]);

            if ($fresh !== null) {
                $fresh['timezone'] = self::userTimezone($userId);
                RecurrenceService::materialise($fresh, 30);
            }
        }

        GoogleService::queuePush($userId, $reminderId);

        return true;
    }

    public static function softDelete(int $reminderId, int $userId): bool
    {
        $db = App::i()->db();

        $updated = $db->update('reminders', [
            'deleted_at' => now_utc(),
            'status'     => 'cancelled',
        ], 'id = :id AND user_id = :uid AND deleted_at IS NULL', ['id' => $reminderId, 'uid' => $userId]);

        if ($updated > 0) {
            $db->query(
                'UPDATE reminder_occurrences SET status = "cancelled" WHERE reminder_id = ? AND status IN ("pending","notified","snoozed")',
                [$reminderId]
            );
            GoogleService::queueDelete($userId, $reminderId);
        }

        return $updated > 0;
    }

    public static function restore(int $reminderId, int $userId): bool
    {
        return App::i()->db()->update('reminders', [
            'deleted_at' => null,
            'status'     => 'active',
        ], 'id = :id AND user_id = :uid', ['id' => $reminderId, 'uid' => $userId]) > 0;
    }

    /* ------------------------------------------------- Occurrence lifecycle */

    /**
     * Mark an occurrence done. Idempotent: repeating the call is harmless,
     * which matters because the same "Done" can arrive from several devices.
     */
    public static function complete(int $occurrenceId, int $userId, string $via = 'app', ?string $note = null, ?int $deviceId = null): bool
    {
        $db = App::i()->db();

        $occurrence = $db->one(
            'SELECT * FROM reminder_occurrences WHERE id = ? AND user_id = ?',
            [$occurrenceId, $userId]
        );

        if ($occurrence === null) {
            return false;
        }

        if ($occurrence['status'] === 'done') {
            return true;
        }

        $db->update('reminder_occurrences', [
            'status'   => 'done',
            'done_at'  => now_utc(),
            'done_via' => $via,
            'done_by'  => $userId,
            'note'     => $note === null ? null : mb_substr($note, 0, 255),
        ], 'id = :id', ['id' => $occurrenceId]);

        self::recordResponse($occurrenceId, $userId, 'done', $via, [], $deviceId);

        // A non-recurring reminder is finished once its only occurrence is done.
        $reminder = $db->one('SELECT * FROM reminders WHERE id = ?', [(int) $occurrence['reminder_id']]);

        if ($reminder !== null) {
            $rule = json_field($reminder['recurrence'], ['freq' => 'none']);

            if (($rule['freq'] ?? 'none') === 'none') {
                $db->update('reminders', ['status' => 'completed'], 'id = :id', ['id' => (int) $reminder['id']]);
            }

            self::updateStreak($userId);
            self::notifyDone($reminder, $userId);
            GoogleService::queuePush($userId, (int) $reminder['id']);
        }

        // Clear the same reminder on every other device.
        FcmService::sendToUser($userId, [
            'type'          => 'occurrence_resolved',
            'occurrence_id' => (string) $occurrenceId,
            'action'        => 'done',
        ]);

        return true;
    }

    /**
     * Snooze: the current occurrence is closed and a new one is created at
     * now + minutes. Snooze count is carried over and capped.
     */
    public static function snooze(int $occurrenceId, int $userId, ?int $minutes = null, string $via = 'app'): array
    {
        $db = App::i()->db();

        $occurrence = $db->one('SELECT * FROM reminder_occurrences WHERE id = ? AND user_id = ?', [$occurrenceId, $userId]);

        if ($occurrence === null) {
            return ['ok' => false, 'message' => 'Occurrence not found'];
        }

        $reminder = $db->one('SELECT * FROM reminders WHERE id = ?', [(int) $occurrence['reminder_id']]);

        if ($reminder === null) {
            return ['ok' => false, 'message' => 'Reminder not found'];
        }

        $settings = self::userSettings($userId);
        $minutes = $minutes !== null && $minutes > 0
            ? min(10080, $minutes)
            : (int) ($reminder['snooze_default_min'] ?: $settings['default_snooze_min'] ?? 5);

        $snoozeCount = (int) $occurrence['snooze_count'] + 1;
        $maxSnoozes = max(1, (int) ($settings['max_snoozes'] ?? 5));

        if ($snoozeCount > $maxSnoozes) {
            return [
                'ok'      => false,
                'message' => Lang::get('reminder.max_snoozes', ['n' => $maxSnoozes]),
                'code'    => 'MAX_SNOOZES',
            ];
        }

        $newDue = date('Y-m-d H:i:s', time() + ($minutes * 60));

        $db->update('reminder_occurrences', [
            'status' => 'snoozed',
        ], 'id = :id', ['id' => $occurrenceId]);

        try {
            $newId = $db->insert('reminder_occurrences', [
                'reminder_id'     => (int) $reminder['id'],
                'user_id'         => $userId,
                'due_at'          => $newDue,
                'original_due_at' => $occurrence['original_due_at'] ?: $occurrence['due_at'],
                'sequence'        => (int) $occurrence['sequence'],
                'status'          => 'pending',
                'snooze_count'    => $snoozeCount,
                'created_at'      => now_utc(),
            ]);
        } catch (\Throwable) {
            // Same minute collision — reuse the existing row.
            $existing = $db->one('SELECT id FROM reminder_occurrences WHERE reminder_id = ? AND due_at = ?', [(int) $reminder['id'], $newDue]);
            $newId = (int) ($existing['id'] ?? 0);
        }

        self::recordResponse($occurrenceId, $userId, 'snooze', $via, ['minutes' => $minutes]);

        $user = $db->one('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user !== null && (int) (self::userSettings($userId)['wa_notify_due'] ?? 1) === 1 && $via === 'whatsapp') {
            WhatsAppService::queueTemplate('reminder_snoozed', $user, [
                'code'    => $reminder['short_code'],
                'minutes' => $minutes,
                'time'    => self::formatWhen($newDue, (string) $user['language'], (string) $user['timezone']),
            ], 3);
        }

        FcmService::sendToUser($userId, [
            'type'          => 'occurrence_resolved',
            'occurrence_id' => (string) $occurrenceId,
            'action'        => 'snooze',
        ]);

        return ['ok' => true, 'occurrence_id' => $newId, 'due_at' => $newDue, 'minutes' => $minutes];
    }

    public static function reschedule(int $occurrenceId, int $userId, string $newDueUtc, string $via = 'app'): bool
    {
        $db = App::i()->db();

        $occurrence = $db->one('SELECT * FROM reminder_occurrences WHERE id = ? AND user_id = ?', [$occurrenceId, $userId]);

        if ($occurrence === null) {
            return false;
        }

        $db->update('reminder_occurrences', [
            'due_at'          => $newDueUtc,
            'status'          => 'pending',
            'attempt_count'   => 0,
            'next_attempt_at' => null,
            'notified_at'     => null,
        ], 'id = :id', ['id' => $occurrenceId]);

        self::recordResponse($occurrenceId, $userId, 'reschedule', $via, ['due_at' => $newDueUtc]);

        FcmService::sendToUser($userId, ['type' => 'sync', 'reason' => 'reschedule']);

        return true;
    }

    public static function cancelOccurrence(int $occurrenceId, int $userId, string $via = 'app'): bool
    {
        $updated = App::i()->db()->update('reminder_occurrences', [
            'status' => 'cancelled',
        ], 'id = :id AND user_id = :uid', ['id' => $occurrenceId, 'uid' => $userId]);

        if ($updated > 0) {
            self::recordResponse($occurrenceId, $userId, 'cancel', $via);
        }

        return $updated > 0;
    }

    public static function cancelReminder(int $reminderId, int $userId): bool
    {
        $db = App::i()->db();

        $updated = $db->update('reminders', ['status' => 'cancelled'], 'id = :id AND user_id = :uid', [
            'id' => $reminderId, 'uid' => $userId,
        ]);

        if ($updated > 0) {
            $db->query(
                'UPDATE reminder_occurrences SET status = "cancelled" WHERE reminder_id = ? AND status IN ("pending","notified","snoozed")',
                [$reminderId]
            );
            GoogleService::queueDelete($userId, $reminderId);
        }

        return $updated > 0;
    }

    /* ------------------------------------------------------------- Queries */

    public static function findByShortCode(int $userId, string $code): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');

        if ($code === '') {
            return null;
        }

        return App::i()->db()->one(
            'SELECT * FROM reminders WHERE user_id = ? AND short_code = ? AND deleted_at IS NULL',
            [$userId, $code]
        );
    }

    /** The occurrence a user means when they say "DONE A12". */
    public static function activeOccurrenceFor(int $reminderId): ?array
    {
        $db = App::i()->db();

        return $db->one(
            'SELECT * FROM reminder_occurrences
              WHERE reminder_id = ? AND status IN ("pending","notified","snoozed","missed")
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, due_at, UTC_TIMESTAMP())) ASC
              LIMIT 1',
            [$reminderId]
        );
    }

    /**
     * Today's occurrences in the user's local day.
     */
    public static function todayOccurrences(int $userId, ?string $tz = null, array $statuses = ['pending', 'notified', 'snoozed', 'missed', 'done']): array
    {
        $tz = $tz ?: self::userTimezone($userId);
        [$startUtc, $endUtc] = self::localDayBounds($tz);

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return App::i()->db()->all(
            'SELECT o.*, r.title, r.short_code, r.type, r.priority, r.amount, r.currency, r.person_name, r.color, r.category_id
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ?
                AND r.deleted_at IS NULL
                AND o.due_at BETWEEN ? AND ?
                AND o.status IN (' . $placeholders . ')
              ORDER BY o.due_at ASC',
            array_merge([$userId, $startUtc, $endUtc], $statuses)
        );
    }

    public static function upcoming(int $userId, int $limit = 20): array
    {
        return App::i()->db()->all(
            'SELECT o.*, r.title, r.short_code, r.type, r.priority, r.amount, r.currency, r.color
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL
                AND o.status IN ("pending","notified","snoozed")
                AND o.due_at >= ?
              ORDER BY o.due_at ASC
              LIMIT ' . (int) $limit,
            [$userId, now_utc()]
        );
    }

    public static function overdue(int $userId, int $limit = 50): array
    {
        return App::i()->db()->all(
            'SELECT o.*, r.title, r.short_code, r.type, r.priority, r.amount, r.currency
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL
                AND o.status IN ("pending","notified","missed","snoozed")
                AND o.due_at < ?
              ORDER BY o.due_at DESC
              LIMIT ' . (int) $limit,
            [$userId, now_utc()]
        );
    }

    /**
     * Dashboard counters.
     */
    public static function stats(int $userId, ?string $tz = null): array
    {
        $db = App::i()->db();
        $tz = $tz ?: self::userTimezone($userId);
        [$dayStart, $dayEnd] = self::localDayBounds($tz);
        [$monthStart, $monthEnd] = self::localMonthBounds($tz);

        $todayTotal = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ? AND o.status <> "cancelled"',
            [$userId, $dayStart, $dayEnd], 0
        );

        $doneToday = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.status = "done" AND o.done_at BETWEEN ? AND ?',
            [$userId, $dayStart, $dayEnd], 0
        );

        $pending = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.status IN ("pending","notified","snoozed") AND o.due_at >= ?',
            [$userId, now_utc()], 0
        );

        $overdue = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.status IN ("pending","notified","missed","snoozed") AND o.due_at < ?',
            [$userId, now_utc()], 0
        );

        $paymentsDue = (float) $db->value(
            'SELECT COALESCE(SUM(amount - paid_amount), 0) FROM payments
              WHERE user_id = ? AND deleted_at IS NULL AND status IN ("unpaid","partial") AND due_date BETWEEN ? AND ?',
            [$userId, substr($monthStart, 0, 10), substr($monthEnd, 0, 10)], 0.0
        );

        $user = $db->one('SELECT streak_days, best_streak FROM users WHERE id = ?', [$userId]);

        return [
            'today_total'  => $todayTotal,
            'done_today'   => $doneToday,
            'pending'      => $pending,
            'overdue'      => $overdue,
            'payments_due' => $paymentsDue,
            'streak'       => (int) ($user['streak_days'] ?? 0),
            'best_streak'  => (int) ($user['best_streak'] ?? 0),
            'progress'     => $todayTotal > 0 ? (int) round(($doneToday / $todayTotal) * 100) : 0,
            'next'         => self::upcoming($userId, 1)[0] ?? null,
        ];
    }

    /* --------------------------------------------------------- Duplicates */

    /**
     * Guard against the same message being parsed twice into near-identical
     * reminders (common when a user re-sends on a flaky connection).
     */
    public static function findDuplicate(int $userId, string $title, string $startUtc, int $windowMinutes = 60): ?array
    {
        $from = date('Y-m-d H:i:s', strtotime($startUtc) - ($windowMinutes * 60));
        $to = date('Y-m-d H:i:s', strtotime($startUtc) + ($windowMinutes * 60));

        $candidates = App::i()->db()->all(
            'SELECT * FROM reminders
              WHERE user_id = ? AND deleted_at IS NULL AND status = "active" AND start_at BETWEEN ? AND ?
              LIMIT 20',
            [$userId, $from, $to]
        );

        $needle = self::normaliseForCompare($title);

        foreach ($candidates as $candidate) {
            if (self::normaliseForCompare((string) $candidate['title']) === $needle) {
                return $candidate;
            }

            similar_text($needle, self::normaliseForCompare((string) $candidate['title']), $percent);

            if ($percent > 88) {
                return $candidate;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------- Formatting */

    /**
     * Localised "tomorrow, 28 July 10:00 AM" used in every WhatsApp reply.
     */
    public static function formatWhen(string $utc, string $lang = 'gu', string $tz = 'Asia/Kolkata'): string
    {
        try {
            $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz));
            $now = new \DateTime('now', new \DateTimeZone($tz));
        } catch (\Throwable) {
            return $utc;
        }

        $dayDiff = (int) $now->diff($dt)->format('%r%a');
        $sameDay = $dt->format('Y-m-d') === $now->format('Y-m-d');

        $timePart = $dt->format('g:i A');
        $months = Lang::get('months.' . strtolower($dt->format('M')), [], $lang);
        $datePart = $dt->format('j') . ' ' . ($months !== '' ? $months : $dt->format('F'));

        if ($sameDay) {
            return Lang::get('time.today_at', ['time' => $timePart], $lang);
        }

        if ($dayDiff >= 0 && $dayDiff <= 1 && $dt->format('Y-m-d') === (clone $now)->modify('+1 day')->format('Y-m-d')) {
            return Lang::get('time.tomorrow_at', ['date' => $datePart, 'time' => $timePart], $lang);
        }

        return Lang::get('time.on_date_at', ['date' => $datePart, 'time' => $timePart], $lang);
    }

    /* ------------------------------------------------------------ Internals */

    public static function generateShortCode(int $userId): string
    {
        $db = App::i()->db();

        for ($i = 0; $i < 25; $i++) {
            $code = Crypto::shortCode($i < 15 ? 3 : 4);

            $exists = $db->value('SELECT id FROM reminders WHERE user_id = ? AND short_code = ?', [$userId, $code]);

            if ($exists === null) {
                return $code;
            }
        }

        return Crypto::shortCode(6);
    }

    private static function syncTags(int $reminderId, int $userId, array $tags): void
    {
        $db = App::i()->db();
        $db->delete('reminder_tags', 'reminder_id = ?', [$reminderId]);

        foreach (array_slice(array_unique(array_filter(array_map('trim', $tags))), 0, 10) as $name) {
            $existing = $db->one('SELECT id FROM tags WHERE user_id = ? AND name = ?', [$userId, $name]);

            $tagId = $existing !== null
                ? (int) $existing['id']
                : $db->insert('tags', ['user_id' => $userId, 'name' => mb_substr($name, 0, 60), 'created_at' => now_utc()]);

            try {
                $db->insert('reminder_tags', ['reminder_id' => $reminderId, 'tag_id' => $tagId]);
            } catch (\Throwable) {
                // Already linked.
            }
        }
    }

    private static function createPaymentFor(array $reminder, float $amount, string $partyName, string $direction = 'payable'): void
    {
        try {
            App::i()->db()->insert('payments', [
                'user_id'     => (int) $reminder['user_id'],
                'reminder_id' => (int) $reminder['id'],
                'party_name'  => $partyName !== '' ? mb_substr($partyName, 0, 160) : mb_substr((string) $reminder['title'], 0, 160),
                'direction'   => in_array($direction, ['payable', 'receivable'], true) ? $direction : 'payable',
                'amount'      => $amount,
                'currency'    => (string) $reminder['currency'],
                'due_date'    => substr((string) $reminder['start_at'], 0, 10),
                'status'      => 'unpaid',
                'created_at'  => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Failed to create linked payment', ['error' => $e->getMessage()], 'reminders');
        }
    }

    public static function assign(int $reminderId, int $ownerId, int $assigneeId): bool
    {
        if (!PlanService::canAssignStaff($ownerId)) {
            return false;
        }

        $db = App::i()->db();
        $assignee = $db->one('SELECT * FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL', [$assigneeId]);
        $reminder = $db->one('SELECT * FROM reminders WHERE id = ? AND user_id = ?', [$reminderId, $ownerId]);
        $owner = $db->one('SELECT * FROM users WHERE id = ?', [$ownerId]);

        if ($assignee === null || $reminder === null || $owner === null) {
            return false;
        }

        $db->insert('assignments', [
            'reminder_id'         => $reminderId,
            'assigned_by'         => $ownerId,
            'assigned_to_user_id' => $assigneeId,
            'status'              => 'pending',
            'created_at'          => now_utc(),
        ]);

        $db->update('reminders', ['assigned_to' => $assigneeId], 'id = :id', ['id' => $reminderId]);

        WhatsAppService::queueTemplate('assignment_received', $assignee, [
            'code'  => $reminder['short_code'],
            'title' => $reminder['title'],
            'time'  => self::formatWhen((string) $reminder['start_at'], (string) $assignee['language'], (string) $assignee['timezone']),
            'name'  => $owner['name'],
        ], 4);

        return true;
    }

    private static function notifyDone(array $reminder, int $userId): void
    {
        $settings = self::userSettings($userId);

        if ((int) ($settings['wa_notify_done'] ?? 0) !== 1) {
            return;
        }

        $user = App::i()->db()->one('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            return;
        }

        WhatsAppService::queueTemplate('reminder_done', $user, [
            'code'   => $reminder['short_code'],
            'title'  => $reminder['title'],
            'streak' => (int) ($user['streak_days'] ?? 0),
        ], 6);
    }

    public static function recordResponse(int $occurrenceId, int $userId, string $action, string $source, array $payload = [], ?int $deviceId = null): void
    {
        try {
            App::i()->db()->insert('user_responses', [
                'occurrence_id' => $occurrenceId,
                'user_id'       => $userId,
                'action'        => $action,
                'source'        => $source,
                'payload'       => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'device_id'     => $deviceId,
                'created_at'    => now_utc(),
            ]);
        } catch (\Throwable) {
            // Timeline entry is best-effort.
        }
    }

    /**
     * Recalculate the "all of today's reminders completed" streak.
     */
    public static function updateStreak(int $userId): void
    {
        $db = App::i()->db();
        $tz = self::userTimezone($userId);
        [$dayStart, $dayEnd] = self::localDayBounds($tz);

        $total = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = ? AND due_at BETWEEN ? AND ? AND status <> "cancelled"',
            [$userId, $dayStart, $dayEnd], 0
        );

        $done = (int) $db->value(
            'SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = ? AND due_at BETWEEN ? AND ? AND status = "done"',
            [$userId, $dayStart, $dayEnd], 0
        );

        $localDate = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
        $allDone = $total > 0 && $done >= $total;

        $db->upsert('streaks', [
            'user_id'     => $userId,
            'streak_date' => $localDate,
            'all_done'    => $allDone ? 1 : 0,
            'done_count'  => $done,
            'total_count' => $total,
            'score'       => $total > 0 ? round(($done / $total) * 100, 2) : 0,
            'created_at'  => now_utc(),
        ], ['all_done', 'done_count', 'total_count', 'score']);

        if (!$allDone) {
            return;
        }

        $rows = $db->all(
            'SELECT streak_date, all_done FROM streaks WHERE user_id = ? AND streak_date <= ? ORDER BY streak_date DESC LIMIT 400',
            [$userId, $localDate]
        );

        $streak = 0;
        $expected = new \DateTime($localDate);

        foreach ($rows as $row) {
            if ((string) $row['streak_date'] !== $expected->format('Y-m-d') || (int) $row['all_done'] !== 1) {
                break;
            }

            $streak++;
            $expected->modify('-1 day');
        }

        $best = (int) $db->value('SELECT best_streak FROM users WHERE id = ?', [$userId], 0);

        $db->update('users', [
            'streak_days' => $streak,
            'best_streak' => max($best, $streak),
        ], 'id = :id', ['id' => $userId]);
    }

    /* ----------------------------------------------------------- Utilities */

    public static function userTimezone(int $userId): string
    {
        static $cache = [];

        if (!isset($cache[$userId])) {
            $tz = App::i()->db()->value('SELECT timezone FROM users WHERE id = ?', [$userId], 'Asia/Kolkata');
            $cache[$userId] = is_string($tz) && $tz !== '' ? $tz : 'Asia/Kolkata';
        }

        return $cache[$userId];
    }

    public static function userSettings(int $userId): array
    {
        static $cache = [];

        if (!isset($cache[$userId])) {
            $row = App::i()->db()->one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);

            if ($row === null) {
                self::ensureSettings($userId);
                $row = App::i()->db()->one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]) ?? [];
            }

            $cache[$userId] = $row;
        }

        return $cache[$userId];
    }

    public static function ensureSettings(int $userId): void
    {
        try {
            App::i()->db()->insert('user_settings', ['user_id' => $userId, 'created_at' => now_utc()]);
        } catch (\Throwable) {
            // Row already exists.
        }
    }

    /** @return array{0: string, 1: string} UTC bounds of the user's local day */
    public static function localDayBounds(string $tz, ?string $localDate = null): array
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('Asia/Kolkata');
        }

        $localDate ??= (new \DateTime('now', $zone))->format('Y-m-d');

        $start = new \DateTime($localDate . ' 00:00:00', $zone);
        $end = new \DateTime($localDate . ' 23:59:59', $zone);

        $start->setTimezone(new \DateTimeZone('UTC'));
        $end->setTimezone(new \DateTimeZone('UTC'));

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** @return array{0: string, 1: string} */
    public static function localMonthBounds(string $tz): array
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('Asia/Kolkata');
        }

        $now = new \DateTime('now', $zone);
        $start = new \DateTime($now->format('Y-m-01') . ' 00:00:00', $zone);
        $end = new \DateTime($now->format('Y-m-t') . ' 23:59:59', $zone);

        $start->setTimezone(new \DateTimeZone('UTC'));
        $end->setTimezone(new \DateTimeZone('UTC'));

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    public static function isoToUtc(string $iso): ?string
    {
        $ts = strtotime($iso);

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private static function categoryForType(string $type): ?int
    {
        $map = [
            'payment' => 'payment', 'bill' => 'bill', 'meeting' => 'meeting',
            'medicine' => 'health', 'birthday' => 'birthday', 'call' => 'work',
            'task' => 'work', 'note' => 'other', 'other' => 'other',
        ];

        $code = $map[$type] ?? 'other';

        $row = App::i()->db()->one('SELECT id FROM categories WHERE code = ? AND user_id IS NULL LIMIT 1', [$code]);

        return $row === null ? null : (int) $row['id'];
    }

    private static function normaliseForCompare(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
