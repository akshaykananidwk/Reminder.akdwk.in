<?php /** @var array|null $account */ /** @var array $accounts */ /** @var array $messages */
/** @var array $stats */ /** @var int $page */ /** @var int $pages */ /** @var int $total */
/** @var string $contact */

$statusBadge = static function (string $status): string {
    return match ($status) {
        'read'      => 'success',
        'delivered' => 'info',
        'sent'      => 'muted',
        'failed'    => 'danger',
        default     => 'warn',
    };
};
?>

<div class="grid grid-4 mb-2">
    <div class="stat-card"><span class="value"><?= (int) $stats['out'] ?></span><span class="label">Sent</span></div>
    <div class="stat-card green"><span class="value"><?= (int) $stats['read'] ?></span><span class="label">Read</span></div>
    <div class="stat-card"><span class="value"><?= (int) $stats['in'] ?></span><span class="label">Received</span></div>
    <div class="stat-card red"><span class="value"><?= (int) $stats['failed'] ?></span><span class="label">Failed</span></div>
</div>

<div class="card mb-2">
    <form method="get" class="flex-between">
        <div class="grow">
            <input name="contact" value="<?= e($contact) ?>" placeholder="Search by number…">
        </div>
        <?php if (count($accounts) > 1 && $account !== null): ?>
            <select name="account">
                <?php foreach ($accounts as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= (int) $option['id'] === (int) $account['id'] ? ' selected' : '' ?>>
                        <?= e((string) $option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn-sm">Search</button>
    </form>
</div>

<div class="card">
    <h3><?= (int) $total ?> message(s)</h3>

    <?php if ($messages === []): ?>
        <p class="text-muted text-sm">
            Nothing yet. Every message in and out of the Cloud API is recorded here with
            its delivery state and what Meta charged for it.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>When</th><th>Direction</th><th>Number</th><th>Type</th>
                    <th>Message</th><th>Status</th><th>Cost</th>
                </tr></thead>
                <tbody>
                <?php foreach ($messages as $message): ?>
                    <tr>
                        <td class="text-sm text-muted"><?= e(to_user_time((string) $message['created_at'], 'd M, H:i')) ?></td>
                        <td>
                            <span class="badge badge-<?= $message['direction'] === 'in' ? 'info' : 'muted' ?>">
                                <?= $message['direction'] === 'in' ? '← in' : 'out →' ?>
                            </span>
                        </td>
                        <td class="text-sm"><?= e(display_phone((string) $message['contact_wa_id'])) ?></td>
                        <td class="text-sm"><?= e((string) $message['message_type']) ?></td>
                        <td class="text-sm">
                            <?= e(str_limit((string) ($message['body'] ?? ''), 90)) ?>
                            <?php if (!empty($message['template_name'])): ?>
                                <br><span class="badge badge-muted"><?= e((string) $message['template_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($message['interactive_reply_title'])): ?>
                                <br><span class="badge badge-info">pressed: <?= e((string) $message['interactive_reply_title']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($message['error_detail'])): ?>
                                <br><span class="text-sm" style="color:var(--danger)"><?= e(str_limit((string) $message['error_detail'], 120)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $statusBadge((string) $message['status']) ?>"><?= e((string) $message['status']) ?></span>
                        </td>
                        <td class="text-sm">
                            <?php if ((float) $message['price'] > 0): ?>
                                <?= e(money((float) $message['price'], (string) ($message['currency'] ?: 'INR'))) ?>
                            <?php elseif (!empty($message['billing_category'])): ?>
                                <span class="text-muted"><?= e((string) $message['billing_category']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pager">
                <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                    <a class="pager-link<?= $p === $page ? ' active' : '' ?>"
                       href="?page=<?= $p ?><?= $contact !== '' ? '&contact=' . urlencode($contact) : '' ?><?= $account !== null ? '&account=' . (int) $account['id'] : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
