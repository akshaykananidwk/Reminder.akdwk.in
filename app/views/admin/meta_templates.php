<?php /** @var array|null $account */ /** @var array $accounts */
/** @var array $templates */ /** @var array|null $editing */

$components = $editing !== null ? json_field($editing['components']) : [];
$body = '';
$headerText = '';
$headerFormat = '';
$footer = '';

foreach ($components as $component) {
    switch (strtoupper((string) ($component['type'] ?? ''))) {
        case 'BODY':   $body = (string) ($component['text'] ?? ''); break;
        case 'HEADER': $headerFormat = (string) ($component['format'] ?? 'TEXT');
                       $headerText = (string) ($component['text'] ?? ''); break;
        case 'FOOTER': $footer = (string) ($component['text'] ?? ''); break;
    }
}

$badge = static function (string $status): string {
    return match ($status) {
        'APPROVED' => 'success',
        'REJECTED', 'DISABLED' => 'danger',
        'PENDING', 'IN_APPEAL' => 'info',
        'PAUSED' => 'warn',
        default => 'muted',
    };
};
?>

<?php if ($account === null): ?>
    <div class="alert warn">Connect a WhatsApp Business Account first — <a href="<?= e(url('/admin/meta')) ?>">do that here</a>.</div>
<?php else: ?>

