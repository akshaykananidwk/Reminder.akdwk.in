<?php /** @var \App\Core\Settings $settings */ /** @var int $monthTokens */ /** @var float $monthCost */ /** @var array $failures */ ?>
<div class="grid grid-3 mb-2">
    <div class="stat-card"><span class="value"><?= number_format($monthTokens) ?></span><span class="label">Tokens this month</span></div>
    <div class="stat-card gold"><span class="value">$<?= number_format($monthCost, 4) ?></span><span class="label">Estimated cost</span></div>
    <div class="stat-card <?= $settings->int('ai_monthly_budget_tokens', 0) > 0 && $monthTokens >= $settings->int('ai_monthly_budget_tokens', 0) ? 'red' : 'green' ?>">
        <span class="value"><?= $settings->int('ai_monthly_budget_tokens', 0) > 0 ? number_format($settings->int('ai_monthly_budget_tokens', 0)) : '∞' ?></span>
        <span class="label">Monthly budget (hard stop)</span>
    </div>
</div>

<div class="grid grid-2">
    <form class="card" method="post" action="<?= e(url('/admin/ai')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Gemini</h3>

        <div class="field"><label>API key <span class="hint">blank keeps the stored key</span>
            <input name="gemini_api_key" type="password" placeholder="<?= $settings->get('gemini_api_key') ? '•••••••• stored' : 'not set' ?>"></label></div>
        <div class="field"><label>Rotation key (optional)
            <input name="gemini_api_key_2" type="password" placeholder="<?= $settings->get('gemini_api_key_2') ? '•••••••• stored' : 'not set' ?>"></label></div>

        <div class="field"><label>Model
            <select name="gemini_model">
                <?php foreach (['gemini-2.0-flash','gemini-2.0-flash-lite','gemini-1.5-flash','gemini-1.5-pro'] as $model): ?>
                    <option value="<?= e($model) ?>"<?= (string) $settings->get('gemini_model') === $model ? ' selected' : '' ?>><?= e($model) ?></option>
                <?php endforeach; ?>
            </select></label></div>

        <div class="grid grid-2">
            <div class="field"><label>Temperature<input name="gemini_temperature" type="number" step="0.05" min="0" max="1" value="<?= e((string) $settings->get('gemini_temperature', '0.2')) ?>"></label></div>
            <div class="field"><label>Max output tokens<input name="gemini_max_tokens" type="number" min="128" max="8192" value="<?= (int) $settings->int('gemini_max_tokens', 1024) ?>"></label></div>
            <div class="field"><label>Cache hours<input name="ai_cache_hours" type="number" min="0" max="168" value="<?= (int) $settings->int('ai_cache_hours', 24) ?>"></label></div>
            <div class="field"><label>Monthly token budget<input name="ai_monthly_budget_tokens" type="number" min="0" value="<?= (int) $settings->int('ai_monthly_budget_tokens', 0) ?>"></label></div>
            <div class="field"><label>Cost / 1k input<input name="ai_cost_per_1k_input" type="number" step="0.000001" value="<?= e((string) $settings->get('ai_cost_per_1k_input', '0.000075')) ?>"></label></div>
            <div class="field"><label>Cost / 1k output<input name="ai_cost_per_1k_output" type="number" step="0.000001" value="<?= e((string) $settings->get('ai_cost_per_1k_output', '0.0003')) ?>"></label></div>
        </div>

        <label class="check"><input type="checkbox" name="gemini_enabled" value="1"<?= $settings->bool('gemini_enabled', true) ? ' checked' : '' ?>> Gemini enabled</label>
        <label class="check"><input type="checkbox" name="ai_fallback_enabled" value="1"<?= $settings->bool('ai_fallback_enabled', true) ? ' checked' : '' ?>> Use the regex fallback parser when AI is unavailable</label>

        <div class="field mt-2">
            <label>Prompt template <span class="hint">blank = built-in short prompt (recommended, cheapest)</span>
                <textarea name="ai_prompt_template" rows="8"><?= e((string) $settings->get('ai_prompt_template', '')) ?></textarea>
            </label>
            <span class="hint">Placeholders: {now} {weekday} {timezone} {language} {default_time} {categories}</span>
        </div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <div>
        <form class="card mb-2" method="post" action="<?= e(url('/admin/ai/test')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <h3>Test parse</h3>
            <div class="field"><label>Gujarati sentence
                <input name="text" value="કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે"></label></div>
            <button class="btn">Test</button>
        </form>

        <div class="card">
            <h3>Recent failures</h3>
            <?php if ($failures === []): ?>
                <p class="text-sm text-muted">No failures recorded. 👍</p>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($failures as $failure): ?>
                        <li class="list-item">
                            <div class="body">
                                <div class="title text-sm"><?= e(str_limit((string) $failure['error'], 90)) ?></div>
                                <div class="meta"><?= e((string) $failure['model']) ?> · <?= e(human_diff((string) $failure['created_at'])) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
