<?php /** @var \App\Core\Settings $settings */ /** @var string $webhook */ /** @var array $queue */ /** @var array $log */
/** @var array $inbound */ /** @var array $unknown */ /** @var array $stats */ ?>
<div class="grid grid-3 mb-2">
    <div class="stat-card"><span class="value"><?= (int) $stats['queued'] ?></span><span class="label">Queued</span></div>
    <div class="stat-card green"><span class="value"><?= (int) $stats['sent24'] ?></span><span class="label">Sent (24h)</span></div>
    <div class="stat-card red"><span class="value"><?= (int) $stats['failed'] ?></span><span class="label">Failed</span></div>
</div>

<div class="grid grid-2 mb-2">
    <form class="card" method="post" action="<?= e(url('/admin/whatsapp')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Gateway settings</h3>

        <div class="field"><label>Endpoint <span class="hint">without /api.php</span>
            <input name="wa_endpoint" value="<?= e((string) $settings->get('wa_endpoint', '')) ?>"></label></div>
        <div class="field"><label>API key <span class="hint">leave blank to keep the stored key</span>
            <input name="wa_api_key" type="password" placeholder="<?= $settings->get('wa_api_key') ? '•••••••• stored' : 'not set' ?>"></label></div>
        <div class="field"><label>Session id <span class="hint">leave blank to keep</span>
            <input name="wa_session_id" type="password" placeholder="<?= $settings->get('wa_session_id') ? '•••••••• stored' : 'not set' ?>"></label></div>
        <div class="field"><label>Sender number<input name="wa_sender_number" value="<?= e((string) $settings->get('wa_sender_number', '')) ?>"></label></div>

        <div class="grid grid-2">
            <div class="field"><label>Rate / minute<input name="wa_rate_per_minute" type="number" min="1" max="120" value="<?= (int) $settings->int('wa_rate_per_minute', 30) ?>"></label></div>
            <div class="field"><label>Max retries<input name="wa_max_retries" type="number" min="1" max="10" value="<?= (int) $settings->int('wa_max_retries', 3) ?>"></label></div>
        </div>

        <label class="check"><input type="checkbox" name="wa_enabled" value="1"<?= $settings->bool('wa_enabled', true) ? ' checked' : '' ?>> Sending enabled</label>
        <label class="check"><input type="checkbox" name="wa_invite_unknown" value="1"<?= $settings->bool('wa_invite_unknown', true) ? ' checked' : '' ?>> Invite unknown numbers (once per window)</label>
        <div class="field"><label>Invite cooldown (days)<input name="wa_invite_cooldown_days" type="number" min="1" value="<?= (int) $settings->int('wa_invite_cooldown_days', 7) ?>"></label></div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <div>
        <div class="card mb-2">
            <h3>Inbound webhook</h3>
            <p class="text-sm text-muted">Point the gateway's outbound webhook here:</p>
            <div class="copy-box">
                <span class="grow"><?= e($webhook) ?></span>
                <button class="btn btn-sm" data-action="copy" data-value="<?= e($webhook) ?>"><?= t('common.copy') ?></button>
            </div>
        </div>

        <form class="card" method="post" action="<?= e(url('/admin/whatsapp/test')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <h3>Test send</h3>
            <div class="field"><label>Number<input name="number" value="<?= e((string) $settings->get('alert_admin_number', '')) ?>"></label></div>
            <div class="field"><label>Message<textarea name="message">🙏 Krishna Reminder test message</textarea></label></div>
            <button class="btn">Send now</button>
        </form>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>Outbound log</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Time</th><th>To</th><th>Message</th><th>OK</th></tr></thead>
                <tbody>
                <?php foreach ($log as $row): ?>
                    <tr>
                        <td><?= e(to_user_time((string) $row['created_at'], 'd M H:i')) ?></td>
                        <td><?= e(display_phone((string) $row['to_number'])) ?></td>
                        <td style="white-space:normal;max-width:280px"><?= e(str_limit((string) $row['message'], 80)) ?></td>
                        <td><span class="badge badge-<?= (int) $row['success'] === 1 ? 'success' : 'danger' ?>"><?= (int) $row['http_code'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Unknown senders</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Number</th><th>Hits</th><th>Last seen</th><th>Invited</th></tr></thead>
                <tbody>
                <?php foreach ($unknown as $row): ?>
                    <tr>
                        <td><?= e(display_phone((string) $row['from_number'])) ?></td>
                        <td><?= (int) $row['hits'] ?></td>
                        <td><?= e(human_diff((string) $row['last_seen_at'])) ?></td>
                        <td><?= $row['invite_sent_at'] ? '✅' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
