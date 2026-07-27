<?php /** @var array $templates */ /** @var array $keys */ ?>
<div class="card mb-2">
    <h3>Message templates</h3>
    <p class="text-sm text-muted mb-0">
        Every outgoing WhatsApp message, in all three languages. Placeholders:
        <code>{name}</code> <code>{title}</code> <code>{time}</code> <code>{code}</code> <code>{amount}</code>
        <code>{date}</code> <code>{list}</code> <code>{count}</code> <code>{streak}</code> <code>{url}</code>
    </p>
</div>

<?php foreach ($keys as $key): ?>
    <details class="card mb-2">
        <summary style="cursor:pointer;font-weight:600"><?= e($key) ?></summary>

        <div class="grid grid-3 mt-2">
            <?php foreach (['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'] as $lang => $label): ?>
                <form method="post" action="<?= e(url('/admin/templates')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="template_key" value="<?= e($key) ?>">
                    <input type="hidden" name="lang" value="<?= e($lang) ?>">

                    <div class="field">
                        <label><?= e($label) ?>
                            <textarea name="body" rows="8"><?= e((string) ($templates[$key][$lang]['body'] ?? '')) ?></textarea>
                        </label>
                    </div>

                    <button class="btn btn-sm"><?= t('common.save') ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </details>
<?php endforeach; ?>
