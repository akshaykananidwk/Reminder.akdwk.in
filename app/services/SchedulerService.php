<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * The heart of delivery (Section 11).
 *
 *   T-24h/1h/30m/10m  -> silent advance notification
 *   T-0               -> call attempt #1 (FCM full-screen + TTS)
 *   +gap              -> attempt #2, then #3 with a WhatsApp fallback
 *   no response       -> MISSED
 */
class SchedulerService
{
    /**
     * One dispatcher tick.
     *
     * @return array{due: int, escalated: int, advance: int, missed: int}
     */
    public static function tick(int $limit = 300): array
    {
        return [
            'due'       => self::dispatchDue($limit),
            'escalated' => self::escalate($limit),
            'advance'   => self::sendAdvanceAlerts($limit),
            'missed'    => self::markMissed($limit),
        ];
    }

    /* ------------------------------------------------------------- Due now */

    private static function dispatchDue(int $limit): int
    {
        $db = App::i()->db();

        // A small look-back window covers a cron run that started late.
        $rows = $db->all(
            'SELECT o.*, r.title, r.description, r.short_code, r.type, r.priority, r.amount, r.currency,
                    r.person_name, r.call_reminder, r.snooze_default_min, r.call_attempts, r.assigned_to
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
               JOIN users u ON u.id = o.user_id
              WHERE o.status = "pending"
                AND o.due_at <= ?
                AND o.due_at > ?
                AND r.status = "active"
                AND r.deleted_at IS NULL
                AND u.is_active = 1
                AND u.reminders_paused = 0
                AND u.deleted_at IS NULL
              ORDER BY o.due_at ASC
              LIMIT ' . (int) $limit,
            [now_utc(), date('Y-m-d H:i:s', time() - 3600)]
        );

        $count = 0;

        foreach ($rows as $row) {
            try {
                self::deliver($row, 1);
                $count++;
            } catch (\Throwable $e) {
                Logger::error('Dispatch failed', ['occurrence_id' => $row['id'], 'error' => $e->getMessage()], 'dispatcher');
            }
        }

        return $count;
    }

    /**
     * Deliver one call attempt for an occurrence.
     */
    public static function deliver(array $occurrence, int $attemptNo): void
    {
        $db = App::i()->db();
        $userId = (int) $occurrence['user_id'];
        $user = $db->one('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            return;
        }

        $settings = ReminderService::userSettings($userId);
        $priority = (string) ($occurrence['priority'] ?? 'normal');

        // Do-Not-Disturb: urgent always rings, everything else is deferred.
        if ($priority !== 'urgent' && self::inDndWindow($user, $settings)) {
            $resumeAt = self::dndEndUtc($user, $settings);

            $db->update('reminder_occurrences', ['due_at' => $resumeAt], 'id = :id', ['id' => (int) $occurrence['id']]);
            Logger::info('Deferred for DND', ['occurrence_id' => $occurrence['id'], 'until' => $resumeAt], 'dispatcher');

            return;
        }

        if (self::inHolidayMode($settings) && $priority !== 'urgent') {
            return;
        }

        $reminder = $db->one('SELECT * FROM reminders WHERE id = ?', [(int) $occurrence['reminder_id']]);

        if ($reminder === null) {
            return;
        }

        $deliveryId = $db->insert('deliveries', [
            'occurrence_id' => (int) $occurrence['id'],
            'user_id'       => $userId,
            'kind'          => 'call',
            'attempt_no'    => $attemptNo,
            'status'        => 'queued',
            'created_at'    => now_utc(),
        ]);

        $maxAttempts = (int) ($reminder['call_attempts'] ?: $settings['call_attempts'] ?? 3);
        $gapMinutes = max(1, (int) ($settings['call_gap_minutes'] ?? 2));

        $anyChannelWorked = false;

        // --- Channel 1: FCM to every device (the phone call experience) -----
        if ((int) $reminder['call_reminder'] === 1 && FcmService::isConfigured()) {
            $payload = FcmService::callPayload($occurrence, $reminder, $user, $attemptNo);
            $result = FcmService::sendToUser($userId, $payload, ['ttl' => 600]);

            $db->insert('delivery_attempts', [
                'delivery_id' => $deliveryId,
                'channel'     => 'fcm',
                'target'      => $result['tokens'] . ' device(s)',
                'attempt_no'  => $attemptNo,
                'sent_at'     => now_utc(),
                'result'      => $result['sent'] > 0 ? 'success' : 'failed',
                'response'    => json_encode($result),
                'created_at'  => now_utc(),
            ]);

            $anyChannelWorked = $result['sent'] > 0;
        }

        // --- Channel 2: WhatsApp text (fallback / final attempt) ------------
        $whatsappNow = (int) ($settings['whatsapp_fallback'] ?? 1) === 1
            && ($attemptNo >= $maxAttempts || !$anyChannelWorked);

        if ($whatsappNow) {
            $queueId = WhatsAppService::queueTemplate('reminder_due', $user, [
                'code'  => (string) $reminder['short_code'],
                'title' => (string) $reminder['title'],
                'time'  => ReminderService::formatWhen((string) $occurrence['due_at'], (string) $user['language'], (string) $user['timezone']),
            ], 2);

            $db->insert('delivery_attempts', [
                'delivery_id' => $deliveryId,
                'channel'     => 'whatsapp',
                'target'      => (string) $user['phone'],
                'attempt_no'  => $attemptNo,
                'sent_at'     => now_utc(),
                'result'      => $queueId > 0 ? 'success' : 'failed',
                'response'    => 'queue#' . $queueId,
                'created_at'  => now_utc(),
            ]);

            $anyChannelWorked = $anyChannelWorked || $queueId > 0;
        }

        // --- Channel 3: in-app notification (always) ------------------------
        self::createNotification($userId, $reminder, $occurrence);

        // --- Assignment: ring the assignee too -----------------------------
        if (!empty($reminder['assigned_to'])) {
            $assignee = $db->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $reminder['assigned_to']]);

