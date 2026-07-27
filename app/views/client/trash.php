<?php /** @var array $rows */ ?>
<div class="card">
    <div class="card-head">
        <h3><?= t('nav.trash') ?></h3>
        <span class="text-sm text-muted">Items are removed permanently after 30 days.</span>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty"><div class="icon">🗑</div><h3><?= t('common.no_data') ?></h3></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($rows as $row): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title"><?= e($row['title']) ?></div>
                        <div class="meta"><?= e(human_diff((string) $row['deleted_at'])) ?> · <code><?= e($row['short_code']) ?></code></div>
                    </div>
                    <form method="post" action="<?= e(url('/client/reminders/' . (int) $row['id'] . '/restore')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <button class="btn btn-sm btn-ghost">↩ <?= t('common.reset') ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
