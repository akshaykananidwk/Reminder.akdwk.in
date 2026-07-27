<?php
/**
 * Pager + "clear this list" control, shared by the admin message and log lists.
 *
 * Expects $list from ConfigController::page():
 *   rows, page, pages, total, param, table
 *
 * The page number travels in its own query parameter per list, so paging the
 * log does not also move the inbox — and every other parameter already on the
 * URL is carried through rather than dropped.
 *
 * @var array $list
 * @var string $csrf
 */

$page  = (int) ($list['page'] ?? 1);
$pages = (int) ($list['pages'] ?? 1);
$total = (int) ($list['total'] ?? 0);
$param = (string) ($list['param'] ?? 'page');
$table = (string) ($list['table'] ?? '');

/** Keep the rest of the query string intact when changing this list's page. */
$pageUrl = static function (int $target) use ($param): string {
    $query = $_GET;
    $query[$param] = $target;

    return '?' . http_build_query($query);
};
?>
<div class="pager">
    <div class="pager-info">
        <?php if ($total === 0): ?>
            <span class="text-sm text-muted">Nothing here yet.</span>
        <?php else: ?>
            <span class="text-sm text-muted">
                Page <?= $page ?> of <?= $pages ?> · <?= $total ?> total
            </span>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pager-links">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-outline" href="<?= e($pageUrl(1)) ?>">« First</a>
                <a class="btn btn-sm btn-outline" href="<?= e($pageUrl($page - 1)) ?>">‹ Prev</a>
            <?php endif; ?>

            <?php
            // A short window around the current page — a hundred numbered
            // links is not navigation, it is noise.
            $from = max(1, $page - 2);
            $to = min($pages, $page + 2);

            for ($i = $from; $i <= $to; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="btn btn-sm" aria-current="page"><?= $i ?></span>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline" href="<?= e($pageUrl($i)) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
                <a class="btn btn-sm btn-outline" href="<?= e($pageUrl($page + 1)) ?>">Next ›</a>
                <a class="btn btn-sm btn-outline" href="<?= e($pageUrl($pages)) ?>">Last »</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($total > 0 && $table !== ''): ?>
        <form method="post" action="<?= e(url('/admin/lists/clear')) ?>" class="pager-clear"
              onsubmit="return confirm('Delete all <?= $total ?> row(s)? This cannot be undone.')">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="table" value="<?= e($table) ?>">
            <input name="confirm" placeholder="type <?= e($table) ?>" autocomplete="off" aria-label="Type the table name to confirm">
            <button class="btn btn-sm btn-danger">Clear all</button>
        </form>
    <?php endif; ?>
</div>
