<?php /** @var string $link */ /** @var array $referrals */ /** @var array $ledger */ /** @var array $balance */ /** @var float $percent */ ?>
<div class="card mb-2">
    <h3><?= t('referral.your_link') ?></h3>
    <p class="text-sm text-muted">Earn <?= e((string) $percent) ?>% commission on every plan your referrals buy.</p>

    <div class="copy-box">
        <span class="grow"><?= e($link) ?></span>
        <button class="btn btn-sm" data-action="copy" data-value="<?= e($link) ?>" data-copied="<?= t('common.copied') ?>"><?= t('common.copy') ?></button>
    </div>

    <a class="btn btn-green btn-sm mt-2" target="_blank" rel="noopener"
       href="https://wa.me/?text=<?= rawurlencode('કૃષ્ણ રિમાઇન્ડર વાપરી જુઓ — WhatsApp પર લખો, સમય થતાં ફોન વાગે! ' . $link) ?>">
        💬 Share on WhatsApp
    </a>
</div>

<div class="grid grid-3 mb-2">
    <div class="stat-card"><span class="value"><?= count($referrals) ?></span><span class="label"><?= t('referral.signups') ?></span></div>
    <div class="stat-card green"><span class="value"><?= e(money((float) $balance['earned'])) ?></span><span class="label"><?= t('referral.earned') ?></span></div>
    <div class="stat-card gold"><span class="value"><?= e(money((float) $balance['available'])) ?></span><span class="label"><?= t('referral.available') ?></span></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3><?= t('referral.signups') ?></h3>
        <?php if ($referrals === []): ?>
            <div class="empty"><div class="icon">🎁</div><h3><?= t('common.no_data') ?></h3></div>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($referrals as $referral): ?>
                    <li class="list-item">
                        <div class="body">
                            <div class="title text-sm"><?= e((string) $referral['name']) ?></div>
                            <div class="meta"><?= e(to_user_time((string) $referral['joined_at'], 'd M Y')) ?></div>
                        </div>
                        <span class="badge badge-<?= (string) $referral['status'] === 'converted' ? 'success' : 'secondary' ?>"><?= e((string) $referral['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?= t('referral.ledger') ?></h3>
        <ul class="list">
            <?php foreach ($ledger as $entry): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e((string) $entry['type']) ?> — <?= e(money((float) $entry['amount'])) ?></div>
                        <div class="meta"><?= e(to_user_time((string) $entry['created_at'], 'd M Y')) ?> · <?= e((string) $entry['note']) ?></div>
                    </div>
                    <span class="badge badge-<?= e(status_badge((string) $entry['status'])) ?>"><?= e((string) $entry['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <form method="post" action="<?= e(url('/client/referral/payout')) ?>" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn btn-block" <?= (float) $balance['available'] < 500 ? 'disabled' : '' ?>><?= t('referral.request_payout') ?></button>
            <span class="hint">Minimum payout <?= e(money(500)) ?>.</span>
        </form>
    </div>
</div>
