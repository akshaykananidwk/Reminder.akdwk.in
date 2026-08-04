<?php /** @var array $jobs */ /** @var array $health */ /** @var array $runs */
/** @var \App\Core\Settings $settings */ /** @var string|null $lastTick */ /** @var int|null $tickAgo */
/** @var string $timezone */ /** @var string $filter */ /** @var int $runPage */ /** @var int $runPages */
/** @var string $cronToken */ /** @var string $root */

$masterLine = '* * * * * /usr/bin/php ' . $root . '/cron/run.php >/dev/null 2>&1';
$webUrl = url('/cron.php?token=' . $cronToken);

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$groups = [];
foreach ($jobs as $job) {
    $groups[(string) $job['job_group']][] = $job;
}

$badge = static function (string $status): string {
    return match ($status) {
        'ok'                 => 'success',
        'error', 'timeout'   => 'danger',
        'running'            => 'info',
        'skipped', 'disabled'=> 'muted',
        default              => 'warning',
    };
};
?>

<?php /* ---------------------------------------------------------- Master */ ?>

<?php if ($tickAgo === null): ?>
    <div class="alert danger">
        <strong>The master cron has never run.</strong><br>
        <span class="text-sm">
            Nothing scheduled is happening: no reminders, no messages, no summaries.
            Add the single crontab line below on the server.
        </span>
    </div>
<?php elseif ($tickAgo > 5): ?>
    <div class="alert danger">
        <strong>The master cron last ran <?= (int) $tickAgo ?> minutes ago.</strong><br>
        <span class="text-sm">
            It should run every minute. Check that the crontab line below is still present
            and that the PHP binary path is right.
        </span>
    </div>
<?php elseif (!$settings->bool('scheduler_enabled', true)): ?>
    <div class="alert warn">
        <strong>The scheduler is switched off.</strong><br>
        <span class="text-sm">The master cron is running but every job is being skipped.</span>
    </div>
<?php endif; ?>

<div class="grid grid-4 mb-2">
    <div class="stat-card<?= $health['healthy'] ? ' green' : ' red' ?>">
        <span class="value"><?= $health['healthy'] ? 'Healthy' : 'Attention' ?></span>
        <span class="label">Scheduler</span>
    </div>
    <div class="stat-card">
        <span class="value"><?= (int) $health['enabled'] ?>/<?= (int) $health['total'] ?></span>
        <span class="label">Jobs enabled</span>
    </div>
    <div class="stat-card<?= $health['overdue'] !== [] ? ' red' : '' ?>">
        <span class="value"><?= count($health['overdue']) ?></span>
        <span class="label">Overdue</span>
    </div>
    <div class="stat-card<?= $health['failing'] !== [] ? ' red' : '' ?>">
        <span class="value"><?= count($health['failing']) ?></span>
        <span class="label">Failing</span>
    </div>
</div>

<div class="card mb-2">
    <div class="flex-between">
        <div>
            <h3>One cron, everything else from here</h3>
            <p class="text-sm text-muted">
                The server runs a single line every minute. Which jobs exist, when each is due
                and whether it is on, is decided on this page — a new background task never
                needs another crontab entry.
            </p>
        </div>
        <form method="post" action="<?= e(url('/admin/cron/run-due')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn">▶ Run everything due now</button>
        </form>
    </div>

    <div class="copy-box mt-1">
        <span class="grow"><?= e($masterLine) ?></span>
        <button class="btn btn-sm" data-action="copy" data-value="<?= e($masterLine) ?>"><?= t('common.copy') ?></button>
    </div>

    <p class="text-sm text-muted mt-2">
        No PHP-CLI cron on this host? Point an uptime monitor at this URL once a minute instead
        — it does exactly the same work.
    </p>
    <div class="copy-box">
        <span class="grow"><?= e($webUrl) ?></span>
        <button class="btn btn-sm" data-action="copy" data-value="<?= e($webUrl) ?>"><?= t('common.copy') ?></button>
    </div>

    <p class="text-sm text-muted mt-2">
        Last pass: <strong><?= $lastTick === null ? 'never' : e(human_diff($lastTick)) ?></strong>
        · schedules are in <strong><?= e($timezone) ?></strong>, timestamps below are shown in your timezone.
    </p>
