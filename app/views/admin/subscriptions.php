<?php /** @var array $rows */ /** @var string $status */ ?>
<div class="chips mb-2">
    <?php foreach (['pending','active','expired','cancelled',''] as $key): ?>
        <a class="chip <?= $status === $key ? 'active' : '' ?>" href="?status=<?= e($key) ?>"><?= e($key === '' ? 'All' : ucfirst($key)) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>User</th><th>Plan</th><th>Amount</th><th>Method</th><th>Ref</th><th>Status</th><th>Requested</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="<?= e(url('/admin/users/' . (int) $row['user_id'])) ?>"><?= e((string) $row['user_name']) ?></a><br>
                        <span class="text-xs text-muted"><?= e(display_phone((string) $row['phone'])) ?></span></td>
                    <td><?= e((string) $row['plan_name']) ?></td>
                    <td><?= e(money((float) $row['amount'], (string) $row['currency'])) ?></td>
                    <td><?= e((string) $row['payment_method']) ?></td>
                    <td><?= e((string) $row['payment_ref']) ?></td>
                    <td><span class="badge badge-<?= e(status_badge((string) $row['status'])) ?>"><?= e((string) $row['status']) ?></span></td>
                    <td><?= e(to_user_time((string) $row['created_at'], 'd M Y')) ?></td>
                    <td>
                        <?php if ((string) $row['status'] === 'pending'): ?>
                            <form method="post" action="<?= e(url('/admin/subscriptions/' . (int) $row['id'] . '/approve')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <button class="btn btn-sm btn-green">Approve</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
