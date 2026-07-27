<?php /** @var array $backups */ /** @var string $backupDir */ /** @var array $exposure */ /** @var bool $maintenance */
/** @var array $phpInfo */ /** @var array $tableSizes */ ?>
<?php if ($exposure !== []): ?>
    <div class="alert alert-danger">
        ⚠️ <strong>Security:</strong> these are reachable from the web root — remove them now:
        <?= e(implode(', ', $exposure)) ?>
    </div>
<?php endif; ?>

<?php if ($maintenance): ?>
    <div class="alert alert-warning">🛠️ Maintenance mode is ON — visitors see the maintenance page.</div>
<?php endif; ?>

<div class="grid grid-2 mb-2">
    <div class="card">
        <h3>Actions</h3>
        <div class="flex-wrap">
            <form method="post" action="<?= e(url('/admin/system/backup')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="kind" value="full">
                <button class="btn btn-sm">💾 Backup now</button>
            </form>
            <form method="post" action="<?= e(url('/admin/system/cache/clear')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost">🧹 Clear cache</button>
            </form>
            <form method="post" action="<?= e(url('/admin/system/maintenance')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost"><?= $maintenance ? '▶ Maintenance OFF' : '⏸ Maintenance ON' ?></button>
            </form>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/update')) ?>">⬆️ Updates</a>
        </div>

        <p class="text-sm text-muted mt-2">Backup directory: <code><?= e($backupDir) ?></code></p>
    </div>

    <div class="card">
        <h3>Server</h3>
        <table class="data">
            <tbody>
            <?php foreach ($phpInfo as $label => $value): ?>
                <tr><th><?= e((string) $label) ?></th><td style="white-space:normal"><?= e((string) $value) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>Backups</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>File</th><th>Kind</th><th>Size</th><th>When</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><?= e((string) $backup['filename']) ?></td>
                        <td><?= e((string) $backup['kind']) ?></td>
                        <td><?= e((string) round((int) $backup['size_bytes'] / 1048576, 1)) ?> MB</td>
                        <td><?= e(human_diff((string) $backup['created_at'])) ?></td>
                        <td class="flex">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/system/backup/' . (int) $backup['id'] . '/download')) ?>">⬇</a>
                            <form method="post" action="<?= e(url('/admin/system/restore/' . (int) $backup['id'])) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <button class="btn btn-sm btn-danger" data-action="confirm" data-message="Restore the database from this backup? Current data will be replaced.">Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Database size</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead>
                <tbody>
                <?php foreach ($tableSizes as $table): ?>
                    <tr><td><?= e((string) $table['name']) ?></td><td><?= number_format((int) $table['rows_estimate']) ?></td><td><?= e((string) $table['size_mb']) ?> MB</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
