<?php /** @var array $status */ /** @var array $stale */ /** @var array $runs */ /** @var string $cronToken */ /** @var string $root */ ?>
<?php if ($stale !== []): ?>
    <div class="alert alert-danger">⚠️ Not running: <?= e(implode(', ', array_map(static fn ($j) => (string) $j['job'], $stale))) ?></div>
<?php endif; ?>

<div class="card mb-2">
    <h3>Jobs</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Job</th><th>Last run</th><th>Duration</th><th>Rows</th><th>Status</th><th>Message</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($status as $job): ?>
                <tr>
                    <td><strong><?= e((string) $job['job']) ?></strong></td>
                    <td><?= $job['last_run'] ? e(human_diff((string) $job['last_run'])) : '—' ?></td>
                    <td><?= $job['duration_ms'] !== null ? (int) $job['duration_ms'] . ' ms' : '—' ?></td>
                    <td><?= (int) $job['rows'] ?></td>
                    <td><span class="badge badge-<?= e(status_badge((string) $job['status'])) ?>"><?= e((string) $job['status']) ?></span></td>
                    <td style="white-space:normal;max-width:280px"><?= e(str_limit((string) $job['message'], 90)) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/cron/run')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="job" value="<?= e((string) $job['job']) ?>">
                            <button class="btn btn-sm btn-ghost">▶ Run now</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-2">
    <h3>Crontab</h3>
    <pre class="code" style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:12px;overflow-x:auto;font-size:12.5px">* * * * * /usr/bin/php <?= e($root) ?>/cron/dispatcher.php >/dev/null 2>&amp;1
* * * * * /usr/bin/php <?= e($root) ?>/cron/ai_queue.php >/dev/null 2>&amp;1
* * * * * /usr/bin/php <?= e($root) ?>/cron/wa_queue.php >/dev/null 2>&amp;1
*/5 * * * * /usr/bin/php <?= e($root) ?>/cron/morning_brief.php >/dev/null 2>&amp;1
*/5 * * * * /usr/bin/php <?= e($root) ?>/cron/daily_summary.php >/dev/null 2>&amp;1
*/15 * * * * /usr/bin/php <?= e($root) ?>/cron/google_sync.php >/dev/null 2>&amp;1
0 * * * * /usr/bin/php <?= e($root) ?>/cron/recurrence.php >/dev/null 2>&amp;1
0 9 * * * /usr/bin/php <?= e($root) ?>/cron/subscriptions.php >/dev/null 2>&amp;1
0 3 * * * /usr/bin/php <?= e($root) ?>/cron/backup.php >/dev/null 2>&amp;1
0 4 * * 0 /usr/bin/php <?= e($root) ?>/cron/cleanup.php >/dev/null 2>&amp;1</pre>

    <p class="text-sm text-muted mt-2">Web-cron fallback (one URL, once a minute):</p>
    <div class="copy-box">
        <span class="grow"><?= e(url('/cron.php?job=all&token=' . $cronToken)) ?></span>
        <button class="btn btn-sm" data-action="copy" data-value="<?= e(url('/cron.php?job=all&token=' . $cronToken)) ?>"><?= t('common.copy') ?></button>
    </div>
</div>

<div class="card">
    <h3>Recent runs</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Job</th><th>Started</th><th>Duration</th><th>Rows</th><th>Status</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= e((string) $run['job']) ?></td>
                    <td><?= e(to_user_time((string) $run['started_at'], 'd M H:i:s')) ?></td>
                    <td><?= (int) $run['duration_ms'] ?> ms</td>
                    <td><?= (int) $run['rows_processed'] ?></td>
                    <td><span class="badge badge-<?= e(status_badge((string) $run['status'])) ?>"><?= e((string) $run['status']) ?></span></td>
                    <td style="white-space:normal;max-width:320px"><?= e(str_limit((string) $run['message'], 100)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
