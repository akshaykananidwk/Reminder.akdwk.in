<?php /** @var array $usage */ /** @var array $plans */ /** @var array $invoices */ /** @var array $subscriptions */ /** @var array|null $authUser */
$plan = $usage['plan'];
$limit = static fn (int $value): string => $value < 0 ? __('billing.unlimited') : number_format($value);
?>
<div class="grid grid-2 mb-2">
    <div class="card">
        <h3><?= t('billing.current_plan') ?></h3>
        <div class="value" style="font-size:1.7rem;font-weight:800"><?= e((string) ($plan['name'] ?? 'Trial')) ?></div>
        <?php if (!empty($authUser['plan_expires_at'])): ?>
            <p class="text-sm text-muted"><?= t('billing.expires_on') ?>: <?= e(to_user_time((string) $authUser['plan_expires_at'], 'd M Y')) ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?= t('billing.usage') ?></h3>
        <ul class="list">
            <li class="list-item"><div class="body"><div class="title text-sm"><?= t('nav.reminders') ?></div></div>
                <span><?= (int) $usage['reminders'] ?> / <?= e($limit((int) ($plan['max_reminders_month'] ?? 0))) ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">AI messages</div></div>
                <span><?= (int) $usage['ai_messages'] ?> / <?= e($limit((int) ($plan['max_ai_messages_month'] ?? 0))) ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">AI tokens</div></div>
                <span><?= number_format((int) $usage['ai_tokens']) ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm"><?= t('nav.devices') ?></div></div>
                <span><?= (int) $usage['devices'] ?> / <?= (int) ($plan['max_devices'] ?? 1) ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">Calls placed</div></div>
                <span><?= (int) $usage['calls'] ?></span></li>
        </ul>
    </div>
</div>

<div class="card mb-2">
    <h3><?= t('billing.upgrade') ?></h3>

    <div class="grid grid-4 mt-2">
        <?php foreach ($plans as $option): ?>
            <form class="price-card<?= (int) ($plan['id'] ?? 0) === (int) $option['id'] ? ' featured' : '' ?>" method="post" action="<?= e(url('/client/billing/subscribe')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="plan_id" value="<?= (int) $option['id'] ?>">

                <h3><?= e((string) $option['name']) ?></h3>
                <div class="price"><?= (float) $option['price'] > 0 ? e(money((float) $option['price'], (string) $option['currency'])) : 'Free' ?>
                    <small>/ <?= (int) $option['duration_days'] ?>d</small></div>

                <ul>
                    <li>🔔 <?= e($limit((int) $option['max_reminders_month'])) ?></li>
                    <li>🤖 <?= e($limit((int) $option['max_ai_messages_month'])) ?></li>
                    <li>📱 <?= (int) $option['max_devices'] ?></li>
                    <li><?= (int) $option['google_sync'] === 1 ? '✅' : '—' ?> Google</li>
                    <li><?= (int) $option['api_access'] === 1 ? '✅' : '—' ?> API</li>
                </ul>

                <input name="coupon" placeholder="<?= t('billing.coupon') ?>" class="mb-1">
                <button class="btn btn-block <?= (int) ($plan['id'] ?? 0) === (int) $option['id'] ? 'btn-ghost' : '' ?>">
                    <?= (int) ($plan['id'] ?? 0) === (int) $option['id'] ? __('common.refresh') : __('billing.upgrade') ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>

    <p class="text-sm text-muted mt-2">
        Pay to <strong><?= e((string) setting('company_phone', '')) ?></strong> (UPI / bank) and submit the request —
        we activate the plan as soon as we see it. Prices include GST.
    </p>
</div>

<?php if ($subscriptions !== []): ?>
    <div class="card mb-2">
        <h3><?= t('admin.subscriptions') ?></h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th><?= t('admin.plans') ?></th><th><?= t('common.amount') ?></th><th><?= t('common.status') ?></th><th><?= t('billing.expires_on') ?></th></tr></thead>
                <tbody>
                <?php foreach ($subscriptions as $subscription): ?>
                    <tr>
                        <td><?= e((string) $subscription['plan_name']) ?></td>
                        <td><?= e(money((float) $subscription['amount'], (string) $subscription['currency'])) ?></td>
                        <td><span class="badge badge-<?= e(status_badge((string) $subscription['status'])) ?>"><?= e((string) $subscription['status']) ?></span></td>
                        <td><?= e(to_user_time((string) $subscription['ends_at'], 'd M Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($invoices !== []): ?>
    <div class="card">
        <h3><?= t('billing.invoices') ?></h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>#</th><th><?= t('common.date') ?></th><th><?= t('common.amount') ?></th><th><?= t('common.status') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?= e((string) $invoice['invoice_no']) ?></td>
                        <td><?= e(to_user_time((string) $invoice['issued_at'], 'd M Y')) ?></td>
                        <td><?= e(money((float) $invoice['total'], (string) $invoice['currency'])) ?></td>
                        <td><span class="badge badge-<?= e(status_badge((string) $invoice['status'])) ?>"><?= e((string) $invoice['status']) ?></span></td>
                        <td><a class="btn btn-sm btn-ghost" target="_blank" href="<?= e(url('/client/billing/invoice/' . (int) $invoice['id'])) ?>">🖨</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
