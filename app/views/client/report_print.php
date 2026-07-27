<?php /** @var array $rows */ /** @var array $report */ /** @var array $user */ /** @var string $from */ /** @var string $to */ ?>
<!doctype html>
<html lang="<?= e(\App\Core\Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Krishna Reminder — Report <?= e($from) ?> to <?= e($to) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>@page{margin:14mm} body{background:#fff}</style>
</head>
<body>
<div class="container" style="max-width:900px;padding:24px 16px">
    <div class="flex-between mb-2">
        <div>
            <h1 style="margin:0">Krishna Reminder</h1>
            <p class="text-sm text-muted mb-0"><?= e((string) $user['name']) ?> · <?= e(display_phone((string) $user['phone'])) ?></p>
        </div>
        <div class="text-right text-sm">
            <strong><?= e($from) ?> → <?= e($to) ?></strong><br>
            <span class="text-muted">Generated <?= e(date('d M Y, h:i A')) ?></span>
        </div>
    </div>

    <div class="grid grid-4 mb-3">
        <div class="stat-card"><span class="value"><?= (int) $report['totals']['total'] ?></span><span class="label">Total</span></div>
        <div class="stat-card green"><span class="value"><?= (int) $report['totals']['done'] ?></span><span class="label">Done</span></div>
        <div class="stat-card red"><span class="value"><?= (int) $report['totals']['missed'] ?></span><span class="label">Missed</span></div>
        <div class="stat-card gold"><span class="value"><?= (int) $report['totals']['rate'] ?>%</span><span class="label">Success</span></div>
    </div>

    <table class="data">
        <thead><tr><th>Code</th><th>Title</th><th>Due</th><th>Status</th><th>Completed</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= e((string) $row['code']) ?></td>
                <td style="white-space:normal"><?= e((string) $row['title']) ?></td>
                <td><?= e((string) $row['due']) ?></td>
                <td><?= e((string) $row['status']) ?></td>
                <td><?= e((string) $row['done_at']) ?></td>
                <td><?= $row['amount'] !== null ? e(money((float) $row['amount'])) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="text-xs text-muted mt-3">AK Computer · Dwarka, Gujarat · 🙏 જય શ્રી કૃષ્ણ</p>
</div>
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
