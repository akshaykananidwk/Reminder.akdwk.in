<?php /** @var array $users */ /** @var int $total */ /** @var array $plans */ /** @var array $page */ ?>
<form class="card mb-2" method="get">
    <div class="flex">
        <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="Name, phone, email, city…">
        <select name="status" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="suspended"<?= $status === 'suspended' ? ' selected' : '' ?>>Suspended</option>
            <option value="expired"<?= $status === 'expired' ? ' selected' : '' ?>>Expired</option>
        </select>
        <select name="plan" onchange="this.form.submit()">
            <option value="0">All plans</option>
            <?php foreach ($plans as $plan): ?>
                <option value="<?= (int) $plan['id'] ?>"<?= $planId === (int) $plan['id'] ? ' selected' : '' ?>><?= e((string) $plan['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm">🔍</button>
    </div>
</form>

<div class="card">
    <div class="card-head"><h3><?= number_format($total) ?> users</h3></div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Name</th><th>Phone</th><th>Plan</th><th>Expires</th><th>Reminders</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('/admin/users/' . (int) $user['id'])) ?>"><?= e((string) $user['name']) ?></a>
                        <?php if ((int) $user['is_active'] !== 1): ?><span class="badge badge-danger">suspended</span><?php endif; ?>
                        <?php if ((int) $user['is_verified'] !== 1): ?><span class="badge badge-warning">unverified</span><?php endif; ?>
                    </td>
                    <td><?= e(display_phone((string) $user['phone'])) ?></td>
                    <td><?= e((string) ($user['plan_name'] ?? '—')) ?></td>
                    <td><?= $user['plan_expires_at'] ? e(to_user_time((string) $user['plan_expires_at'], 'd M Y')) : '—' ?></td>
                    <td><?= (int) $user['reminder_count'] ?></td>
                    <td><?= e(to_user_time((string) $user['created_at'], 'd M Y')) ?></td>
                    <td><a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/users/' . (int) $user['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $pages = (int) ceil($total / max(1, (int) $page['perPage'])); if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($p = max(1, (int) $page['page'] - 3); $p <= min($pages, (int) $page['page'] + 3); $p++): ?>
            <?php if ($p === (int) $page['page']): ?><span class="current"><?= $p ?></span>
            <?php else: ?><a href="?<?= e(http_build_query(['q' => $search, 'status' => $status, 'plan' => $planId, 'page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
<?php endif; ?>
