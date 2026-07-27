<?php
/** @var string $month */ /** @var array $days */ /** @var string $tz */
$current = new DateTime($month . '-01');
$prev = (clone $current)->modify('-1 month')->format('Y-m');
$next = (clone $current)->modify('+1 month')->format('Y-m');
?>
<div class="card">
    <div class="card-head">
        <div class="flex">
            <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/calendar?month=' . $prev)) ?>">←</a>
            <h3 style="margin:0"><?= e(__('months.' . strtolower($current->format('M')))) ?> <?= e($current->format('Y')) ?></h3>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/calendar?month=' . $next)) ?>">→</a>
        </div>
        <a class="btn btn-sm" href="<?= e(url('/client/reminders/create')) ?>">+</a>
    </div>

    <div class="cal-grid">
        <?php foreach (['mo','tu','we','th','fr','sa','su'] as $day): ?>
            <div class="cal-head"><?= e(mb_substr(__('weekday.' . $day), 0, 3)) ?></div>
        <?php endforeach; ?>

        <?php foreach ($days as $day): ?>
            <div class="cal-day<?= $day['in_month'] ? '' : ' other' ?><?= $day['is_today'] ? ' today' : '' ?>"
                 onclick="location.href='<?= e(url('/client/reminders?filter=week')) ?>'">
                <div class="n"><?= (int) $day['day'] ?></div>
                <?php foreach (array_slice($day['items'], 0, 4) as $item): ?>
                    <span class="dot <?= e((string) $item['status'] === 'done' ? 'done' : ((string) $item['status'] === 'missed' ? 'missed' : '')) ?>"
                          title="<?= e((string) $item['title']) ?>"></span>
                <?php endforeach; ?>
                <?php if (count($day['items']) > 4): ?>
                    <div class="text-xs text-muted">+<?= count($day['items']) - 4 ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card mt-2">
    <h3><?= e(__('months.' . strtolower($current->format('M')))) ?> — <?= t('nav.reminders') ?></h3>
    <ul class="list">
        <?php
        $any = false;
        foreach ($days as $day):
            if (!$day['in_month'] || $day['items'] === []) { continue; }
            $any = true;
        ?>
            <li class="list-item">
                <span class="time"><?= (int) $day['day'] ?></span>
                <div class="body">
                    <?php foreach ($day['items'] as $item): ?>
                        <div class="text-sm">
                            <?= e(to_user_time((string) $item['due_at'], 'h:i A', $tz)) ?> —
                            <a href="<?= e(url('/client/reminders?q=' . rawurlencode((string) $item['short_code']))) ?>"><?= e($item['title']) ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </li>
        <?php endforeach; ?>
        <?php if (!$any): ?>
            <div class="empty"><div class="icon">📅</div><h3><?= t('common.no_data') ?></h3></div>
        <?php endif; ?>
    </ul>
</div>
