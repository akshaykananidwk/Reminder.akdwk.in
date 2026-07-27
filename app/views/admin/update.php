<?php /** @var \App\Core\Settings $settings */ /** @var array|null $check */ /** @var array $history */ /** @var array $backups */ ?>
<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="card-head">
            <h3>Update status</h3>
            <form method="post" action="<?= e(url('/admin/update/check')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost">🔄 Check now</button>
            </form>
        </div>

        <?php if ($check === null): ?>
            <p class="text-sm text-muted">Automatic checking is off. Press "Check now".</p>
        <?php elseif (!$check['ok']): ?>
            <div class="alert alert-error"><?= e((string) $check['message']) ?></div>
        <?php else: ?>
            <p class="text-sm">
                Installed: <code><?= e((string) $check['current']) ?></code> ·
                Latest: <code><?= e((string) $check['latest']) ?></code>
            </p>

            <?php if ($check['has_update'] && $check['commit'] !== null): ?>
                <div class="alert alert-info">
                    <strong>Update available</strong><br>
                    <?= e(str_limit((string) $check['commit']['message'], 200)) ?><br>
                    <span class="text-xs text-muted">
                        <?= e((string) $check['commit']['author']) ?> ·
                        <?= e(date('d M Y H:i', strtotime((string) $check['commit']['date']))) ?> ·
                        <?= (int) $check['commit']['files'] ?> file(s)
                    </span>
                </div>

                <form method="post" action="<?= e(url('/admin/update/run')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <button class="btn btn-gold btn-block" data-action="confirm"
                            data-message="Run the update now? A full backup is taken first and any failure is rolled back automatically.">
                        ⬆️ Update now
                    </button>
                </form>
            <?php else: ?>
                <div class="alert alert-success">✅ You are on the latest version.</div>
            <?php endif; ?>
        <?php endif; ?>

        <p class="text-xs text-muted mt-2">
            Protected during updates: <code>config/config.php</code>, <code>/uploads</code>, <code>/storage</code>,
            <code>/backups</code>, <code>install.lock</code>, plus anything in <code>.updateignore</code>.
        </p>
    </div>

    <form class="card" method="post" action="<?= e(url('/admin/update/settings')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>GitHub</h3>

        <div class="field"><label>Owner<input name="github_owner" value="<?= e((string) $settings->get('github_owner', '')) ?>"></label></div>
        <div class="field"><label>Repository<input name="github_repo" value="<?= e((string) $settings->get('github_repo', '')) ?>"></label></div>
        <div class="field"><label>Branch<input name="github_branch" value="<?= e((string) $settings->get('github_branch', 'main')) ?>"></label></div>
        <div class="field"><label>Token <span class="hint">encrypted at rest; blank keeps the stored token</span>
            <input name="github_token" type="password" placeholder="<?= $settings->get('github_token') ? '•••••••• stored' : 'not set' ?>"></label></div>
        <div class="field"><label>Backup retention (days)<input name="backup_retention_days" type="number" min="1" value="<?= (int) $settings->int('backup_retention_days', 14) ?>"></label></div>

        <label class="check"><input type="checkbox" name="update_auto_check" value="1"<?= $settings->bool('update_auto_check', true) ? ' checked' : '' ?>> Check automatically (cached 15 min)</label>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>
</div>

<div class="card">
    <h3>Update history</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Started</th><th>From → to</th><th>Status</th><th>Steps</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($history as $entry): ?>
                <tr>
                    <td><?= e(to_user_time((string) $entry['started_at'], 'd M Y H:i')) ?></td>
                    <td><?= e((string) $entry['from_version']) ?> → <?= e((string) $entry['to_version']) ?></td>
                    <td><span class="badge badge-<?= e(status_badge((string) $entry['status'])) ?>"><?= e((string) $entry['status']) ?></span></td>
                    <td style="white-space:normal;max-width:340px">
                        <?php foreach (json_field($entry['steps'], []) as $step): ?>
                            <span class="badge badge-<?= ($step['status'] ?? '') === 'ok' ? 'success' : 'danger' ?>"><?= e((string) ($step['step'] ?? '')) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td style="white-space:normal;max-width:260px"><?= e(str_limit((string) $entry['error'], 120)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