</div>

<?php /* ------------------------------------------------------------ Jobs */ ?>

<?php foreach ($groups as $group => $rows): ?>
<div class="card mb-2">
    <h3><?= e(ucfirst($group)) ?></h3>

    <div class="table-wrap">
        <table class="data">
            <thead><tr>
                <th>Job</th><th>Schedule</th><th>Last run</th><th>Next run</th>
                <th>Status</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $job): ?>
                <?php $key = (string) $job['job_key']; $enabled = (int) $job['is_enabled'] === 1; ?>
                <tr<?= $enabled ? '' : ' style="opacity:.55"' ?>>
                    <td style="min-width:210px">
                        <strong><?= e((string) $job['label']) ?></strong>
                        <?php if ((int) $job['is_registered'] !== 1): ?>
                            <span class="badge badge-danger">code missing</span>
                        <?php endif; ?>
                        <?php if ((int) $job['is_heavy'] === 1): ?>
                            <span class="badge badge-muted">heavy</span>
                        <?php endif; ?>
                        <br><span class="text-sm text-muted"><?= e((string) $job['description']) ?></span>
                        <br><code class="text-sm"><?= e($key) ?></code>
                    </td>

                    <td style="min-width:230px">
                        <form method="post" action="<?= e(url('/admin/cron/schedule')) ?>" class="text-sm">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="job" value="<?= e($key) ?>">

                            <select name="schedule_kind" data-cron-kind>
                                <?php foreach (['every' => 'Every', 'daily' => 'Daily at', 'weekly' => 'Weekly on'] as $value => $text): ?>
                                    <option value="<?= $value ?>"<?= (string) $job['schedule_kind'] === $value ? ' selected' : '' ?>><?= $text ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="interval_seconds" data-cron-field="every">
                                <?php foreach ([60 => '1 minute', 300 => '5 minutes', 600 => '10 minutes',
                                                900 => '15 minutes', 1800 => '30 minutes', 3600 => '1 hour',
                                                21600 => '6 hours', 43200 => '12 hours', 86400 => '24 hours'] as $seconds => $text): ?>
                                    <option value="<?= $seconds ?>"<?= (int) $job['interval_seconds'] === $seconds ? ' selected' : '' ?>><?= $text ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="weekday" data-cron-field="weekly">
                                <?php foreach ($days as $index => $day): ?>
                                    <option value="<?= $index ?>"<?= (int) ($job['weekday'] ?? 0) === $index ? ' selected' : '' ?>><?= $day ?></option>
                                <?php endforeach; ?>
                            </select>

                            <input type="time" name="run_at" data-cron-field="daily weekly" value="<?= e(mb_substr((string) ($job['run_at'] ?? '09:00'), 0, 5)) ?>" style="max-width:7rem">

                            <button class="btn btn-sm btn-outline">Save</button>
                        </form>
                        <span class="text-sm text-muted"><?= e((string) $job['schedule_text']) ?></span>
                    </td>

                    <td class="text-sm" style="min-width:150px">
                        <?= $job['last_run_at'] === null ? '—' : e(human_diff((string) $job['last_run_at'])) ?>
                        <?php if ($job['last_duration_ms'] !== null): ?>
                            <br><span class="text-muted"><?= (int) $job['last_duration_ms'] ?> ms</span>
                        <?php endif; ?>
                        <?php if ((int) $job['total_runs'] > 0): ?>
                            <br><span class="text-muted"><?= (int) $job['total_runs'] ?> runs, <?= (int) $job['total_failures'] ?> failed</span>
                        <?php endif; ?>
                    </td>

                    <td class="text-sm" style="min-width:130px">
                        <?php if (!$enabled): ?>
                            <span class="text-muted">—</span>
                        <?php elseif ($job['locked_at'] !== null): ?>
                            <span class="badge badge-info">running</span>
                            <br><span class="text-muted"><?= e((string) $job['locked_by']) ?></span>
                        <?php else: ?>
                            <?= $job['next_run_at'] === null ? 'next pass' : e(to_user_time((string) $job['next_run_at'], 'd M H:i')) ?>
                        <?php endif; ?>
                    </td>

                    <td style="min-width:170px">
                        <span class="badge badge-<?= $badge($enabled ? (string) $job['last_status'] : 'disabled') ?>">
                            <?= e($enabled ? (string) $job['last_status'] : 'disabled') ?>
                        </span>
                        <?php if ((int) $job['consecutive_failures'] > 0): ?>
                            <span class="badge badge-danger"><?= (int) $job['consecutive_failures'] ?>× failed</span>
                        <?php endif; ?>
                        <?php if (!empty($job['last_message'])): ?>
                            <br><span class="text-sm text-muted" style="white-space:normal"><?= e(str_limit((string) $job['last_message'], 110)) ?></span>
                        <?php endif; ?>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        <form method="post" action="<?= e(url('/admin/cron/run')) ?>" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="job" value="<?= e($key) ?>">
                            <button class="btn btn-sm btn-outline">▶ Run</button>
                        </form>

                        <form method="post" action="<?= e(url('/admin/cron/toggle')) ?>" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="job" value="<?= e($key) ?>">
                            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline"><?= $enabled ? 'Disable' : 'Enable' ?></button>
                        </form>

                        <?php if ((int) $job['consecutive_failures'] > 0 || in_array((string) $job['last_status'], ['error', 'timeout'], true)): ?>
                            <form method="post" action="<?= e(url('/admin/cron/retry')) ?>" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="job" value="<?= e($key) ?>">
                                <button class="btn btn-sm">↻ Retry</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($job['locked_at'] !== null): ?>
                            <form method="post" action="<?= e(url('/admin/cron/unlock')) ?>" style="display:inline"
                                  onsubmit="return confirm('Clear the lock? Only do this if you are sure the run has died.')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="job" value="<?= e($key) ?>">
                                <button class="btn btn-sm btn-danger">Unlock</button>
                            </form>
                        <?php endif; ?>

                        <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/cron?job=' . urlencode($key))) ?>">History</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php /* -------------------------------------------------------- Settings */ ?>

