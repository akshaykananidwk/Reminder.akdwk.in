<?php /** @var array $coupons */ /** @var array $plans */ ?>
<form class="card mb-2" method="post" action="<?= e(url('/admin/coupons')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h3>New / update coupon</h3>

    <div class="grid grid-3">
        <div class="field"><label>Code<input name="code" required placeholder="DIWALI25"></label></div>
        <div class="field"><label>Type
            <select name="discount_type"><option value="percent">Percent</option><option value="fixed">Fixed</option></select></label></div>
        <div class="field"><label>Value<input name="discount_value" type="number" step="0.01" required></label></div>
        <div class="field"><label>Plan
            <select name="plan_id"><option value="">Any</option>
                <?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= e((string) $plan['name']) ?></option><?php endforeach; ?>
            </select></label></div>
        <div class="field"><label>Max uses<input name="max_uses" type="number" min="1"></label></div>
        <div class="field"><label>Valid until<input name="valid_until" type="date"></label></div>
    </div>

    <label class="check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
    <button class="btn mt-2"><?= t('common.save') ?></button>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Code</th><th>Discount</th><th>Plan</th><th>Used</th><th>Valid until</th><th>Active</th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $coupon): ?>
                <tr>
                    <td><code><?= e((string) $coupon['code']) ?></code></td>
                    <td><?= (string) $coupon['discount_type'] === 'percent' ? (float) $coupon['discount_value'] . '%' : e(money((float) $coupon['discount_value'])) ?></td>
                    <td><?= e((string) ($coupon['plan_name'] ?? 'Any')) ?></td>
                    <td><?= (int) $coupon['used_count'] ?><?= $coupon['max_uses'] ? ' / ' . (int) $coupon['max_uses'] : '' ?></td>
                    <td><?= e((string) ($coupon['valid_until'] ?? '—')) ?></td>
                    <td><?= (int) $coupon['is_active'] === 1 ? '✅' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
