<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Logger;
use App\Services\WhatsAppService;

/**
 * Payment due reminders for money that has no reminder attached to it.
 *
 * Most payments are created alongside a reminder and the dispatcher handles
 * them. But a payment can be entered on its own — an EMI series, a bill added
 * from the payments screen, a receivable typed in during a phone call — and
 * those had nothing watching them: the due date passed and nobody was told.
 *
 * So this covers exactly the gap: unpaid payments with no linked reminder,
 * due today or overdue, one message per payment per day. Anything already
 * carried by a reminder is left alone, because two notifications for the same
 * rupee is how people learn to ignore both.
 */
class PaymentDueJob extends Job
{
    public static function key(): string
    {
        return 'payment_due';
    }

    public static function label(): string
    {
        return 'Payment due reminders';
    }

    public static function description(): string
    {
        return 'Messages customers about unpaid amounts that have no reminder of their own — due today or overdue.';
    }

    public static function group(): string
    {
        return 'billing';
    }

    public static function priority(): int
    {
        return 32;
    }

    public static function scheduleKind(): string
    {
        return 'daily';
    }

    public static function runAt(): ?string
    {
        return '10:00';
    }

    public static function timeoutSeconds(): int
    {
        return 600;
    }

    public function handle(): array
    {
        $db = App::i()->db();
        $sent = 0;
        $skipped = 0;

        $rows = $db->all(
            "SELECT p.*, u.id AS uid, u.name AS user_name, u.phone, u.language, u.timezone
               FROM payments p
               JOIN users u ON u.id = p.user_id
              WHERE p.deleted_at IS NULL
                AND p.reminder_id IS NULL
                AND p.status IN ('unpaid','partial')
                AND u.is_active = 1
                AND u.deleted_at IS NULL
              ORDER BY p.due_date ASC
              LIMIT 500"
        );

        foreach ($rows as $row) {
            try {
                // "Due today" is a question about the customer's calendar, not
                // the server's: a bill due on the 5th in Dwarka is not due on
                // the 4th because the server thinks in UTC.
                $today = (new \DateTimeImmutable('now', new \DateTimeZone(user_tz($row))))->format('Y-m-d');

                if ((string) $row['due_date'] > $today) {
                    continue;
                }

                if ($this->alreadyNotifiedToday($row, $today)) {
                    $skipped++;
                    continue;
                }

                $outstanding = max(0, (float) $row['amount'] - (float) $row['paid_amount']);

                if ($outstanding <= 0) {
                    continue;
                }

                $user = [
                    'id'       => (int) $row['uid'],
                    'name'     => (string) $row['user_name'],
                    'phone'    => (string) $row['phone'],
                    'language' => (string) $row['language'],
                ];

                $queued = WhatsAppService::queueTemplate('payment_due', $user, [
                    'code'         => 'P' . (int) $row['id'],
                    'name'         => (string) $row['party_name'],
                    'amount'       => money($outstanding, (string) $row['currency']),
                    'amount_plain' => (string) round($outstanding, 2),
                    'time'         => (string) $row['due_date'],
                ], 6);

                if ($queued > 0) {
                    $this->markNotified((int) $row['id'], $today);
                    $sent++;
                }
            } catch (\Throwable $e) {
                Logger::warn('Payment due reminder failed', [
                    'payment_id' => $row['id'] ?? null,
                    'error'      => $e->getMessage(),
                ], 'payments');
            }
        }

        return [
            'processed' => $sent,
            'message'   => sprintf('sent=%d already_notified=%d', $sent, $skipped),
        ];
    }

    /**
     * One message per payment per local day.
     *
     * The marker is a note on the payment row rather than a new table: an
     * overdue payment is chased every day until it is settled, so all that has
     * to be remembered is the date of the last chase.
     */
    private function alreadyNotifiedToday(array $row, string $today): bool
    {
        return str_contains((string) ($row['note'] ?? ''), '[due-notified:' . $today . ']');
    }

    private function markNotified(int $paymentId, string $today): void
    {
        try {
            $db = App::i()->db();
            $current = (string) $db->value('SELECT note FROM payments WHERE id = ?', [$paymentId], '');

            // Drop any previous marker so the note does not grow without bound.
            $cleaned = trim((string) preg_replace('/\s*\[due-notified:[0-9-]+\]/', '', $current));
            $marker = '[due-notified:' . $today . ']';

            $db->update(
                'payments',
                ['note' => mb_substr(trim($cleaned . ' ' . $marker), 0, 255)],
                'id = :id',
                ['id' => $paymentId]
            );
        } catch (\Throwable $e) {
            Logger::warn('Could not mark payment as notified', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ], 'payments');
        }
    }
}