<div class="card mb-2">
    <div class="flex-between">
        <div>
            <h3><?= e((string) $account['name']) ?></h3>
            <p class="text-sm text-muted">
                Outside the 24-hour window a template is the only thing WhatsApp delivers.
                Approval usually takes minutes, occasionally a day.
            </p>
        </div>

        <div class="text-sm" style="white-space:nowrap">
            <?php if (count($accounts) > 1): ?>
                <form method="get" style="display:inline">
                    <select name="account" onchange="this.form.submit()">
                        <?php foreach ($accounts as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= (int) $option['id'] === (int) $account['id'] ? ' selected' : '' ?>>
                                <?= e((string) $option['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/admin/meta/templates/sync')) ?>" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <button class="btn btn-sm btn-outline">Sync from Meta</button>
            </form>

            <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/meta/templates/export?account=' . (int) $account['id'])) ?>">Export</a>
        </div>
    </div>
</div>

<div class="grid grid-2 mb-2">
    <form class="card" method="post" action="<?= e(url('/admin/meta/templates')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
        <input type="hidden" name="template_id" value="<?= $editing !== null ? (int) $editing['id'] : 0 ?>">

        <h3><?= $editing !== null ? 'Edit ' . e((string) $editing['name']) : 'New template' ?></h3>

        <div class="field"><label>Name <span class="hint">lowercase, numbers and underscores only — it can never be changed</span>
            <input name="name" required value="<?= e((string) ($editing['name'] ?? '')) ?>"
                   <?= $editing !== null ? 'readonly' : '' ?>></label></div>

        <div class="grid grid-2">
            <div class="field"><label>Language
                <input name="language" value="<?= e((string) ($editing['language'] ?? 'en')) ?>"></label></div>
            <div class="field"><label>Category
                <select name="category">
                    <?php foreach (['UTILITY', 'MARKETING', 'AUTHENTICATION'] as $category): ?>
                        <option value="<?= $category ?>"<?= ($editing['category'] ?? 'UTILITY') === $category ? ' selected' : '' ?>><?= $category ?></option>
                    <?php endforeach; ?>
                </select></label></div>
        </div>

        <div class="grid grid-2">
            <div class="field"><label>Header type
                <select name="header_format">
                    <option value="">none</option>
                    <?php foreach (['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'] as $format): ?>
                        <option value="<?= $format ?>"<?= $headerFormat === $format ? ' selected' : '' ?>><?= $format ?></option>
                    <?php endforeach; ?>
                </select></label></div>
            <div class="field"><label>Header text <span class="hint">at most one {{1}}</span>
                <input name="header_text" value="<?= e($headerText) ?>" maxlength="60"></label></div>
        </div>

        <div class="field"><label>Body <span class="hint">use {{1}}, {{2}} … numbered with no gaps, and never at the very start or end</span>
            <textarea name="body" rows="5" required maxlength="1024"><?= e($body) ?></textarea></label></div>

        <div class="field"><label>Example values <span class="hint">separated by |  — Meta rejects a variable with no example</span>
            <input name="body_examples" placeholder="Electricity bill|28 July, 8:00 AM"></label></div>

        <div class="field"><label>Footer <span class="hint">60 characters</span>
            <input name="footer" value="<?= e($footer) ?>" maxlength="60"></label></div>

        <h4 class="mt-2">Buttons</h4>
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="grid grid-3">
                <select name="button_type[<?= $i ?>]">
                    <option value="">none</option>
                    <option value="QUICK_REPLY">Quick reply</option>
                    <option value="URL">Open link</option>
                    <option value="PHONE_NUMBER">Call</option>
                    <option value="COPY_CODE">Copy code</option>
                </select>
                <input name="button_text[<?= $i ?>]" placeholder="Label" maxlength="25">
                <input name="button_value[<?= $i ?>]" placeholder="URL / number / example code">
            </div>
        <?php endfor; ?>

        <label class="check mt-2"><input type="checkbox" name="submit_now" value="1" checked> Submit to Meta for approval straight away</label>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <div>
        <div class="card mb-2">
            <h3>Import</h3>
            <form method="post" action="<?= e(url('/admin/meta/templates/import')) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <div class="field"><label>Export file<input type="file" name="file" accept="application/json"></label></div>
                <p class="text-sm text-muted">Imported templates arrive as drafts. Submitting someone else's copy to Meta under this business's name should always be a deliberate click.</p>
                <button class="btn btn-outline btn-block">Import</button>
            </form>
        </div>

        <div class="card">
            <h3>What Meta rejects</h3>
            <ul class="text-sm">
                <li>Variables numbered with a gap — {{1}} then {{3}}.</li>
                <li>A body that begins or ends with a variable.</li>
                <li>A header with more than one variable.</li>
                <li>A parameter containing a newline, a tab, or four spaces in a row.</li>
                <li>Marketing content submitted under the UTILITY category.</li>
            </ul>
            <p class="text-sm text-muted">The first four are checked here before submission, so the wait is not wasted.</p>
        </div>
    </div>
</div>

<div class="card">
    <h3><?= count($templates) ?> template(s)</h3>

    <?php if ($templates === []): ?>
        <p class="text-muted text-sm">None yet. Create one on the left, or press <strong>Sync from Meta</strong> to pull in templates made in Meta's own dashboard.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Lang</th><th>Category</th><th>Status</th><th>Vars</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td>
                            <strong><?= e((string) $template['name']) ?></strong>
                            <?php if (!empty($template['rejected_reason'])): ?>
                                <br><span class="text-sm" style="color:var(--danger)"><?= e((string) $template['rejected_reason']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e((string) $template['language']) ?></td>
                        <td class="text-sm"><?= e((string) $template['category']) ?></td>
                        <td><span class="badge badge-<?= $badge((string) $template['status']) ?>"><?= e((string) $template['status']) ?></span></td>
                        <td class="text-sm"><?= (int) $template['variable_count'] ?></td>
                        <td class="text-sm" style="white-space:nowrap">
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/meta/templates?account=' . (int) $account['id'] . '&edit=' . (int) $template['id'])) ?>">Edit</a>

                            <form method="post" action="<?= e(url('/admin/meta/templates/submit')) ?>" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                                <button class="btn btn-sm btn-outline"><?= empty($template['meta_template_id']) ? 'Submit' : 'Resubmit' ?></button>
                            </form>

                            <form method="post" action="<?= e(url('/admin/meta/templates/clone')) ?>" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                                <input name="new_name" placeholder="new_name" size="12" required>
                                <input name="new_language" placeholder="gu" size="3">
                                <button class="btn btn-sm btn-outline">Copy</button>
                            </form>

                            <form method="post" action="<?= e(url('/admin/meta/templates/delete')) ?>" style="display:inline"
                                  onsubmit="return confirm('Delete at Meta as well? Every language of this name goes.')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>