            if ($assignee !== null) {
                FcmService::sendToUser((int) $assignee['id'], FcmService::callPayload($occurrence, $reminder, $assignee, $attemptNo), ['ttl' => 600]);
            }
        }

        $db->update('deliveries', ['status' => $anyChannelWorked ? 'sent' : 'failed'], 'id = :id', ['id' => $deliveryId]);

        $nextAttemptAt = $attemptNo < $maxAttempts
            ? date('Y-m-d H:i:s', time() + ($gapMinutes * 60))
            : null;

        $db->update('reminder_occurrences', [
            'status'          => 'notified',
            'attempt_count'   => $attemptNo,
            'notified_at'     => $occurrence['notified_at'] ?: now_utc(),
            'next_attempt_at' => $nextAttemptAt,
        ], 'id = :id', ['id' => (int) $occurrence['id']]);

        UserWebhookService::dispatch($userId, 'reminder.due', [
            'occurrence_id' => (int) $occurrence['id'],
            'reminder_id'   => (int) $reminder['id'],
            'short_code'    => (string) $reminder['short_code'],
            'title'         => (string) $reminder['title'],
            'due_at'        => (string) $occurrence['due_at'],
        ]);
    }

    /* ------------------------------------------------------------ Escalation */

    private static function escalate(int $limit): int
    {
        $db = App::i()->db();

        $rows = $db->all(
            'SELECT o.*, r.title, r.short_code, r.priority, r.call_reminder, r.call_attempts, r.snooze_default_min
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
               JOIN users u ON u.id = o.user_id
              WHERE o.status = "notified"
                AND o.next_attempt_at IS NOT NULL
                AND o.next_attempt_at <= ?
                AND r.deleted_at IS NULL
                AND u.is_active = 1
                AND u.reminders_paused = 0
              ORDER BY o.next_attempt_at ASC
              LIMIT ' . (int) $limit,
            [now_utc()]
        );

        $count = 0;

        foreach ($rows as $row) {
            try {
                self::deliver($row, (int) $row['attempt_count'] + 1);
                $count++;
            } catch (\Throwable $e) {
                Logger::error('Escalation failed', ['occurrence_id' => $row['id'], 'error' => $e->getMessage()], 'dispatcher');
            }
        }

        return $count;
    }

    /* -------------------------------------------------------- Advance alerts */

    private static function sendAdvanceAlerts(int $limit): int
    {
        $db = App::i()->db();

        $rows = $db->all(
            'SELECT o.*, r.title, r.short_code, r.advance_alerts, r.type, r.amount, r.currency
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
               JOIN users u ON u.id = o.user_id
              WHERE o.status = "pending"
                AND r.advance_alerts IS NOT NULL
                AND r.advance_alerts <> "[]"
                AND o.due_at > ?
                AND o.due_at <= ?
                AND r.deleted_at IS NULL
                AND u.is_active = 1
                AND u.reminders_paused = 0
              LIMIT ' . (int) $limit,
            [now_utc(), date('Y-m-d H:i:s', time() + 86400 + 300)]
        );

        $count = 0;

        foreach ($rows as $row) {
            $alerts = json_field($row['advance_alerts'], []);
            $sentAlready = json_field($row['advance_sent'], []);
            $dueTs = strtotime((string) $row['due_at'] . ' UTC');
            $changed = false;

            foreach ($alerts as $minutesBefore) {
                $minutesBefore = (int) $minutesBefore;

                if ($minutesBefore <= 0 || in_array($minutesBefore, array_map('intval', $sentAlready), true)) {
                    continue;
                }

                $fireAt = $dueTs - ($minutesBefore * 60);

                // Fire inside a 90-second window so a one-minute cron cannot skip it.
                if ($fireAt > time() || $fireAt < time() - 90) {
                    continue;
                }

                FcmService::sendToUser((int) $row['user_id'], [
                    'type'           => 'advance',
                    'occurrence_id'  => (string) $row['id'],
                    'title'          => (string) $row['title'],
                    'short_code'     => (string) $row['short_code'],
                    'minutes_before' => (string) $minutesBefore,
                    'due_at'         => (string) $row['due_at'],
                ], ['ttl' => 300]);

                $sentAlready[] = $minutesBefore;
                $changed = true;
                $count++;
            }

            if ($changed) {
                $db->update('reminder_occurrences', [
                    'advance_sent' => json_encode(array_values(array_unique(array_map('intval', $sentAlready)))),
                ], 'id = :id', ['id' => (int) $row['id']]);
            }
        }

        return $count;
    }

    /* ------------------------------------------------------------- Missed */

    private static function markMissed(int $limit): int
    {
        $db = App::i()->db();

        // Notified, all attempts exhausted, nothing scheduled, and the due time
        // is far enough in the past that a late answer is unlikely.
        $rows = $db->all(
            'SELECT o.*, r.short_code, r.title
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.status = "notified"
                AND o.next_attempt_at IS NULL
                AND o.due_at < ?
                AND r.deleted_at IS NULL
              LIMIT ' . (int) $limit,
            [date('Y-m-d H:i:s', time() - 600)]
        );

        $count = 0;

        foreach ($rows as $row) {
            $db->update('reminder_occurrences', ['status' => 'missed'], 'id = :id', ['id' => (int) $row['id']]);

            $user = $db->one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
            $settings = ReminderService::userSettings((int) $row['user_id']);

            if ($user !== null && (int) ($settings['wa_notify_missed'] ?? 1) === 1) {
                WhatsAppService::queueTemplate('reminder_missed', $user, [
                    'code'  => (string) $row['short_code'],
                    'title' => (string) $row['title'],
                    'time'  => ReminderService::formatWhen((string) $row['due_at'], (string) $user['language'], (string) $user['timezone']),
                ], 5);
            }

            UserWebhookService::dispatch((int) $row['user_id'], 'reminder.missed', [
                'occurrence_id' => (int) $row['id'],
                'short_code'    => (string) $row['short_code'],
            ]);

            $count++;
        }

        return $count;
    }

    /* ------------------------------------------------------------- Helpers */

    public static function inDndWindow(array $user, array $settings): bool
    {
        if ((int) ($settings['dnd_enabled'] ?? 0) !== 1) {
            return false;
        }

        $start = (string) ($settings['dnd_start'] ?? '');
        $end = (string) ($settings['dnd_end'] ?? '');

        if ($start === '' || $end === '') {
            return false;
        }

        try {
            $now = new \DateTime('now', new \DateTimeZone((string) $user['timezone']));
        } catch (\Throwable) {
            return false;
        }

        $current = (int) $now->format('Gi');
        $from = (int) str_replace(':', '', substr($start, 0, 5));
        $to = (int) str_replace(':', '', substr($end, 0, 5));

        // Windows that cross midnight (e.g. 23:00 -> 06:30).
        return $from <= $to
            ? ($current >= $from && $current < $to)
            : ($current >= $from || $current < $to);
    }

    private static function dndEndUtc(array $user, array $settings): string
    {
        $end = (string) ($settings['dnd_end'] ?? '06:30:00');

        try {
            $tz = new \DateTimeZone((string) $user['timezone']);
            $now = new \DateTime('now', $tz);
            $resume = new \DateTime($now->format('Y-m-d') . ' ' . $end, $tz);

            if ($resume <= $now) {
                $resume->modify('+1 day');
            }

            $resume->setTimezone(new \DateTimeZone('UTC'));

            return $resume->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s', time() + 3600);
        }
    }

    private static function inHolidayMode(array $settings): bool
    {
        $until = $settings['holiday_mode_until'] ?? null;

        return $until !== null && strtotime((string) $until . ' 23:59:59') >= time();
    }

    private static function createNotification(int $userId, array $reminder, array $occurrence): void
    {
        try {
            App::i()->db()->insert('notifications', [
                'user_id'    => $userId,
                'title'      => mb_substr((string) $reminder['title'], 0, 190),
                'body'       => 'Due ' . to_user_time((string) $occurrence['due_at'], 'd M Y, h:i A', ReminderService::userTimezone($userId)),
                'kind'       => 'reminder_due',
                'link'       => '/client/reminders/' . (int) $reminder['id'],
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable) {
            // Notification centre entry is best effort.
        }
    }

    /**
     * Follow-up chain: "if not done by 6 PM, remind me again."
     */
    public static function createFollowUp(int $reminderId, int $userId, int $minutesLater = 120): ?array
    {
        $db = App::i()->db();
        $reminder = $db->one('SELECT * FROM reminders WHERE id = ? AND user_id = ?', [$reminderId, $userId]);

        if ($reminder === null) {
            return null;
        }

        return ReminderService::create($userId, [
            'title'         => (string) $reminder['title'],
            'description'   => (string) $reminder['description'],
            'type'          => (string) $reminder['type'],
            'priority'      => 'high',
            'start_at'      => date('Y-m-d H:i:s', time() + ($minutesLater * 60)),
            'call_reminder' => 1,
            'parent_id'     => $reminderId,
            'source'        => 'system',
        ]);
    }
}
