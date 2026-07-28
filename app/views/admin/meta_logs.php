<?php /** @var array $calls */ /** @var array $events */ /** @var int $page */ /** @var int $pages */
/** @var bool $errorsOnly */ /** @var int $unprocessed */ /** @var int $unsigned */ ?>

<div class="grid grid-2 mb-2">
    <div class="stat-card<?= $unprocessed > 0 ? ' red' : '' ?>">
        <span class="value"><?= (int) $unprocessed ?></span>
        <span class="label">Webhook events not processed</span>
    </div>
    <div class="stat-card<?= $unsigned > 0 ? ' red' : '' ?>">
        <span class="value"><?= (int) $unsigned ?></span>
        <span class="label">Events with an unverified signature</span>
    </div>
</div>

<?php if ($unsigned > 0): ?>
    <div class="alert warn">
        Some webhooks arrived without a verified <code>X-Hub-Signature-256</code>. Meta signs
        every body with the <strong>app secret</strong> — not the verify token. Set the App
        Secret in <a href="<?= e(url('/admin/meta')) ?>">Admin → WhatsApp Business Platform</a>
        so nobody else can post events to this URL.
    </div>
<?php endif; ?>

<div class="card mb-2">
    <div class="flex-between">
        <h3>Graph API calls</h3>
        <div class="text-sm">
            <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/meta/logs')) ?>">All</a>
            <a class="btn btn-sm<?= $errorsOnly ? '' : ' btn-outline' ?>" href="<?= e(url('/admin/meta/logs?errors=1')) ?>">Errors only</a>
        </div>
    </div>

    <p class="text-sm text-muted">Access tokens are stripped before anything is written here.</p>

    <?php if ($calls === []): ?>
        <p class="text-muted text-sm">No calls recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>When</th><th>Call</th><th>HTTP</th><th>Error</th><th>Time</th><th>Response</th></tr></thead>
                <tbody>
                <?php foreach ($calls as $call): ?>
                    <tr>
                        <td class="text-sm text-muted"><?= e(to_user_time((string) $call['created_at'], 'd M, H:i:s')) ?></td>
                        <td class="text-sm"><code><?= e((string) $call['method']) ?> <?= e(str_limit((string) $call['endpoint'], 60)) ?></code></td>
                        <td>
                            <span class="badge badge-<?= (int) $call['http_code'] >= 400 || (int) $call['http_code'] === 0 ? 'danger' : 'success' ?>">
                                <?= (int) $call['http_code'] ?>
                            </span>
                        </td>
                        <td class="text-sm"><?= $call['error_code'] !== null ? (int) $call['error_code'] : '—' ?></td>
                        <td class="text-sm text-muted"><?= (int) $call['latency_ms'] ?> ms</td>
                        <td class="text-sm text-muted"><?= e(str_limit((string) $call['response'], 90)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pager">
                <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                    <a class="pager-link<?= $p === $page ? ' active' : '' ?>"
                       href="?page=<?= $p ?><?= $errorsOnly ? '&errors=1' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Webhook events</h3>
    <p class="text-sm text-muted">
        Stored raw before they are processed, so a bad deploy can be replayed instead of
        losing the delivery receipts and billing records that arrived during it.
    </p>

    <?php if ($events === []): ?>
        <p class="text-muted text-sm">
            Nothing received yet. If messages are being sent but no receipts arrive, our app
            is probably not subscribed to the WABA — press <strong>Subscribe</strong> on the
            accounts page.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>When</th><th>Field</th><th>Key</th><th>Signed</th><th>Processed</th><th>Error</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td class="text-sm text-muted"><?= e(to_user_time((string) $event['received_at'], 'd M, H:i:s')) ?></td>
                        <td class="text-sm"><?= e((string) $event['field']) ?></td>
                        <td class="text-sm text-muted"><?= e(str_limit((string) ($event['event_key'] ?? ''), 40)) ?></td>
                        <td><?= (int) $event['signature_valid'] === 1 ? '🔒' : '<span style="color:var(--danger)">✕</span>' ?></td>
                        <td><?= (int) $event['processed'] === 1 ? '✅' : '⏳' ?></td>
                        <td class="text-sm" style="color:var(--danger)"><?= e(str_limit((string) ($event['error'] ?? ''), 70)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
