<?php /** @var \App\Core\Settings $settings */ /** @var string $webhook */ /** @var string $cloudHook */
/** @var array $cloud */ /** @var array $providers */ /** @var array $queue */ /** @var array $log */
/** @var array $inbound */ /** @var array $unknown */ /** @var array $stats */

$primary = (string) $settings->get('wa_provider', 'bulk');
?>
<div class="grid grid-3 mb-2">
    <div class="stat-card"><span class="value"><?= (int) $stats['queued'] ?></span><span class="label">Queued</span></div>
    <div class="stat-card green"><span class="value"><?= (int) $stats['sent24'] ?></span><span class="label">Sent (24h)</span></div>
    <div class="stat-card red"><span class="value"><?= (int) $stats['failed'] ?></span><span class="label">Failed</span></div>
</div>

<div class="card mb-2">
    <h3>Active providers</h3>
    <?php if ($providers === []): ?>
        <div class="alert warn">No WhatsApp provider is configured — nothing can be sent. Fill in either section below.</div>
    <?php else: ?>
        <p class="text-sm text-muted">Tried in this order; the next one is used only if the one before it fails.</p>
        <p>
            <?php foreach ($providers as $i => $p): ?>
                <?php if ($i > 0): ?><span class="text-muted"> → </span><?php endif; ?>
                <span class="badge badge-<?= $i === 0 ? 'success' : 'info' ?>">
                    <?= $p === 'cloud' ? 'Meta Cloud API' : 'bulk.akdwk.in' ?><?= $i === 0 ? ' (primary)' : ' (fallback)' ?>
                </span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</div>

