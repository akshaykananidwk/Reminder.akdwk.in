<?php /** @var array $devices */ /** @var bool $fcmReady */ /** @var string $apkUrl */ ?>
<?php if (!$fcmReady): ?>
    <div class="alert alert-warning">Push notifications are not configured on the server yet — reminders will still arrive on WhatsApp.</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3><?= t('devices.title') ?></h3>
        <a class="btn btn-sm" href="<?= e($apkUrl) ?>" target="_blank" rel="noopener">📥 <?= t('nav.download') ?></a>
    </div>

    <?php if ($devices === []): ?>
        <div class="empty"><div class="icon">📱</div><h3><?= t('devices.none') ?></h3>
            <a class="btn mt-2" href="<?= e($apkUrl) ?>" target="_blank" rel="noopener"><?= t('nav.download') ?></a></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($devices as $device): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title"><?= e((string) ($device['name'] ?: $device['model'] ?: 'Android device')) ?></div>
                        <div class="meta">
                            <?= e((string) $device['manufacturer']) ?> <?= e((string) $device['model']) ?>
                            · Android <?= e((string) $device['os_version']) ?>
                            · v<?= e((string) $device['app_version']) ?>
                            · <?= t('devices.last_seen') ?>: <?= e(human_diff((string) $device['last_seen_at'])) ?>
                            <span class="badge badge-<?= (int) $device['push_ok'] === 1 && !empty($device['fcm_token']) ? 'success' : 'danger' ?>">
                                <?= (int) $device['push_ok'] === 1 && !empty($device['fcm_token']) ? 'push ok' : 'no push' ?>
                            </span>
                            <?php if ((int) $device['is_active'] !== 1): ?><span class="badge badge-secondary">removed</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="actions">
                        <form method="post" action="<?= e(url('/client/devices/' . (int) $device['id'] . '/test')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <button class="btn btn-sm"><?= t('devices.send_test') ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/client/devices/' . (int) $device['id'] . '/delete')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" data-action="confirm" data-message="<?= t('common.confirm') ?>">🗑</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
