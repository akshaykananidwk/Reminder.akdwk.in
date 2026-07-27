<?php /** @var array|null $google */ /** @var bool $googleReady */ /** @var bool $canGoogle */ /** @var bool $canApi */
/** @var array $apiKeys */ /** @var array $webhooks */ /** @var mixed $newKey */ ?>
<div class="grid grid-2">
    <div class="card">
        <h3>📅 Google Calendar</h3>

        <?php if (!$googleReady): ?>
            <p class="text-sm text-muted">Google integration is not configured on this server. Everything works without it — the database stays the single source of truth.</p>
        <?php elseif (!$canGoogle): ?>
            <p class="text-sm text-muted">Google sync is available on the Pro and Business plans.</p>
            <a class="btn btn-sm" href="<?= e(url('/client/billing')) ?>"><?= t('billing.upgrade') ?></a>
        <?php elseif ($google === null): ?>
            <p class="text-sm text-muted">Two-way sync: reminders become Google events, and events created in Google become reminders.</p>
            <a class="btn" href="<?= e(url('/auth/google')) ?>">Connect Google</a>
        <?php else: ?>
            <p class="text-sm">Connected as <strong><?= e((string) $google['email']) ?></strong></p>
            <p class="text-xs text-muted">Last sync: <?= $google['last_sync_at'] ? e(human_diff((string) $google['last_sync_at'])) : 'not yet' ?></p>
            <form method="post" action="<?= e(url('/client/integrations/google/disconnect')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <label class="check"><input type="checkbox" name="delete_events" value="1"> Also delete the synced events from Google</label>
                <button class="btn btn-ghost btn-sm" data-action="confirm" data-message="<?= t('common.confirm') ?>">Disconnect</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>🔑 API &amp; webhooks</h3>

        <?php if (!$canApi): ?>
            <p class="text-sm text-muted">API access is part of the Business plan.</p>
            <a class="btn btn-sm" href="<?= e(url('/client/billing')) ?>"><?= t('billing.upgrade') ?></a>
        <?php else: ?>
            <?php if (is_string($newKey) && $newKey !== ''): ?>
                <div class="alert alert-success">
                    <strong>Copy this key now — it is shown only once.</strong>
                    <div class="copy-box mt-1">
                        <code class="grow"><?= e($newKey) ?></code>
                        <button class="btn btn-sm" data-action="copy" data-value="<?= e($newKey) ?>"><?= t('common.copy') ?></button>
                    </div>
                </div>
            <?php endif; ?>

            <ul class="list">
                <?php foreach ($apiKeys as $key): ?>
                    <li class="list-item">
                        <div class="body">
                            <div class="title text-sm"><?= e((string) $key['name']) ?></div>
                            <div class="meta"><code><?= e((string) $key['key_prefix']) ?>…</code> · <?= e((string) $key['scopes']) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post" action="<?= e(url('/client/integrations/api-key')) ?>" class="flex mt-1">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input class="grow" name="name" placeholder="Key name" maxlength="120">
                <button class="btn btn-sm">Generate</button>
            </form>

            <h3 class="mt-3">Webhooks</h3>
            <ul class="list">
                <?php foreach ($webhooks as $hook): ?>
                    <li class="list-item">
                        <div class="body">
                            <div class="title text-sm"><?= e(str_limit((string) $hook['url'], 46)) ?></div>
                            <div class="meta"><?= e((string) $hook['events']) ?> · last <?= e((string) ($hook['last_status'] ?: '—')) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post" action="<?= e(url('/client/integrations/webhook')) ?>" class="mt-1">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="field"><label for="w-url">HTTPS endpoint</label><input id="w-url" name="url" type="url" placeholder="https://example.com/hook" required></div>
                <div class="field"><label for="w-events">Events</label><input id="w-events" name="events" value="reminder.created,reminder.due,reminder.done,reminder.missed"></div>
                <button class="btn btn-sm"><?= t('common.save') ?></button>
                <span class="hint">Each call carries an <code>X-Krishna-Signature</code> HMAC-SHA256 header.</span>
            </form>
        <?php endif; ?>
    </div>
</div>
