<?php /** @var \App\Core\Settings $settings */ /** @var array $signup */ /** @var array $browser */
/** @var array $accounts */ /** @var array $phones */ /** @var string $callback */
/** @var string $verifyToken */ /** @var array $steps */ ?>

<?php if ($steps !== []): ?>
    <div class="card mb-2">
        <h3>Connection result</h3>
        <table class="table">
            <?php foreach ($steps as $step): ?>
                <tr>
                    <td style="width:2rem"><?= $step['ok'] ? '✅' : '⚠️' ?></td>
                    <td><strong><?= e($step['step']) ?></strong></td>
                    <td class="text-sm text-muted"><?= e($step['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<div class="card mb-2">
    <h3>Connected WhatsApp Business Accounts</h3>

    <?php if ($accounts === []): ?>
        <div class="alert warn">
            No WhatsApp Business Account is connected yet, so nothing can be sent.
            Use <strong>Connect WhatsApp</strong> below, or paste a System User token.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>Business</th><th>WABA id</th><th>Numbers</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <?php $list = $phones[(int) $account['id']] ?? []; ?>
                    <tr>
                        <td>
                            <strong><?= e((string) $account['name']) ?></strong>
                            <?php if ($account['owner_user_id'] !== null): ?>
                                <br><span class="badge badge-info">tenant #<?= (int) $account['owner_user_id'] ?></span>
                            <?php endif; ?>
                            <?php if (!empty($account['last_error'])): ?>
                                <br><span class="text-sm" style="color:var(--danger)"><?= e((string) $account['last_error']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><code><?= e((string) $account['waba_id']) ?></code></td>
                        <td class="text-sm">
                            <?php if ($list === []): ?>
                                <span class="badge badge-muted">none synced</span>
                            <?php endif; ?>
                            <?php foreach ($list as $phone): ?>
                                <div>
                                    <?= e((string) ($phone['display_number'] ?: $phone['phone_number_id'])) ?>
                                    <span class="badge badge-<?= $phone['quality_rating'] === 'GREEN' ? 'success' : ($phone['quality_rating'] === 'RED' ? 'danger' : 'muted') ?>">
                                        <?= e((string) $phone['quality_rating']) ?>
                                    </span>
                                    <?php if (empty($phone['registered_at'])): ?>
                                        <span class="badge badge-warning">not registered</span>
                                    <?php endif; ?>
                                    <?php if (!empty($phone['messaging_limit'])): ?>
                                        <span class="text-muted"><?= e((string) $phone['messaging_limit']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $account['status'] === 'active' ? 'success' : 'danger' ?>">
                                <?= e((string) $account['status']) ?>
                            </span>
                            <?php if (!empty($account['webhook_subscribed_at'])): ?>
                                <br><span class="text-sm text-muted">webhook ✔</span>
                            <?php else: ?>
                                <br><span class="text-sm" style="color:var(--danger)">not subscribed</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm" style="white-space:nowrap">
                            <?php foreach ([
                                '/admin/meta/subscribe' => 'Subscribe',
                                '/admin/meta/sync-phones' => 'Sync numbers',
                                '/admin/meta/health' => 'Check',
                            ] as $action => $label): ?>
                                <form method="post" action="<?= e(url($action)) ?>" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                    <button class="btn btn-sm btn-outline"><?= e($label) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/meta/templates?account=' . (int) $account['id'])) ?>">Templates</a>
                            <form method="post" action="<?= e(url('/admin/meta/disconnect')) ?>" style="display:inline"
                                  onsubmit="return confirm('Disconnect this account? Message history is kept.')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-sm btn-danger">Disconnect</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <h3>Connect WhatsApp</h3>

        <div class="alert <?= $signup['ok'] ? 'success' : ($signup['level'] === 'error' ? 'danger' : 'warn') ?>">
            <?= e($signup['message']) ?>
            <?= $signup['hint'] !== '' ? '<br><span class="text-sm">' . e($signup['hint']) . '</span>' : '' ?>
        </div>

        <?php if ($signup['ok']): ?>
            <p class="text-sm text-muted">
                Opens Meta's own dialog. The business picks its WhatsApp account and number
                there; nothing is typed here and no password is ever seen by this site.
            </p>

            <button class="btn btn-block" id="meta-connect">Connect WhatsApp</button>

            <form method="post" action="<?= e(url('/admin/meta/connect')) ?>" id="meta-connect-form" class="mt-2" hidden>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="code" id="meta-code">
                <input type="hidden" name="waba_id" id="meta-waba">
                <input type="hidden" name="phone_number_id" id="meta-phone">
                <div class="field"><label>Two-step PIN for the number <span class="hint">six digits; required before anything can be sent</span>
                    <input name="pin" inputmode="numeric" maxlength="6"></label></div>
                <button class="btn btn-block">Finish connecting</button>
            </form>

            <p class="text-sm text-muted mt-1" id="meta-status"></p>
        <?php endif; ?>

        <hr class="mt-2">

        <h3>Or paste a System User token</h3>
        <p class="text-sm text-muted">
            For a business that already has a permanent token. This works without waiting
            for Meta to review the app.
        </p>

        <form method="post" action="<?= e(url('/admin/meta/connect-token')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="field"><label>Access token
                <input name="access_token" type="password" autocomplete="off" required></label></div>
            <div class="field"><label>WhatsApp Business Account ID <span class="hint">optional — discovered from the token when possible</span>
                <input name="waba_id" inputmode="numeric"></label></div>
            <div class="field"><label>App secret <span class="hint">optional; used to verify this account's webhooks</span>
                <input name="app_secret" type="password" autocomplete="off"></label></div>
            <button class="btn btn-outline btn-block">Connect with token</button>
        </form>
    </div>

    <div>
        <form class="card mb-2" method="post" action="<?= e(url('/admin/meta/app')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <h3>Meta app</h3>
            <p class="text-sm text-muted">
                From <strong>developers.facebook.com</strong> → your app → Settings → Basic,
                and the Embedded Signup configuration under WhatsApp.
            </p>

            <div class="field"><label>App ID
                <input name="meta_app_id" value="<?= e((string) $settings->get('meta_app_id', '')) ?>" inputmode="numeric"></label></div>
            <div class="field"><label>App Secret <span class="hint">leave blank to keep the stored secret</span>
                <input name="meta_app_secret" type="password" autocomplete="off"
                       placeholder="<?= $settings->get('meta_app_secret') ? '•••••••• stored' : 'not set' ?>"></label></div>
            <div class="field"><label>Embedded Signup configuration ID
                <input name="meta_config_id" value="<?= e((string) $settings->get('meta_config_id', '')) ?>" inputmode="numeric"></label></div>
            <div class="field"><label>Graph API version
                <input name="meta_graph_version" value="<?= e((string) $settings->get('meta_graph_version', 'v23.0')) ?>"></label></div>
            <div class="field"><label>Price markup % <span class="hint">added on top of Meta's rate when billing customers</span>
                <input name="meta_price_markup_percent" type="number" step="0.01" min="0"
                       value="<?= e((string) $settings->get('meta_price_markup_percent', '0')) ?>"></label></div>

            <label class="check"><input type="checkbox" name="meta_only_mode" value="1"<?= $settings->bool('meta_only_mode', true) ? ' checked' : '' ?>>
                Official Cloud API only — never fall back to the old gateway</label>

            <button class="btn btn-block"><?= t('common.save') ?></button>
        </form>

        <div class="card">
            <h3>Webhook</h3>
            <p class="text-sm text-muted">Set for you automatically when an account is connected. Shown in case it has to be pasted by hand.</p>

            <p class="text-sm text-muted mt-1">Callback URL</p>
            <div class="copy-box">
                <span class="grow"><?= e($callback) ?></span>
                <button class="btn btn-sm" data-action="copy" data-value="<?= e($callback) ?>"><?= t('common.copy') ?></button>
            </div>

            <p class="text-sm text-muted mt-1">Verify token</p>
            <div class="copy-box">
                <span class="grow"><?= e($verifyToken) ?></span>
                <button class="btn btn-sm" data-action="copy" data-value="<?= e($verifyToken) ?>"><?= t('common.copy') ?></button>
            </div>

            <div class="alert warn text-sm mt-1">
                Subscribe to the <code>messages</code>, <code>message_template_status_update</code>
                and <code>phone_number_quality_update</code> fields. Without the subscription,
                Meta sends nothing at all — which looks exactly like a broken webhook.
            </div>
        </div>
    </div>
</div>

<?php if ($signup['ok']): ?>
<script>
/*
 * Embedded Signup.
 *
 * Two things arrive separately and both are needed: the authorisation code
 * comes back from FB.login, and the WABA/phone ids arrive as a postMessage from
 * Meta's dialog. Neither is useful alone, so both are collected and the form is
 * only revealed once the code is in hand.
 *
 * The code is short-lived and single-use; it is exchanged for a token on the
 * server, because the exchange needs the app secret and an app secret in
 * JavaScript is an app secret published.
 */
(function () {
    var status = document.getElementById('meta-status');
    var form = document.getElementById('meta-connect-form');

    window.addEventListener('message', function (event) {
        if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') {
            return;
        }

        try {
            var data = JSON.parse(event.data);

            if (data.type === 'WA_EMBEDDED_SIGNUP' && data.event === 'FINISH') {
                document.getElementById('meta-waba').value = data.data.waba_id || '';
                document.getElementById('meta-phone').value = data.data.phone_number_id || '';
                status.textContent = 'Number selected. Enter the two-step PIN and finish.';
            } else if (data.type === 'WA_EMBEDDED_SIGNUP' && data.event === 'CANCEL') {
                status.textContent = 'Cancelled at: ' + (data.data.current_step || 'unknown step');
            }
        } catch (e) {
            /* Not our message. */
        }
    });

    window.fbAsyncInit = function () {
        FB.init({
            appId: <?= json_encode($browser['app_id']) ?>,
            cookie: true,
            xfbml: false,
            version: <?= json_encode($browser['version']) ?>
        });
    };

    var script = document.createElement('script');
    script.src = 'https://connect.facebook.net/en_US/sdk.js';
    script.async = true;
    document.head.appendChild(script);

    document.getElementById('meta-connect').addEventListener('click', function () {
        if (typeof FB === 'undefined') {
            status.textContent = 'Facebook SDK has not loaded — check that the browser is not blocking it.';
            return;
        }

        FB.login(function (response) {
            if (response.authResponse && response.authResponse.code) {
                document.getElementById('meta-code').value = response.authResponse.code;
                form.hidden = false;
                status.textContent = 'Authorised. Enter the two-step PIN and press Finish connecting.';
            } else {
                status.textContent = 'Meta did not return an authorisation code — the dialog was closed early.';
            }
        }, {
            config_id: <?= json_encode($browser['config_id']) ?>,
            response_type: 'code',
            override_default_response_type: true,
            extras: { setup: {}, featureType: '', sessionInfoVersion: '3' }
        });
    });
})();
</script>
<?php endif; ?>
