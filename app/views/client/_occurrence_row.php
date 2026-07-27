<?php

/**
 * One occurrence line, shared by the dashboard and the reminders list.
 *
 * @var array $row
 * @var string $tz
 * @var string $csrf
 */

$status = (string) $row['status'];
$isOpen = in_array($status, ['pending', 'notified', 'snoozed', 'missed'], true);
$isOverdue = $isOpen && strtotime((string) $row['due_at'] . ' UTC') < time();

$typeIcon = match ((string) ($row['type'] ?? 'task')) {
    'payment'  => '💰',
    'call'     => '📞',
    'meeting'  => '👥',
    'medicine' => '💊',
    'birthday' => '🎂',
    'bill'     => '🧾',
    default    => '🔔',
};
?>
<li class="list-item<?= $status === 'done' ? ' done' : '' ?>" data-row>
    <?php if (!empty($bulk)): ?>
        <input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" style="width:20px;height:20px;min-height:0;margin-top:4px">
    <?php endif; ?>

    <span class="time"><?= e(to_user_time((string) $row['due_at'], 'h:i A', $tz)) ?></span>

    <div class="body">
        <a class="title" href="<?= e(url('/client/reminders/' . (int) $row['reminder_id'])) ?>">
            <?= $typeIcon ?> <?= e($row['title']) ?>
        </a>

        <div class="meta">
            <span class="badge badge-<?= e(status_badge($status)) ?>"><?= t('status.' . $status) ?></span>
            <?php if (!empty($row['short_code'])): ?>
                <code><?= e($row['short_code']) ?></code>
            <?php endif; ?>
            <?php if (!empty($row['amount'])): ?>
                · <strong><?= e(money((float) $row['amount'], (string) ($row['currency'] ?? 'INR'))) ?></strong>
            <?php endif; ?>
            <?php if (!empty($row['person_name'])): ?>
                · <?= e($row['person_name']) ?>
            <?php endif; ?>
            <?php if ($isOverdue): ?>
                · <span style="color:var(--red)"><?= e(human_diff((string) $row['due_at'])) ?></span>
            <?php endif; ?>
            <?php if ((int) ($row['snooze_count'] ?? 0) > 0): ?>
                · ⏰ ×<?= (int) $row['snooze_count'] ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isOpen): ?>
        <div class="actions">
            <button class="btn btn-sm btn-green" data-action="done" data-occurrence="<?= (int) $row['id'] ?>" title="<?= t('reminder.mark_done') ?>">✓</button>
            <button class="btn btn-sm btn-ghost" data-action="snooze" data-minutes="10" data-occurrence="<?= (int) $row['id'] ?>" title="<?= t('reminder.snooze') ?> 10m">⏰</button>
        </div>
    <?php endif; ?>
</li>
