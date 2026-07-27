<?php /** @var array $rows */ /** @var array $series */ /** @var string $from */ /** @var int $budget */ /** @var int $used */
$maxCost = 0.000001; foreach ($series as $day) { $maxCost = max($maxCost, (float) $day['cost']); } ?>
<div class="card mb-2">
    <div class="card-head">
        <h3>AI spend since <?= e($from) ?></h3>
        <?php if ($budget > 0): ?>
            <span class="badge badge-<?= $used >= $budget ? 'danger' : 'success' ?>">
                <?= number_format($used) ?> / <?= number_format($budget) ?> tokens
            </span>
        <?php endif; ?>
    </div>

    <div class="bars">
        <?php foreach ($series as $day): ?>
            <div class="bar gold" style="height:<?= (int) max(3, ((float) $day['cost'] / $maxCost) * 100) ?>%"
                 title="<?= (int) $day['calls'] ?> calls · $<?= e(number_format((float) $day['cost'], 5)) ?>"></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3>Cost per user</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>User</th><th>Calls</th><th>Cached</th><th>Tokens</th><th>Cost</th><th>Failures</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <?php if ($row['user_id']): ?>
                            <a href="<?= e(url('/admin/users/' . (int) $row['user_id'])) ?>"><?= e((string) ($row['name'] ?? 'user #' . (int) $row['user_id'])) ?></a>
                        <?php else: ?>system<?php endif; ?>
                    </td>
                    <td><?= (int) $row['calls'] ?></td>
                    <td><?= (int) $row['cached'] ?></td>
                    <td><?= number_format((int) $row['tokens']) ?></td>
                    <td><strong>$<?= number_format((float) $row['cost'], 5) ?></strong></td>
                    <td><?= (int) $row['failures'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
