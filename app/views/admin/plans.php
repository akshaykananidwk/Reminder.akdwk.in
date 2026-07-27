<?php /** @var array $plans */ ?>
<div class="card mb-2">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Plan</th><th>Price</th><th>Reminders</th><th>AI msgs</th><th>Tokens</th><th>Devices</th><th>Staff</th><th>Google</th><th>API</th><th>Active</th></tr></thead>
            <tbody>
            <?php foreach ($plans as $plan): ?>
                <tr>
                    <td><strong><?= e((string) $plan['name']) ?></strong><br><code class="text-xs"><?= e((string) $plan['code']) ?></code></td>
                    <td><?= e(money((float) $plan['price'], (string) $plan['currency'])) ?> / <?= (int) $plan['duration_days'] ?>d</td>
                    <td><?= (int) $plan['max_reminders_month'] < 0 ? '∞' : (int) $plan['max_reminders_month'] ?></td>
                    <td><?= (int) $plan['max_ai_messages_month'] ?></td>
                    <td><?= number_format((int) $plan['max_ai_tokens_month']) ?></td>
                    <td><?= (int) $plan['max_devices'] ?></td>
                    <td><?= (int) $plan['max_staff'] ?></td>
                    <td><?= (int) $plan['google_sync'] === 1 ? '✅' : '—' ?></td>
                    <td><?= (int) $plan['api_access'] === 1 ? '✅' : '—' ?></td>
                    <td><?= (int) $plan['is_active'] === 1 ? '✅' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach (array_merge($plans, [null]) as $plan): ?>
    <details class="card mb-2">
        <summary style="cursor:pointer;font-weight:600"><?= $plan === null ? '➕ New plan' : '✏️ ' . e((string) $plan['name']) ?></summary>

        <form method="post" action="<?= e(url('/admin/plans')) ?>" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= $plan === null ? '' : (int) $plan['id'] ?>">

            <div class="grid grid-3">
                <div class="field"><label>Code<input name="code" value="<?= e((string) ($plan['code'] ?? '')) ?>" required></label></div>
                <div class="field"><label>Name<input name="name" value="<?= e((string) ($plan['name'] ?? '')) ?>" required></label></div>
                <div class="field"><label>Sort<input name="sort_order" type="number" value="<?= (int) ($plan['sort_order'] ?? 0) ?>"></label></div>
                <div class="field"><label>Name (ગુ)<input name="name_gu" value="<?= e((string) ($plan['name_gu'] ?? '')) ?>"></label></div>
                <div class="field"><label>Name (हि)<input name="name_hi" value="<?= e((string) ($plan['name_hi'] ?? '')) ?>"></label></div>
                <div class="field"><label>Currency<input name="currency" value="<?= e((string) ($plan['currency'] ?? 'INR')) ?>" maxlength="3"></label></div>
                <div class="field"><label>Price<input name="price" type="number" step="0.01" value="<?= e((string) ($plan['price'] ?? 0)) ?>"></label></div>
                <div class="field"><label>Duration (days)<input name="duration_days" type="number" value="<?= (int) ($plan['duration_days'] ?? 30) ?>"></label></div>
                <div class="field"><label>Trial days<input name="trial_days" type="number" value="<?= (int) ($plan['trial_days'] ?? 0) ?>"></label></div>
                <div class="field"><label>Reminders/month (-1 = ∞)<input name="max_reminders_month" type="number" value="<?= (int) ($plan['max_reminders_month'] ?? 200) ?>"></label></div>
                <div class="field"><label>AI messages/month<input name="max_ai_messages_month" type="number" value="<?= (int) ($plan['max_ai_messages_month'] ?? 200) ?>"></label></div>
                <div class="field"><label>AI tokens/month<input name="max_ai_tokens_month" type="number" value="<?= (int) ($plan['max_ai_tokens_month'] ?? 300000) ?>"></label></div>
                <div class="field"><label>Devices<input name="max_devices" type="number" value="<?= (int) ($plan['max_devices'] ?? 1) ?>"></label></div>
                <div class="field"><label>Staff<input name="max_staff" type="number" value="<?= (int) ($plan['max_staff'] ?? 0) ?>"></label></div>
            </div>

            <div class="flex-wrap">
                <label class="check"><input type="checkbox" name="call_reminders" value="1"<?= (int) ($plan['call_reminders'] ?? 1) === 1 ? ' checked' : '' ?>> Call reminders</label>
                <label class="check"><input type="checkbox" name="google_sync" value="1"<?= (int) ($plan['google_sync'] ?? 0) === 1 ? ' checked' : '' ?>> Google sync</label>
                <label class="check"><input type="checkbox" name="api_access" value="1"<?= (int) ($plan['api_access'] ?? 0) === 1 ? ' checked' : '' ?>> API access</label>
                <label class="check"><input type="checkbox" name="full_reports" value="1"<?= (int) ($plan['full_reports'] ?? 0) === 1 ? ' checked' : '' ?>> Full reports</label>
                <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) ($plan['is_active'] ?? 1) === 1 ? ' checked' : '' ?>> Active</label>
            </div>

            <button class="btn mt-2"><?= t('common.save') ?></button>
        </form>
    </details>
<?php endforeach; ?>
