<?php /** @var array $stats */ /** @var array $aiSeries */ /** @var array $cron */ /** @var array $stale */
/** @var array $recentUsers */ /** @var array $expiring */ /** @var array $errors */
$maxCost = 0.000001;
foreach ($aiSeries as $day) { $maxCost = max($maxCost, (float) $day['cost']); }
?>
<?php if ($stale !== []): ?>
    <div class="alert alert-danger">
        ⚠️ Cron jobs not running: <strong><?= e(implode(', ', array_map(static fn ($job) => (string) $job['job'], $stale))) ?></strong>
        — <a href="<?= e(url('/admin/cron')) ?>">open the cron monitor</a>.
    </div>
<?php endif; ?>

<div class="grid grid-4 mb-2">
    <div class="stat-card"><span class="value"><?= number_format((int) $stats['users_total']) ?></span><span class="label">Users</span></div>
    <div class="stat-card green"><span class="value"><?= number_format((int) $stats['users_active_today']) ?></span><span class="label">Active today</span></div>
    <div class="stat-card gold"><span class="value"><?= number_format((int) $stats['users_new_week']) ?></span><span class="label">New this week</span></div>
    <div class="stat-card"><span class="value"><?= number_format((int) $stats['reminders_total']) ?></span><span class="label">Reminders</span></div>
</div>

<div class="grid grid-4 mb-2">
    <div class="stat-card green"><span class="value"><?= number_format((int) $stats['delivered_today']) ?></span><span class="label">Delivered today</span></div>
    <div class="stat-card red"><span class="value"><?= number_format((int) $stats['missed_today']) ?></span><span class="label">Missed today</span></div>
    <div class="stat-card"><span class="value"><?= number_format((int) $stats['wa_sent_today']) ?></span><span class="label">WhatsApp sent</span></div>
    <div class="stat-card gold"><span class="value"><?= e(money((float) $stats['revenue_month'])) ?></span><span class="label">Revenue this month</span></div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="card-head">
            <h3>AI cost — 30 days</h3>
            <span class="text-sm text-muted">
                <?= number_format((int) $stats['ai_tokens_month']) ?> tokens · $<?= number_format((float) $stats['ai_cost_month'], 4) ?>
            </span>
        </div>
        <div class="bars">
            <?php foreach ($aiSeries as $day): ?>
                <div class="bar gold" style="height:<?= (int) max(3, ((float) $day['cost'] / $maxCost) * 100) ?>%"
                     title="<?= e((string) $day['calls']) ?> calls · $<?= e(number_format((float) $day['cost'], 5)) ?>"></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h3>Cron health</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Job</th><th>Last run</th><th>Rows</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($cron as $job): ?>
                    <tr>
                        <td><?= e((string) $job['job']) ?></td>
                        <td><?= $job['last_run'] ? e(human_diff((string) $job['last_run'])) : '—' ?></td>
                        <td><?= (int) $job['rows'] ?></td>
                        <td><span class="badge badge-<?= e(status_badge((string) $job['status'])) ?>"><?= e((string) $job['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h3>Newest users</h3><a class="text-sm" href="<?= e(url('/admin/users')) ?>">All →</a></div>
        <ul class="list">
            <?php foreach ($recentUsers as $user): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><a href="<?= e(url('/admin/users/' . (int) $user['id'])) ?>"><?= e((string) $user['name']) ?></a></div>
                        <div class="meta"><?= e(display_phone((string) $user['phone'])) ?> <?= $user['city'] ? '· ' . e((string) $user['city']) : '' ?></div>
                    </div>
                    <span class="text-xs text-muted"><?= e(human_diff((string) $user['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h3>Expiring in 7 days (<?= (int) $stats['expiring_soon'] ?>)</h3>
        <ul class="list">
            <?php foreach ($expiring as $user): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><a href="<?= e(url('/admin/users/' . (int) $user['id'])) ?>"><?= e((string) $user['name']) ?></a></div>
                        <div class="meta"><?= e((string) $user['plan_name']) ?> · <?= e(to_user_time((string) $user['plan_expires_at'], 'd M Y')) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if ($expiring === []): ?><li class="list-item text-sm text-muted">Nothing expiring.</li><?php endif; ?>
        </ul>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="card mt-2" style="border-left:4px solid var(--red)">
        <div class="card-head"><h3>Recent errors</h3><a class="text-sm" href="<?= e(url('/admin/logs')) ?>">All →</a></div>
        <ul class="list">
            <?php foreach ($errors as $error): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e(str_limit((string) $error['message'], 120)) ?></div>
                        <div class="meta"><?= e((string) $error['file']) ?>:<?= (int) $error['line'] ?> · <?= e(human_diff((string) $error['created_at'])) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
