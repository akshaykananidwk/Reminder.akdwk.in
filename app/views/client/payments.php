<?php
/** @var array $payments */ /** @var array $totals */ /** @var array $series */
/** @var string $status */ /** @var string $direction */ /** @var array $contacts */
$max = max(1, max($series ?: [1]));
?>
<div class="grid grid-4 mb-2">
    <div class="stat-card green"><span class="value"><?= e(money((float) $totals['receivable'])) ?></span><span class="label"><?= t('payments.total_receivable') ?></span></div>
    <div class="stat-card red"><span class="value"><?= e(money((float) $totals['payable'])) ?></span><span class="label"><?= t('payments.total_payable') ?></span></div>
    <div class="stat-card gold"><span class="value"><?= e(money((float) $totals['overdue'])) ?></span><span class="label"><?= t('payments.overdue') ?></span></div>
    <div class="stat-card"><span class="value"><?= e(money((float) $totals['paid_today'])) ?></span><span class="label"><?= t('payments.paid_amount') ?> — <?= t('common.today') ?></span></div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <h3><?= t('payments.history') ?> (6 <?= t('reports.monthly') ?>)</h3>
        <div class="bars">
            <?php foreach ($series as $value): ?>
                <div class="bar green" style="height:<?= (int) max(3, ($value / $max) * 100) ?>%" title="<?= e(money((float) $value)) ?>"></div>
            <?php endforeach; ?>
        </div>
        <div class="bars-labels">
            <?php foreach (array_keys($series) as $key): ?>
                <span><?= e(substr((string) $key, 5)) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <form class="card" method="post" action="<?= e(url('/client/payments')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3><?= t('common.add') ?></h3>

        <div class="field"><label for="p-party"><?= t('payments.party') ?></label><input id="p-party" name="party_name" required maxlength="160"></div>

        <div class="grid grid-2">
            <div class="field"><label for="p-amount"><?= t('common.amount') ?></label><input id="p-amount" name="amount" type="number" step="0.01" min="1" required></div>
            <div class="field"><label for="p-due"><?= t('payments.due_date') ?></label><input id="p-due" name="due_date" type="date" required value="<?= e(date('Y-m-d')) ?>"></div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="p-direction"><?= t('payments.direction') ?></label>
                <select id="p-direction" name="direction">
                    <option value="payable"><?= t('payments.payable') ?></option>
                    <option value="receivable"><?= t('payments.receivable') ?></option>
                </select>
            </div>
            <div class="field"><label for="p-emi"><?= t('payments.installments') ?></label><input id="p-emi" name="installments" type="number" min="1" max="60" value="1"></div>
        </div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h3><?= t('payments.title') ?></h3>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/payments/export')) ?>">⬇ CSV</a>
    </div>

    <div class="chips mb-2">
        <a class="chip <?= $status === 'unpaid' ? 'active' : '' ?>" href="?status=unpaid"><?= t('status.unpaid') ?></a>
        <a class="chip <?= $status === 'paid' ? 'active' : '' ?>" href="?status=paid"><?= t('status.paid') ?></a>
        <a class="chip <?= $direction === 'receivable' ? 'active' : '' ?>" href="?direction=receivable"><?= t('payments.receivable') ?></a>
        <a class="chip <?= $direction === 'payable' ? 'active' : '' ?>" href="?direction=payable"><?= t('payments.payable') ?></a>
    </div>

    <?php if ($payments === []): ?>
        <div class="empty"><div class="icon">💰</div><h3><?= t('payments.no_payments') ?></h3></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('payments.party') ?></th><th><?= t('common.amount') ?></th>
                    <th><?= t('payments.balance') ?></th><th><?= t('payments.due_date') ?></th>
                    <th><?= t('common.status') ?></th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $payment):
                    $balance = (float) $payment['amount'] - (float) $payment['paid_amount'];
                ?>
                    <tr>
                        <td>
                            <?= e($payment['party_name']) ?>
                            <?php if ($payment['short_code']): ?><br><code class="text-xs"><?= e($payment['short_code']) ?></code><?php endif; ?>
                            <?php if ((int) $payment['is_emi'] === 1): ?>
                                <span class="badge badge-info"><?= t('payments.emi') ?> <?= (int) $payment['emi_index'] ?>/<?= (int) $payment['emi_total'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?></td>
                        <td><strong><?= e(money($balance, (string) $payment['currency'])) ?></strong></td>
                        <td><?= e(date('d M Y', strtotime((string) $payment['due_date']))) ?></td>
                        <td><span class="badge badge-<?= e(status_badge((string) $payment['status'])) ?>"><?= t('status.' . $payment['status']) ?></span></td>
                        <td>
                            <?php if ($balance > 0): ?>
                                <form method="post" action="<?= e(url('/client/payments/' . (int) $payment['id'] . '/pay')) ?>" enctype="multipart/form-data" class="flex">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="number" name="amount" step="0.01" min="1" value="<?= e((string) $balance) ?>" style="width:110px;min-height:36px">
                                    <button class="btn btn-sm btn-green"><?= t('payments.mark_paid') ?></button>
                                </form>
                            <?php else: ?>
                                <span class="text-sm text-muted"><?= e(to_user_time((string) $payment['paid_at'], 'd M Y')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
