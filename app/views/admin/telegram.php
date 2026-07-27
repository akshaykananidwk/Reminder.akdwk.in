<?php /** @var \App\Core\Settings $settings */ /** @var array $status */ /** @var string $webhook */
/** @var int $linked */ /** @var array $recent */ ?>

<div class="grid grid-2 mb-2">
    <form class="card" method="post" action="<?= e(url('/admin/telegram')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Telegram bot</h3>

        <div class="alert <?= $status['ok'] ? 'success' : ($status['level'] === 'error' ? 'danger' : 'warn') ?>">
            <?= e($status['message']) ?><?= $status['hint'] !== '' ? '<br><span class="text-sm">' . e($status['hint']) . '</span>' : '' ?>
        </div>

        <div class="field"><label>Bot token <span class="hint">leave blank to keep the stored token</span>
            <input name="tg_bot_token" type="password" autocomplete="off"
                   placeholder="<?= $settings->get('tg_bot_token') ? '•••••••• stored' : 'not set' ?>"></label></div>

        <?php $bot = (string) $settings->get('tg_bot_username', ''); ?>
        <?php if ($bot !== ''): ?>
            <p class="text-sm">Bot: <strong>@<?= e($bot) ?></strong> · <?= (int) $linked ?> user(s) linked</p>
        <?php endif; ?>

        <label class="check"><input type="checkbox" name="tg_enabled" value="1"<?= $settings->bool('tg_enabled', false) ? ' checked' : '' ?>> Telegram enabled</label>
        <label class="check"><input type="checkbox" name="tg_send_reminders" value="1"<?= $settings->bool('tg_send_reminders', true) ? ' checked' : '' ?>> Also send reminders to Telegram when the user has linked it</label>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <div>
        <div class="card mb-2">
            <h3>Set up</h3>
            <ol class="text-sm">
                <li>Open <strong>@BotFather</strong> on Telegram and send <code>/newbot</code>.</li>
                <li>Give it a name and a username ending in <code>bot</code>.</li>
                <li>Copy the token it gives you into the box on the left and save.</li>
                <li>Press <strong>Check connection</strong>, then <strong>Register webhook</strong>.</li>
            </ol>

            <p class="text-sm text-muted mt-1">Webhook URL (registered for you):</p>
            <div class="copy-box">
                <span class="grow"><?= e($webhook) ?></span>
                <button class="btn btn-sm" data-action="copy" data-value="<?= e($webhook) ?>"><?= t('common.copy') ?></button>
            </div>

            <div class="alert success text-sm mt-1">
                Telegram has no 24-hour window, no template approval and no per-message
                charge — once a user has pressed Start, the bot can message them at any
                time, for free.
            </div>
        </div>

        <div class="grid grid-2">
            <form method="post" action="<?= e(url('/admin/telegram/test')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-outline btn-block">Check connection</button>
            </form>
            <form method="post" action="<?= e(url('/admin/telegram/webhook')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-outline btn-block">Register webhook</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h3>Recent Telegram sends</h3>
    <?php if ($recent === []): ?>
        <p class="text-sm text-muted">Nothing sent through Telegram yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Time</th><th>Chat</th><th>Message</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= e(to_user_time((string) $row['created_at'], 'd M H:i')) ?></td>
                        <td><?= e((string) $row['to_number']) ?></td>
                        <td style="white-space:normal;max-width:320px"><?= e(str_limit((string) $row['message'], 90)) ?></td>
                        <td>
                            <span class="badge badge-<?= (int) $row['success'] === 1 ? 'success' : 'danger' ?>">
                                <?= (int) $row['success'] === 1 ? 'sent' : 'failed' ?>
                            </span>
                            <?php if ((int) $row['success'] !== 1): ?>
                                <div class="text-sm text-muted"><?= e(str_limit((string) $row['response'], 90)) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
