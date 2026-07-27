<?php /** @var string $tab */ /** @var array $rows */ ?>
<div class="chips mb-2">
    <?php foreach (['errors' => 'Errors', 'whatsapp_in' => 'WhatsApp in', 'whatsapp_out' => 'WhatsApp out', 'ai' => 'AI', 'fcm' => 'FCM', 'logins' => 'Logins'] as $key => $label): ?>
        <a class="chip <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <?php if ($rows === []): ?>
                <tbody><tr><td class="text-muted">No entries.</td></tr></tbody>
            <?php else: ?>
                <thead>
                <tr><?php foreach (array_keys($rows[0]) as $column): ?><th><?= e(str_replace('_', ' ', (string) $column)) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $value): ?>
                            <td style="white-space:normal;max-width:320px"><?= e(str_limit((string) $value, 160)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endif; ?>
        </table>
    </div>
</div>
