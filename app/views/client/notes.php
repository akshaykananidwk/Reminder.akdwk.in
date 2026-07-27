<?php /** @var array $notes */ /** @var string $tz */ ?>
<form class="card mb-2" method="post" action="<?= e(url('/client/notes')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="field">
        <label for="n-body"><?= t('notes.body') ?></label>
        <textarea id="n-body" name="body" required placeholder="…"></textarea>
    </div>
    <button class="btn btn-sm"><?= t('common.save') ?></button>
</form>

<div class="card">
    <h3><?= t('nav.notes') ?></h3>

    <?php if ($notes === []): ?>
        <div class="empty"><div class="icon">📝</div><h3><?= t('notes.none') ?></h3>
            <p class="text-sm">Messages you send on WhatsApp without a date appear here.</p></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($notes as $note): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title"><?= e(str_limit((string) $note['body'], 160)) ?></div>
                        <div class="meta">
                            <?= e(to_user_time((string) $note['created_at'], 'd M Y, h:i A', $tz)) ?>
                            · <?= e((string) $note['source']) ?>
                            <?php if (!empty($note['short_code'])): ?>
                                · <span class="badge badge-success">→ <?= e($note['short_code']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="actions">
                        <?php if (empty($note['converted_reminder_id'])): ?>
                            <form method="post" action="<?= e(url('/client/notes/' . (int) $note['id'] . '/convert')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <button class="btn btn-sm"><?= t('notes.convert') ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/client/notes/' . (int) $note['id'] . '/delete')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" data-action="confirm" data-message="<?= t('reminder.confirm_delete') ?>">🗑</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