<div class="grid grid-2 mb-2">
    <form class="card" method="post" action="<?= e(url('/admin/cron/settings')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Scheduler settings</h3>

        <label class="check">
            <input type="checkbox" name="scheduler_enabled" value="1"<?= $settings->bool('scheduler_enabled', true) ? ' checked' : '' ?>>
            Scheduler enabled — off means the master runs but does nothing
        </label>

        <div class="field"><label>Pass budget (seconds)
            <span class="hint">how long one pass may spend starting jobs; a heavy job is only started in the first half</span>
            <input type="number" name="scheduler_budget_seconds" min="10" max="300"
                   value="<?= e((string) $settings->int('scheduler_budget_seconds', 50)) ?>"></label></div>

        <div class="field"><label>Stale lock after (minutes)
            <span class="hint">a run that dies leaves its lock behind; after this it is cleared automatically</span>
            <input type="number" name="scheduler_lock_stale_minutes" min="5"
                   value="<?= e((string) $settings->int('scheduler_lock_stale_minutes', 30)) ?>"></label></div>

        <div class="field"><label>Keep history for (days)
            <input type="number" name="scheduler_history_days" min="3"
                   value="<?= e((string) $settings->int('scheduler_history_days', 30)) ?>"></label></div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <div class="card">
        <h3>Health</h3>

        <?php if ($health['healthy']): ?>
            <div class="alert success text-sm">Everything is running on time.</div>
        <?php endif; ?>

        <?php if ($health['overdue'] !== []): ?>
            <p class="text-sm"><strong>Overdue</strong></p>
            <ul class="text-sm">
                <?php foreach ($health['overdue'] as $item): ?>
                    <li><?= e((string) $item['label']) ?> —
                        <?= $item['minutes_ago'] === null ? 'never run' : (int) $item['minutes_ago'] . ' min ago' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($health['failing'] !== []): ?>
            <p class="text-sm"><strong>Failing</strong></p>
            <ul class="text-sm">
                <?php foreach ($health['failing'] as $item): ?>
                    <li><?= e((string) $item['label']) ?> — <?= (int) $item['consecutive_failures'] ?>× in a row:
                        <span class="text-muted"><?= e(str_limit((string) $item['last_message'], 90)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($health['locked'] !== []): ?>
            <p class="text-sm"><strong>Currently running</strong></p>
            <ul class="text-sm">
                <?php foreach ($health['locked'] as $item): ?>
                    <li><?= e((string) $item['label']) ?> — since <?= e((string) $item['locked_at']) ?> UTC
                        on <?= e((string) $item['locked_by']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="text-sm text-muted mt-2">
            A job that keeps failing three times in a row, or one that stops reporting, sends a
            WhatsApp alert to the admin number — at most once an hour, so a broken job cannot
            train anyone to ignore the alerts.
        </p>
    </div>
</div>

<?php /* --------------------------------------------------------- History */ ?>

<div class="card">
    <div class="flex-between">
        <h3>Execution history<?= $filter !== '' ? ' — ' . e($filter) : '' ?></h3>
        <?php if ($filter !== ''): ?>
            <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/cron')) ?>">Show all jobs</a>
        <?php endif; ?>
    </div>

    <?php if ($runs === []): ?>
        <p class="text-muted text-sm">Nothing recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr>
                    <th>Job</th><th>Started</th><th>Duration</th><th>Rows</th>
                    <th>Try</th><th>By</th><th>Status</th><th>Message</th>
                </tr></thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                    <tr>
                        <td class="text-sm"><?= e((string) $run['job']) ?></td>
                        <td class="text-sm"><?= e(to_user_time((string) $run['started_at'], 'd M H:i:s')) ?></td>
                        <td class="text-sm"><?= $run['duration_ms'] === null ? '—' : (int) $run['duration_ms'] . ' ms' ?></td>
                        <td class="text-sm"><?= (int) $run['rows_processed'] ?></td>
                        <td class="text-sm"><?= (int) ($run['attempt'] ?? 1) ?></td>
                        <td class="text-sm text-muted"><?= e((string) $run['triggered_by']) ?></td>
                        <td><span class="badge badge-<?= $badge((string) $run['status']) ?>"><?= e((string) $run['status']) ?></span></td>
                        <td style="white-space:normal;max-width:320px" class="text-sm">
                            <?= e(str_limit((string) ($run['message'] ?? ''), 120)) ?>
                            <?php if (!empty($run['error'])): ?>
                                <details><summary class="text-sm text-muted">details</summary>
                                    <pre class="text-sm" style="white-space:pre-wrap"><?= e(str_limit((string) $run['error'], 1500)) ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($runPages > 1): ?>
            <div class="pager">
                <?php for ($p = max(1, $runPage - 3); $p <= min($runPages, $runPage + 3); $p++): ?>
                    <a class="pager-link<?= $p === $runPage ? ' active' : '' ?>"
                       href="?page=<?= $p ?><?= $filter !== '' ? '&job=' . urlencode($filter) : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
/*
 * The schedule editor shows one row of controls per job. Only the ones that
 * belong to the chosen kind are relevant — an interval means nothing for a
 * weekly job — so the rest are hidden rather than left to be misread.
 *
 * Everything still posts, and the server ignores what does not apply, so this
 * is presentation only: with JavaScript off the form is uglier and works
 * exactly the same.
 */
(function () {
    function apply(select) {
        var kind = select.value;

        select.closest('form').querySelectorAll('[data-cron-field]').forEach(function (field) {
            var kinds = field.getAttribute('data-cron-field').split(' ');
            field.style.display = kinds.indexOf(kind) === -1 ? 'none' : '';
        });
    }

    document.querySelectorAll('[data-cron-kind]').forEach(function (select) {
        apply(select);
        select.addEventListener('change', function () { apply(select); });
    });
})();
</script>
