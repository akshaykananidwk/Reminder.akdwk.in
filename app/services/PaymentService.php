<?php

namespace App\Services;

use App\Core\App;

/**
 * Payment ledger: partial payments, receivable/payable totals and EMI series.
 */
class PaymentService
{
    /**
     * Record a (possibly partial) payment against a payment row.
     */
    public static function recordPayment(int $paymentId, int $userId, float $amount, string $method = 'cash', ?string $note = null, ?int $attachmentId = null): bool
    {
        $db = App::i()->db();

        $payment = $db->one('SELECT * FROM payments WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$paymentId, $userId]);

        if ($payment === null || $amount <= 0) {
            return false;
        }

        return $db->transaction(function () use ($db, $payment, $paymentId, $userId, $amount, $method, $note, $attachmentId) {
            $db->insert('payment_transactions', [
                'payment_id'    => $paymentId,
                'user_id'       => $userId,
                'amount'        => $amount,
                'method'        => mb_substr($method, 0, 60),
                'note'          => $note === null ? null : mb_substr($note, 0, 255),
                'attachment_id' => $attachmentId,
                'paid_at'       => now_utc(),
                'created_at'    => now_utc(),
            ]);

            $paid = (float) $payment['paid_amount'] + $amount;
            $total = (float) $payment['amount'];

            $status = match (true) {
                $paid >= $total - 0.001 => 'paid',
                $paid > 0               => 'partial',
                default                 => 'unpaid',
            };

            $db->update('payments', [
                'paid_amount'          => round($paid, 2),
                'status'               => $status,
                'paid_at'              => $status === 'paid' ? now_utc() : null,
                'receipt_attachment_id'=> $attachmentId ?? $payment['receipt_attachment_id'],
            ], 'id = :id', ['id' => $paymentId]);

            return true;
        });
    }

    /**
     * @return array{receivable: float, payable: float, overdue: float, this_month: float, paid_today: float}
     */
    public static function totals(int $userId, string $tz = 'Asia/Kolkata'): array
    {
        $db = App::i()->db();
        [$monthStartUtc, $monthEndUtc] = ReminderService::localMonthBounds($tz);
        [$dayStartUtc, $dayEndUtc] = ReminderService::localDayBounds($tz);

        return [
            'receivable' => (float) $db->value(
                'SELECT COALESCE(SUM(amount - paid_amount), 0) FROM payments WHERE user_id = ? AND deleted_at IS NULL AND direction = "receivable" AND status IN ("unpaid","partial")',
                [$userId], 0.0
            ),
            'payable' => (float) $db->value(
                'SELECT COALESCE(SUM(amount - paid_amount), 0) FROM payments WHERE user_id = ? AND deleted_at IS NULL AND direction = "payable" AND status IN ("unpaid","partial")',
                [$userId], 0.0
            ),
            'overdue' => (float) $db->value(
                'SELECT COALESCE(SUM(amount - paid_amount), 0) FROM payments WHERE user_id = ? AND deleted_at IS NULL AND status IN ("unpaid","partial") AND due_date < CURDATE()',
                [$userId], 0.0
            ),
            'this_month' => (float) $db->value(
                'SELECT COALESCE(SUM(amount - paid_amount), 0) FROM payments WHERE user_id = ? AND deleted_at IS NULL AND status IN ("unpaid","partial") AND due_date BETWEEN ? AND ?',
                [$userId, substr($monthStartUtc, 0, 10), substr($monthEndUtc, 0, 10)], 0.0
            ),
            'paid_today' => (float) $db->value(
                'SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE user_id = ? AND paid_at BETWEEN ? AND ?',
                [$userId, $dayStartUtc, $dayEndUtc], 0.0
            ),
        ];
    }

    /**
     * Monthly chart series for the payments screen (last 6 months).
     */
    public static function monthlySeries(int $userId, int $months = 6): array
    {
        $rows = App::i()->db()->all(
            'SELECT DATE_FORMAT(paid_at, "%Y-%m") AS ym, SUM(amount) AS total
               FROM payment_transactions
              WHERE user_id = ? AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
              GROUP BY ym ORDER BY ym ASC',
            [$userId, $months]
        );

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("-$i months"));
            $series[$key] = 0.0;
        }

        foreach ($rows as $row) {
            $series[(string) $row['ym']] = (float) $row['total'];
        }

        return $series;
    }

    /**
     * Create an EMI series: N monthly payment rows plus their reminders.
     */
    public static function createEmiSeries(int $userId, string $partyName, float $perMonth, string $firstDueDate, int $installments, string $direction = 'payable'): int
    {
        $created = 0;

        for ($i = 0; $i < $installments; $i++) {
            $due = date('Y-m-d', strtotime($firstDueDate . ' +' . $i . ' month'));

            $reminder = ReminderService::create($userId, [
                'title'      => $partyName . ' — EMI ' . ($i + 1) . '/' . $installments,
                'type'       => 'payment',
                'start_at'   => to_utc($due . ' 10:00:00', ReminderService::userTimezone($userId)),
                'amount'     => $perMonth,
                'person_name'=> $partyName,
                'source'     => 'web',
            ]);

            if ($reminder === null) {
                continue;
            }

            App::i()->db()->update('payments', [
                'is_emi'    => 1,
                'emi_total' => $installments,
                'emi_index' => $i + 1,
                'direction' => $direction,
                'due_date'  => $due,
            ], 'reminder_id = :rid', ['rid' => (int) $reminder['id']]);

            $created++;
        }

        return $created;
    }

    public static function duePayments(int $userId, int $limit = 50): array
    {
        return App::i()->db()->all(
            'SELECT p.*, r.short_code
               FROM payments p
               LEFT JOIN reminders r ON r.id = p.reminder_id
              WHERE p.user_id = ? AND p.deleted_at IS NULL AND p.status IN ("unpaid","partial")
              ORDER BY p.due_date ASC
              LIMIT ' . (int) $limit,
            [$userId]
        );
    }
}
