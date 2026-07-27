<?php

/** @var array $stats */
/** @var array $today */
/** @var array $overdue */
/** @var array $payments */
/** @var array $duePayments */
/** @var array $notifications */
/** @var array|null $authUser */

$tz = (string) ($authUser['timezone'] ?? 'Asia/Kolkata');
?>

<form class="card mb-2" id="quick-add-form">
    <label class="form-label" for="quick-text"><?= t('dashboard.quick_add') ?></label>
    <div class="flex">
        <input id="quick-text" name="text" class="grow" placeholder="<?= t('dashboard.quick_add_hint') ?>" autocomplete="off">
        <button class="btn" type="submit">+</button>
    </div>
    <div id="quick-add-preview" class="hide mt-1"></div>
</form>

<div class="grid grid-4 mb-2">
    <div class="stat-card">
        <span class="value"><?= (int) $stats['today_total'] ?></span>
        <span class="label"><?= t('dashboard.today_tasks') ?></span>
    </div>
    <div class="stat-card gold">
        <span class="value"><?= (int) $stats['pending'] ?></span>
        <span class="label"><?= t('dashboard.pending') ?></span>
    </div>
    <div class="stat-card red">
        <span class="value"><?= (int) $stats['overdue'] ?></span>
        <span class="label"><?= t('dashboard.overdue') ?></span>
    </div>
    <div class="stat-card green">
        <span class="value"><?= (int) $stats['done_today'] ?></span>
        <span class="label"><?= t('dashboard.completed_today') ?></span>
    </div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="flex" style="gap:18px">
            <div class="ring-wrap">
                <div class="ring" style="--p:<?= (int) $stats['progress'] ?>"><b><?= (int) $stats['progress'] ?>%</b></div>
            </div>
            <div class="grow">
                <h3 class="mb-1"><?= t('dashboard.progress_today') ?></h3>
                <p class="text-sm text-muted mb-1">
                    <?= (int) $stats['done_today'] ?> / <?= (int) $stats['today_total'] ?> · 🔥 <?= (int) $stats['streak'] ?> <?= t('dashboard.streak') ?>
                </p>
                <?php if ($stats['next'] !== null): ?>
                    <div class="text-xs text-muted"><?= t('dashboard.next_reminder') ?></div>
                    <div class="countdown" data-countdown="<?= e(gmdate('c', strtotime((string) $stats['next']['due_at'] . ' UTC'))) ?>" data-due-label="🔔">—</div>
                    <div class="text-sm"><?= e(str_limit((string) $stats['next']['title'], 40)) ?></div>
                <?php else: ?>
                    <div class="text-sm text-muted"><?= t('dashboard.no_next') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3><?= t('dashboard.payments_due') ?></h3>
            <a class="text-sm" href="<?= e(url('/client/payments')) ?>"><?= t('common.view') ?> →</a>
        </div>

        <div class="value" style="font-size:1.7rem;font-weight:800"><?= e(money((float) $payments['this_month'])) ?></div>

        <div class="flex-between text-sm text-muted mt-1">
            <span><?= t('payments.total_receivable') ?>: <strong><?= e(money((float) $payments['receivable'])) ?></strong></span>
            <span><?= t('payments.total_payable') ?>: <strong><?= e(money((float) $payments['payable'])) ?></strong></span>
        </div>

        <?php if ($duePayments !== []): ?>
            <ul class="list mt-2">
                <?php foreach (array_slice($duePayments, 0, 3) as $payment): ?>
                    <li class="list-item">
                        <div class="body">
                            <div class="title text-sm"><?= e($payment['party_name']) ?></div>
                            <div class="meta"><?= e(to_user_time($payment['due_date'] . ' 00:00:00', 'd M', $tz)) ?></div>
                        </div>
                        <strong class="text-sm"><?= e(money((float) $payment['amount'] - (float) $payment['paid_amount'], (string) $payment['currency'])) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($overdue !== []): ?>
    <div class="card mb-2" style="border-left:4px solid var(--red)">
        <div class="card-head">
            <h3>❗ <?= t('dashboard.overdue') ?></h3>
            <a class="text-sm" href="<?= e(url('/client/reminders?filter=overdue')) ?>"><?= t('common.all') ?> →</a>
        </div>
        <ul class="list">
            <?php foreach ($overdue as $row): ?>
                <?php \App\Core\View::partial('client/_occurrence_row', ['row' => $row, 'tz' => $tz, 'csrf' => $csrf]); ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3><?= t('dashboard.todays_list') ?></h3>
        <a class="text-sm" href="<?= e(url('/client/reminders')) ?>"><?= t('common.all') ?> →</a>
    </div>

    <?php if ($today === []): ?>
        <div class="empty">
            <div class="icon">🌸</div>
            <h3><?= t('dashboard.all_done') ?></h3>
            <p class="text-sm"><?= t('dashboard.quick_add_hint') ?></p>
        </div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($today as $row): ?>
                <?php \App\Core\View::partial('client/_occurrence_row', ['row' => $row, 'tz' => $tz, 'csrf' => $csrf]); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($notifications !== []): ?>
    <div class="card mt-2">
        <h3><?= t('settings.notifications') ?></h3>
        <ul class="list">
            <?php foreach ($notifications as $notification): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e($notification['title']) ?></div>
                        <div class="meta"><?= e(human_diff((string) $notification['created_at'])) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
