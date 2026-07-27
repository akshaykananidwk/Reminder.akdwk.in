<?php
/** @var array $report */ /** @var string $range */ /** @var string $from */ /** @var string $to */
/** @var bool $fullReports */ /** @var array $summaries */
$totals = $report['totals'];
$maxDaily = 1;
foreach ($report['daily'] as $day) { $maxDaily = max($maxDaily, (int) $day['total']); }
?>
<form class="card mb-2" method="get">
    <div class="chips">
        <?php foreach (['today' => __('common.today'), 'week' => __('reports.weekly'), 'month' => __('reports.monthly'), 'year' => 'Year'] as $key => $label): ?>
            <a class="chip <?= $range === $key ? 'active' : '' ?>" href="?range=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="flex mt-2">
        <input type="hidden" name="range" value="custom">
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <button class="btn btn-sm"><?= t('common.apply') ?></button>
    </div>
</form>

<div class="grid grid-4 mb-2">
    <div class="stat-card"><span class="value"><?= (int) $totals['total'] ?></span><span class="label"><?= t('common.all') ?></span></div>
    <div class="stat-card green"><span class="value"><?= (int) $totals['done'] ?></span><span class="label"><?= t('status.done') ?></span></div>
    <div class="stat-card red"><span class="value"><?= (int) $totals['missed'] ?></span><span class="label"><?= t('status.missed') ?></span></div>
    <div class="stat-card gold"><span class="value"><?= (int) $totals['rate'] ?>%</span><span class="label"><?= t('reports.success_rate') ?></span></div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <h3><?= t('reports.completed_vs_missed') ?></h3>
        <?php if ($report['daily'] === []): ?>
            <p class="text-sm text-muted"><?= t('reports.no_data') ?></p>
        <?php else: ?>
            <div class="bars">
                <?php foreach ($report['daily'] as $day): ?>
                    <div class="bar green" style="height:<?= (int) max(3, ((int) $day['done'] / $maxDaily) * 100) ?>%"
                         title="<?= e((string) $day['day']) ?>: <?= (int) $day['done'] ?>/<?= (int) $day['total'] ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="bars-labels">
                <?php foreach ($report['daily'] as $day): ?>
                    <span><?= e(substr((string) $day['day'], 8)) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?= t('reports.by_category') ?></h3>
        <?php if ($report['by_category'] === []): ?>
            <p class="text-sm text-muted"><?= t('reports.no_data') ?></p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($report['by_category'] as $category):
                    $label = match (\App\Core\Lang::locale()) {
                        'gu' => $category['name_gu'], 'hi' => $category['name_hi'], default => $category['name_en'],
                    };
                    $pct = (int) $category['total'] > 0 ? (int) round(((int) $category['done'] / (int) $category['total']) * 100) : 0;
                ?>
                    <li class="list-item">
                        <div class="body">
                            <div class="title text-sm"><?= e($label ?: $category['code']) ?></div>
                            <div class="progress mt-1"><i style="width:<?= $pct ?>%"></i></div>
                        </div>
                        <span class="text-sm text-muted"><?= (int) $category['done'] ?>/<?= (int) $category['total'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-3 mb-2">
    <div class="card"><h3><?= t('reports.avg_delay') ?></h3><div class="value" style="font-size:1.6rem;font-weight:800"><?= e((string) $report['avg_delay_minutes']) ?> min</div></div>
    <div class="card"><h3><?= t('reports.best_hour') ?></h3><div class="value" style="font-size:1.6rem;font-weight:800"><?= $report['best_hour'] === null ? '—' : e(sprintf('%02d:00', (int) $report['best_hour'])) ?></div></div>
    <div class="card"><h3><?= t('reports.worst_hour') ?></h3><div class="value" style="font-size:1.6rem;font-weight:800"><?= $report['worst_hour'] === null ? '—' : e(sprintf('%02d:00', (int) $report['worst_hour'])) ?></div></div>
</div>

<div class="card mb-2">
    <div class="card-head">
        <h3><?= t('common.export') ?></h3>
        <div class="flex">
            <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/reports/export/csv?from=' . $from . '&to=' . $to)) ?>">⬇ CSV</a>
            <a class="btn btn-sm btn-ghost" target="_blank" href="<?= e(url('/client/reports/export/pdf?from=' . $from . '&to=' . $to)) ?>">🖨 PDF</a>
        </div>
    </div>
    <?php if (!$fullReports): ?>
        <p class="text-sm text-muted mb-0">Basic reports are included in your plan. Upgrade to Pro for the full breakdown and history.</p>
    <?php endif; ?>
</div>

<?php if ($summaries !== []): ?>
    <div class="card">
        <h3><?= t('summary.night_title') ?></h3>
        <ul class="list">
            <?php foreach ($summaries as $summary): ?>
                <li class="list-item">
                    <span class="time"><?= e(date('d M', strtotime((string) $summary['summary_date']))) ?></span>
                    <div class="body">
                        <div class="text-sm">✅ <?= (int) $summary['done_count'] ?> · ⏳ <?= (int) $summary['pending_count'] ?> · ❌ <?= (int) $summary['missed_count'] ?>
                            <?php if ((float) $summary['paid_amount'] > 0): ?> · 💰 <?= e(money((float) $summary['paid_amount'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="badge badge-secondary"><?= e((string) $summary['kind']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
