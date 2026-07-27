<?php

namespace App\Services;

use App\Core\App;

/**
 * GST-compliant invoices. The PDF is produced as print-optimised HTML — no
 * PDF library is required on the host, and the browser's "Save as PDF" gives a
 * clean, correct document.
 */
class InvoiceService
{
    public static function createForSubscription(int $subscriptionId): ?array
    {
        $db = App::i()->db();

        $subscription = $db->one('SELECT * FROM subscriptions WHERE id = ?', [$subscriptionId]);

        if ($subscription === null) {
            return null;
        }

        $existing = $db->one('SELECT * FROM invoices WHERE subscription_id = ?', [$subscriptionId]);

        if ($existing !== null) {
            return $existing;
        }

        $settings = App::i()->settings();
        $user = $db->one('SELECT * FROM users WHERE id = ?', [(int) $subscription['user_id']]);

        $gross = (float) $subscription['amount'];
        $taxRate = (float) $settings->get('gst_rate', 18);

        // Displayed prices are inclusive of GST, so the tax is extracted.
        $subtotal = $taxRate > 0 ? round($gross / (1 + ($taxRate / 100)), 2) : $gross;
        $tax = round($gross - $subtotal, 2);

        $invoiceId = $db->insert('invoices', [
            'invoice_no'      => self::nextNumber(),
            'user_id'         => (int) $subscription['user_id'],
            'subscription_id' => $subscriptionId,
            'subtotal'        => $subtotal,
            'discount'        => 0,
            'tax_rate'        => $taxRate,
            'tax_amount'      => $tax,
            'total'           => $gross,
            'currency'        => (string) $subscription['currency'],
            'status'          => $subscription['status'] === 'active' ? 'paid' : 'unpaid',
            'billing_name'    => (string) ($user['name'] ?? ''),
            'issued_at'       => now_utc(),
            'paid_at'         => $subscription['status'] === 'active' ? now_utc() : null,
            'created_at'      => now_utc(),
        ]);

        return $db->one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
    }

    public static function nextNumber(): string
    {
        $settings = App::i()->settings();
        $prefix = (string) $settings->get('invoice_prefix', 'KR');

        $count = (int) App::i()->db()->value(
            'SELECT COUNT(*) FROM invoices WHERE YEAR(created_at) = ?',
            [date('Y')],
            0
        );

        return sprintf('%s/%s/%04d', $prefix, date('Y'), $count + 1);
    }

    /**
     * Apply a coupon and return the discounted amount.
     *
     * @return array{ok: bool, amount: float, discount: float, coupon: array|null, message: string}
     */
    public static function applyCoupon(string $code, float $amount, int $planId): array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return ['ok' => false, 'amount' => $amount, 'discount' => 0.0, 'coupon' => null, 'message' => ''];
        }

        $coupon = App::i()->db()->one(
            'SELECT * FROM coupons WHERE code = ? AND is_active = 1
              AND (valid_from IS NULL OR valid_from <= CURDATE())
              AND (valid_until IS NULL OR valid_until >= CURDATE())',
            [$code]
        );

        if ($coupon === null) {
            return ['ok' => false, 'amount' => $amount, 'discount' => 0.0, 'coupon' => null, 'message' => \App\Core\Lang::get('billing.coupon_invalid')];
        }

        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
            return ['ok' => false, 'amount' => $amount, 'discount' => 0.0, 'coupon' => null, 'message' => \App\Core\Lang::get('billing.coupon_used_up')];
        }

        if ($coupon['plan_id'] !== null && (int) $coupon['plan_id'] !== $planId) {
            return ['ok' => false, 'amount' => $amount, 'discount' => 0.0, 'coupon' => null, 'message' => \App\Core\Lang::get('billing.coupon_wrong_plan')];
        }

        $discount = $coupon['discount_type'] === 'percent'
            ? round($amount * ((float) $coupon['discount_value'] / 100), 2)
            : min($amount, (float) $coupon['discount_value']);

        return [
            'ok'       => true,
            'amount'   => round(max(0, $amount - $discount), 2),
            'discount' => $discount,
            'coupon'   => $coupon,
            'message'  => \App\Core\Lang::get('billing.coupon_applied', ['amount' => money($discount)]),
        ];
    }

    /**
     * Credit the referrer once their referral converts to a paid plan.
     */
    public static function creditReferral(int $userId, int $subscriptionId, float $amount): void
    {
        $db = App::i()->db();

        $referral = $db->one('SELECT * FROM referrals WHERE referred_id = ? AND status = "signed_up"', [$userId]);

        if ($referral === null) {
            return;
        }

        $percent = (float) App::i()->settings()->get('referral_commission_percent', 20);
        $commission = round($amount * ($percent / 100), 2);

        $db->update('referrals', [
            'status'       => 'converted',
            'converted_at' => now_utc(),
        ], 'id = :id', ['id' => (int) $referral['id']]);

        $db->insert('commissions', [
            'user_id'         => (int) $referral['referrer_id'],
            'referral_id'     => (int) $referral['id'],
            'subscription_id' => $subscriptionId,
            'amount'          => $commission,
            'type'            => 'earned',
            'status'          => 'pending',
            'note'            => 'Referral commission (' . $percent . '%)',
            'created_at'      => now_utc(),
        ]);
    }

    /**
     * Commission ledger balance for the referral screen.
     */
    public static function commissionBalance(int $userId): array
    {
        $db = App::i()->db();

        $earned = (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM commissions WHERE user_id = ? AND type = "earned" AND status IN ("pending","approved","paid")', [$userId], 0.0);
        $paid = (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM commissions WHERE user_id = ? AND type = "payout" AND status = "paid"', [$userId], 0.0);

        return [
            'earned'    => $earned,
            'paid'      => $paid,
            'available' => round($earned - $paid, 2),
        ];
    }
}
