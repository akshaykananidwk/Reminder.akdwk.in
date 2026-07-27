<?php
/** @var array $reminder */ /** @var array $occurrences */ /** @var array $timeline */
/** @var array $deliveries */ /** @var array $subtasks */ /** @var array $attachments */ /** @var string $tz */
$recurrence = json_field($reminder['recurrence'], ['freq' => 'none']);
?>
<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2><?= e($reminder['title']) ?></h2>
            <span class="badge badge-<?= e(status_badge((string) $reminder['status'])) ?>"><?= t('status.' . $reminder['status']) ?></span>
        </div>

        <?php if (!empty($reminder['description'])): ?>
            <p class="text-sm"><?= nl2br(e((string) $reminder['description'])) ?></p>
        <?php endif; ?>

        <table class="data" style="width:100%">
            <tbody>
            <tr><th>Code</th><td><code><?= e($reminder['short_code']) ?></code></td></tr>
            <tr><th><?= t('reminder.type') ?></th><td><?= t('types.' . $reminder['type']) ?></td></tr>
            <tr><th><?= t('reminder.priority') ?></th><td><?= t('priority.' . $reminder['priority']) ?></td></tr>
            <tr><th><?= t('reminder.due_date') ?></th><td><?= e(to_user_time((string) $reminder['start_at'], 'd M Y, h:i A', $tz)) ?></td></tr>
            <tr><th><?= t('reminder.repeat') ?></th><td><?= e(\App\Services\RecurrenceService::describe($recurrence, \App\Core\Lang::locale())) ?></td></tr>
            <tr><th><?= t('reminder.call_me') ?></th><td><?= (int) $reminder['call_reminder'] === 1 ? '✅' : '—' ?></td></tr>
            <?php if ($reminder['amount'] !== null): ?>
                <tr><th><?= t('reminder.amount') ?></th><td><strong><?= e(money((float) $reminder['amount'], (string) $reminder['currency'])) ?></strong></td></tr>
            <?php endif; ?>
            <?php if (!empty($reminder['person_name'])): ?>
                <tr><th><?= t('reminder.person') ?></th><td><?= e($reminder['person_name']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($reminder['location'])): ?>
                <tr><th><?= t('reminder.location') ?></th><td><?= e($reminder['location']) ?></td></tr>
            <?php endif; ?>
            <tr><th><?= t('reminder.source') ?></th><td><?= e($reminder['source']) ?></td></tr>
            </tbody>
        </table>

        <?php if ($attachments !== []): ?>
            <h3 class="mt-2"><?= t('reminder.attachment') ?></h3>
            <ul class="list">
                <?php foreach ($attachments as $attachment): ?>
                    <li class="list-item">
                        <a href="<?= e(url((string) $attachment['stored_name'])) ?>" target="_blank" rel="noopener"><?= e($attachment['original_name']) ?></a>
                        <span class="text-xs text-muted"><?= e((string) round((int) $attachment['size_bytes'] / 1024)) ?> KB</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="flex mt-2">
            <a class="btn btn-sm" href="<?= e(url('/client/reminders/' . (int) $reminder['id'] . '/edit')) ?>"><?= t('common.edit') ?></a>
            <form method="post" action="<?= e(url('/client/reminders/' . (int) $reminder['id'] . '/delete')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-danger" data-action="confirm" data-message="<?= t('reminder.confirm_delete') ?>"><?= t('common.delete') ?></button>
            </form>
        </div>
    </div>

    <div>
        <div class="card mb-2">
            <h3><?= t('reminder.timeline') ?></h3>

            <?php if ($occurrences === []): ?>
                <p class="text-sm text-muted"><?= t('common.no_data') ?></p>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($occurrences as $occurrence): ?>
                        <li class="list-item<?= $occurrence['status'] === 'done' ? ' done' : '' ?>">
                            <span class="time"><?= e(to_user_time((string) $occurrence['due_at'], 'd M', $tz)) ?></span>
                            <div class="body">
                                <div class="title text-sm"><?= e(to_user_time((string) $occurrence['due_at'], 'h:i A', $tz)) ?></div>
                                <div class="meta">
                                    <span class="badge badge-<?= e(status_badge((string) $occurrence['status'])) ?>"><?= t('status.' . $occurrence['status']) ?></span>
                                    <?php if ((int) $occurrence['attempt_count'] > 0): ?> · 📞 ×<?= (int) $occurrence['attempt_count'] ?><?php endif; ?>
                                    <?php if ((int) $occurrence['snooze_count'] > 0): ?> · ⏰ ×<?= (int) $occurrence['snooze_count'] ?><?php endif; ?>
                                    <?php if ($occurrence['done_via']): ?> · <?= e((string) $occurrence['done_via']) ?><?php endif; ?>
                                </div>
                            </div>

                            <?php if (in_array((string) $occurrence['status'], ['pending', 'notified', 'snoozed', 'missed'], true)): ?>
                                <div class="actions">
                                    <button class="btn btn-sm btn-green" data-action="done" data-occurrence="<?= (int) $occurrence['id'] ?>">✓</button>
                                    <button class="btn btn-sm btn-ghost" data-action="snooze" data-minutes="30" data-occurrence="<?= (int) $occurrence['id'] ?>">⏰</button>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($timeline !== [] || $deliveries !== []): ?>
            <div class="card">
                <h3>Activity</h3>
                <ul class="list">
                    <?php foreach ($deliveries as $delivery): ?>
                        <li class="list-item">
                            <div class="body">
                                <div class="title text-sm">📤 Attempt #<?= (int) $delivery['attempt_no'] ?> — <?= e((string) $delivery['status']) ?></div>
                                <div class="meta"><?= e(to_user_time((string) $delivery['created_at'], 'd M, h:i A', $tz)) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($timeline as $entry): ?>
                        <li class="list-item">
                            <div class="body">
                                <div class="title text-sm">👤 <?= e((string) $entry['action']) ?> — <?= e((string) $entry['source']) ?></div>
                                <div class="meta"><?= e(to_user_time((string) $entry['created_at'], 'd M, h:i A', $tz)) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
