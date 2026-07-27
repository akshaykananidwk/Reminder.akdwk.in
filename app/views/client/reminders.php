<?php
/** @var array $rows */ /** @var string $filter */ /** @var string $search */
/** @var int $total */ /** @var array $page */ /** @var string $tz */
$filters = ['upcoming','today','tomorrow','week','overdue','completed','cancelled'];
$query = static fn (array $extra = []): string => '?' . http_build_query(array_merge(
    ['filter' => $filter, 'q' => $search, 'type' => $type, 'priority' => $priority], $extra
));
?>
<form class="card mb-2" method="get" action="<?= e(url('/client/reminders')) ?>">
    <div class="flex">
        <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="<?= t('common.search') ?>…">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <button class="btn btn-sm">🔍</button>
    </div>

    <div class="chips mt-2">
        <?php foreach ($filters as $key): ?>
            <a class="chip <?= $filter === $key ? 'active' : '' ?>" href="<?= e(url('/client/reminders') . $query(['filter' => $key])) ?>">
                <?= e(__('status.' . $key) !== 'status.' . $key ? __('status.' . $key) : ucfirst($key)) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="flex mt-2">
        <select name="type" onchange="this.form.submit()">
            <option value=""><?= t('common.all') ?> — <?= t('reminder.type') ?></option>
            <?php foreach (['task','payment','call','meeting','medicine','birthday','bill','other'] as $option): ?>
                <option value="<?= e($option) ?>"<?= $type === $option ? ' selected' : '' ?>><?= t('types.' . $option) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority" onchange="this.form.submit()">
            <option value=""><?= t('common.all') ?> — <?= t('reminder.priority') ?></option>
            <?php foreach (['low','normal','high','urgent'] as $option): ?>
                <option value="<?= e($option) ?>"<?= $priority === $option ? ' selected' : '' ?>><?= t('priority.' . $option) ?></option>
            <?php endforeach; ?>
        </select>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/export/csv')) ?>">⬇ CSV</a>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/export/ics')) ?>">📅 ICS</a>
    </div>
</form>

<form id="bulk-form" method="post" action="<?= e(url('/client/reminders/bulk')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="card">
        <div class="card-head">
            <h3><?= (int) $total ?> <?= t('nav.reminders') ?></h3>
            <a class="btn btn-sm" href="<?= e(url('/client/reminders/create')) ?>">+ <?= t('common.add') ?></a>
        </div>

        <?php if ($rows === []): ?>
            <div class="empty">
                <div class="icon">🔔</div>
                <h3><?= t('reminder.no_reminders') ?></h3>
                <a class="btn mt-2" href="<?= e(url('/client/reminders/create')) ?>">+ <?= t('common.add') ?></a>
            </div>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($rows as $row): ?>
                    <?php \App\Core\View::partial('client/_occurrence_row', ['row' => $row, 'tz' => $tz, 'csrf' => $csrf, 'bulk' => true]); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div id="bulk-bar" class="card mt-2 hide">
        <div class="flex-between">
            <span><strong id="bulk-count">0</strong> <?= t('common.select') ?></span>
            <div class="flex">
                <button class="btn btn-sm btn-green" name="bulk_action" value="done">✓ <?= t('reminder.mark_done') ?></button>
                <button class="btn btn-sm btn-ghost" name="bulk_action" value="snooze">⏰ +30m</button>
                <button class="btn btn-sm btn-ghost" name="bulk_action" value="cancel">✖</button>
                <button class="btn btn-sm btn-danger" name="bulk_action" value="delete"
                        data-action="confirm" data-message="<?= t('reminder.confirm_delete') ?>">🗑</button>
            </div>
        </div>
        <input type="hidden" name="minutes" value="30">
    </div>
</form>

<?php
$pages = (int) ceil($total / max(1, (int) $page['perPage']));
if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($p = max(1, (int) $page['page'] - 2); $p <= min($pages, (int) $page['page'] + 2); $p++): ?>
            <?php if ($p === (int) $page['page']): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e(url('/client/reminders') . $query(['page' => $p])) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<p class="text-center text-sm mt-2"><a href="<?= e(url('/client/trash')) ?>">🗑 <?= t('nav.trash') ?></a></p>
