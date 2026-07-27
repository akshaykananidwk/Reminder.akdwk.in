<?php /** @var array $rows */ ?>
<div class="card">
    <h3><?= t('admin.audit') ?></h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e(to_user_time((string) $row['created_at'], 'd M Y H:i')) ?></td>
                    <td><?= e((string) $row['actor_type']) ?> #<?= (int) $row['actor_id'] ?></td>
                    <td><strong><?= e((string) $row['action']) ?></strong></td>
                    <td><?= e((string) $row['target_type']) ?> <?= $row['target_id'] ? '#' . (int) $row['target_id'] : '' ?></td>
                    <td style="white-space:normal;max-width:300px"><?= e(str_limit((string) $row['details'], 120)) ?></td>
                    <td><?= e((string) $row['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
