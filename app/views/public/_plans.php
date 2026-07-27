<?php

use App\Core\Lang;

/** @var array $plans */

$locale = Lang::locale();
?>
<div class="grid grid-4 mt-3">
    <?php foreach ($plans as $plan):
        $name = match ($locale) {
            'gu' => $plan['name_gu'] ?: $plan['name'],
            'hi' => $plan['name_hi'] ?: $plan['name'],
            default => $plan['name'],
        };

        $unlimited = static fn (int $value): string => $value < 0 ? __('billing.unlimited') : number_format($value);
    ?>
        <div class="price-card<?= (string) $plan['code'] === 'pro' ? ' featured' : '' ?>">
            <?php if ((string) $plan['code'] === 'pro'): ?>
                <span class="badge badge-warning mb-1">Most popular</span>
            <?php endif; ?>

            <h3><?= e($name) ?></h3>

            <div class="price">
                <?= (float) $plan['price'] > 0 ? e(money((float) $plan['price'], (string) $plan['currency'])) : 'Free' ?>
                <small>/ <?= (int) $plan['duration_days'] ?> days</small>
            </div>

            <ul>
                <li>🔔 <?= e($unlimited((int) $plan['max_reminders_month'])) ?> reminders / month</li>
                <li>🤖 <?= e($unlimited((int) $plan['max_ai_messages_month'])) ?> AI messages</li>
                <li>📱 <?= (int) $plan['max_devices'] ?> device<?= (int) $plan['max_devices'] > 1 ? 's' : '' ?></li>
                <li><?= (int) $plan['call_reminders'] === 1 ? '✅' : '—' ?> Call reminders</li>
                <li><?= (int) $plan['google_sync'] === 1 ? '✅' : '—' ?> Google sync</li>
                <li><?= (int) $plan['max_staff'] > 0 ? '✅ ' . (int) $plan['max_staff'] : '—' ?> staff assignment</li>
                <li><?= (int) $plan['api_access'] === 1 ? '✅' : '—' ?> API access</li>
                <li><?= (int) $plan['full_reports'] === 1 ? '✅ Full' : '📊 Basic' ?> reports</li>
            </ul>

            <a class="btn <?= (string) $plan['code'] === 'pro' ? 'btn-gold' : 'btn-ghost' ?> btn-block"
               href="<?= e(url('/register?plan=' . $plan['code'])) ?>">
                <?= (float) $plan['price'] > 0 ? e(__('billing.upgrade')) : e(__('landing.cta_start')) ?>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<p class="text-center text-sm text-muted mt-2">
    All prices include GST. Cancel any time · 7-day money-back guarantee.
</p>
