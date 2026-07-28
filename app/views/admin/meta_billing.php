<?php /** @var array|null $account */ /** @var array $accounts */ /** @var string $month */
/** @var array $summary */ /** @var array $rates */ /** @var array $daily */ /** @var float $markup */ ?>

<?php if ($summary['incomplete_rates']): ?>
    <div class="alert warn">
        <strong>The rate card is empty, so every total below reads as zero.</strong><br>
        <span class="text-sm">
            Meta does not send the price in its webhooks — it sends the pricing category and
            leaves the money to its published rate card. Enter the current rates for your
            country below and the totals become meaningful. Until then this page can tell you
            how many conversations happened, but not what they cost.
        </span>
    </div>
<?php endif; ?>

<div class="card mb-2">
    <form method="get" class="flex-between">
        <div>
            <input type="month" name="month" value="<?= e($month) ?>">
            <?php if (count($accounts) > 1 && $account !== null): ?>
                <select name="account">
                    <?php foreach ($accounts as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"<?= (int) $option['id'] === (int) $account['id'] ? ' selected' : '' ?>>
                            <?= e((string) $option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button class="btn btn-sm">Show</button>
        </div>
        <span class="text-sm text-muted">
            <?= $markup > 0 ? 'Includes a ' . e((string) $markup) . '% markup on Meta&rsquo;s rate.' : 'No markup applied.' ?>
        </span>
    </form>
</div>

<div class="grid grid-3 mb-2">
    <div class="stat-card">
        <span class="value"><?= e(money((float) $summary['total'], (string) $summary['currency'])) ?></span>
        <span class="label">Estimated cost</span>
    </div>
    <div class="stat-card"><span class="value"><?= (int) $summary['conversations'] ?></span><span class="label">Conversations</span></div>
    <div class="stat-card"><span class="value"><?= (int) $summary['messages'] ?></span><span class="label">Messages</span></div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <h3>By category</h3>
        <?php if ($summary['by_category'] === []): ?>
            <p class="text-muted text-sm">No billable conversations this month.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Category</th><th>Conversations</th><th>Cost</th></tr></thead>
                <tbody>
                <?php foreach ($summary['by_category'] as $row): ?>
                    <tr>
                        <td><?= e((string) $row['category']) ?></td>
                        <td><?= (int) $row['conversations'] ?></td>
                        <td><?= e(money((float) $row['total'], (string) ($row['currency'] ?: 'INR'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="text-sm text-muted mt-1">
            Meta bills per 24-hour conversation, not per message. A conversation opened by a
            reminder and continued with ten replies costs once.
        </p>
    </div>

    <div class="card">
        <h3>By day</h3>
        <?php if ($daily === []): ?>
            <p class="text-muted text-sm">Nothing yet this month.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Day</th><th>Conversations</th><th>Cost</th></tr></thead>
                <tbody>
                <?php foreach ($daily as $row): ?>
                    <tr>
                        <td class="text-sm"><?= e((string) $row['day']) ?></td>
                        <td><?= (int) $row['conversations'] ?></td>
                        <td><?= e(money((float) $row['total'], (string) $summary['currency'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<form class="card" method="post" action="<?= e(url('/admin/meta/rates')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <h3>Rate card</h3>
    <p class="text-sm text-muted">
        Meta&rsquo;s published rates, per country and category. These are the numbers every
        total on this page is computed from — they are not fetched from Meta, because Meta
        does not expose them through the API. Check them against Meta&rsquo;s current price
        list when it changes.
    </p>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Country</th><th>Category</th><th>Price per conversation</th><th>Currency</th></tr></thead>
            <tbody>
            <?php foreach ($rates as $rate): ?>
                <tr>
                    <td><?= e((string) $rate['country_code']) ?></td>
                    <td class="text-sm"><?= e((string) $rate['category']) ?></td>
                    <td><input name="price[<?= (int) $rate['id'] ?>]" type="number" step="0.000001" min="0"
                               value="<?= e((string) $rate['price']) ?>" style="max-width:10rem"></td>
                    <td><input name="currency[<?= (int) $rate['id'] ?>]" value="<?= e((string) $rate['currency']) ?>"
                               style="max-width:6rem" maxlength="8"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="btn mt-2">Save rate card</button>
</form>