<div class="grid grid-2 mb-2">
    <form class="card" method="post" action="<?= e(url('/admin/whatsapp')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Provider</h3>

        <div class="field"><label>Send through
            <select name="wa_provider">
                <option value="bulk"<?= $primary === 'bulk' ? ' selected' : '' ?>>bulk.akdwk.in gateway</option>
                <option value="cloud"<?= $primary === 'cloud' ? ' selected' : '' ?>>Meta WhatsApp Cloud API</option>
            </select></label></div>
        <label class="check"><input type="checkbox" name="wa_failover" value="1"<?= $settings->bool('wa_failover', true) ? ' checked' : '' ?>> Fall back to the other provider when this one fails</label>

        <h3 class="mt-2">bulk.akdwk.in gateway</h3>

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

        <h3 class="mt-2">Meta WhatsApp Cloud API</h3>
        <div class="alert <?= $cloud['ok'] ? 'success' : ($cloud['level'] === 'error' ? 'danger' : 'warn') ?>">
            <?= e($cloud['message']) ?><?= $cloud['hint'] !== '' ? '<br><span class="text-sm">' . e($cloud['hint']) . '</span>' : '' ?>
        </div>

        <div class="field"><label>Access token <span class="hint">leave blank to keep the stored token</span>
            <input name="wa_cloud_token" type="password" autocomplete="off" placeholder="<?= $settings->get('wa_cloud_token') ? '•••••••• stored' : 'not set' ?>"></label></div>
        <div class="field"><label>Phone number ID <span class="hint">digits only — not the phone number</span>
            <input name="wa_cloud_phone_id" inputmode="numeric" value="<?= e((string) $settings->get('wa_cloud_phone_id', '')) ?>"></label></div>
        <div class="field"><label>WhatsApp Business account ID <span class="hint">optional</span>
            <input name="wa_cloud_business_id" inputmode="numeric" value="<?= e((string) $settings->get('wa_cloud_business_id', '')) ?>"></label></div>
        <div class="field"><label>App secret <span class="hint">signs the webhook — leave blank to keep</span>
            <input name="wa_cloud_app_secret" type="password" autocomplete="off" placeholder="<?= $settings->get('wa_cloud_app_secret') ? '•••••••• stored' : 'not set' ?>"></label></div>

        <div class="grid grid-2">
            <div class="field"><label>Graph version<input name="wa_cloud_api_version" value="<?= e((string) $settings->get('wa_cloud_api_version', 'v23.0')) ?>"></label></div>
            <div class="field"><label>Verify token <span class="hint">generated if blank</span>
                <input name="wa_cloud_verify_token" value="<?= e((string) $settings->get('wa_cloud_verify_token', '')) ?>"></label></div>
        </div>

        <div class="grid grid-2">
            <div class="field"><label>Template name <span class="hint">for sends outside 24h</span>
                <input name="wa_cloud_template_name" value="<?= e((string) $settings->get('wa_cloud_template_name', '')) ?>"></label></div>
            <div class="field"><label>Template language
                <input name="wa_cloud_template_lang" value="<?= e((string) $settings->get('wa_cloud_template_lang', 'gu')) ?>"></label></div>
        </div>

        <div class="alert warn text-sm">
            <strong>The 24-hour rule.</strong> Meta only accepts a free-form message within 24 hours of
            that person's last WhatsApp message to you. A reminder usually falls outside that window, so
            an <strong>approved template</strong> is required — without one, those reminders are rejected
            with error 131047 and never arrive. The bulk gateway has no such limit.
        </div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <div>
        <div class="card mb-2">
            <h3>Inbound webhook — bulk.akdwk.in</h3>
            <p class="text-sm text-muted">Point the gateway's outbound webhook here:</p>
            <div class="copy-box">
                <span class="grow"><?= e($webhook) ?></span>
                <button class="btn btn-sm" data-action="copy" data-value="<?= e($webhook) ?>"><?= t('common.copy') ?></button>
            </div>
        </div>

        <div class="card mb-2">
            <h3>Inbound webhook — Meta Cloud API</h3>
            <p class="text-sm text-muted">
                Meta → your app → WhatsApp → Configuration → Edit. Paste both, click
                <em>Verify and save</em>, then subscribe to the <strong>messages</strong> field.
            </p>

            <p class="text-sm"><strong>Callback URL</strong></p>
            <div class="copy-box">
                <span class="grow"><?= e($cloudHook) ?></span>
                <button class="btn btn-sm" data-action="copy" data-value="<?= e($cloudHook) ?>"><?= t('common.copy') ?></button>
            </div>

            <p class="text-sm mt-1"><strong>Verify token</strong></p>
            <?php $verify = (string) $settings->get('wa_cloud_verify_token', ''); ?>
            <?php if ($verify === ''): ?>
                <p class="text-sm text-muted">Save the settings once and a token is generated for you.</p>
            <?php else: ?>
                <div class="copy-box">
                    <span class="grow"><?= e($verify) ?></span>
                    <button class="btn btn-sm" data-action="copy" data-value="<?= e($verify) ?>"><?= t('common.copy') ?></button>
                </div>
            <?php endif; ?>

            <?php if (trim((string) $settings->get('wa_cloud_app_secret', '')) === ''): ?>
                <div class="alert warn text-sm mt-1">
                    No app secret stored, so incoming Cloud API webhooks cannot be signature-checked.
                    Add it above — Meta → Settings → Basic → App secret.
                </div>
            <?php endif; ?>
        </div>

        <form class="card mb-2" method="post" action="<?= e(url('/admin/whatsapp/test-cloud')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <h3>Test the Cloud API credentials</h3>
            <p class="text-sm text-muted">Reads the number back from Meta. Sends no message and costs nothing.</p>
            <button class="btn btn-outline">Check connection</button>
        </form>

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
                <thead><tr><th>Time</th><th>To</th><th>Via</th><th>Message</th><th>OK</th></tr></thead>
                <tbody>
                <?php foreach ($log as $row): ?>
                    <?php $via = (string) ($row['provider'] ?? 'bulk'); ?>
                    <tr>
                        <td><?= e(to_user_time((string) $row['created_at'], 'd M H:i')) ?></td>
                        <td><?= e(display_phone((string) $row['to_number'])) ?></td>
                        <td><span class="badge badge-<?= str_starts_with($via, 'cloud') ? 'info' : 'muted' ?>"><?= e($via) ?></span></td>
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
