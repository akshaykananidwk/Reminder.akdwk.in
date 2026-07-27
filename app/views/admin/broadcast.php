<?php /** @var array $broadcasts */ /** @var int $userCount */ ?>
<form class="card mb-2" method="post" action="<?= e(url('/admin/broadcast')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h3>New broadcast</h3>
    <p class="text-sm text-muted"><?= number_format($userCount) ?> active users.</p>

    <div class="field"><label>Title<input name="title" required maxlength="190"></label></div>
    <div class="field"><label>Message<textarea name="body" rows="6" required></textarea></label></div>

    <div class="grid grid-2">
        <div class="field"><label>Audience
            <select name="audience">
                <option value="all">Everyone</option>
                <option value="trial">Trial users</option>
                <option value="paid">Paid users</option>
                <option value="expiring">Expiring in 7 days</option>
            </select></label></div>
        <div class="field">
            <label>Channels</label>
            <label class="check"><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp</label>
            <label class="check"><input type="checkbox" name="channels[]" value="app" checked> App notification</label>
        </div>
    </div>

    <button class="btn" data-action="confirm" data-message="Send this broadcast?">Send</button>
</form>

<div class="card">
    <h3>History</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Title</th><th>Audience</th><th>Channels</th><th>Recipients</th><th>Status</th><th>Sent</th></tr></thead>
            <tbody>
            <?php foreach ($broadcasts as $broadcast): ?>
                <tr>
                    <td><?= e((string) $broadcast['title']) ?></td>
                    <td><?= e((string) $broadcast['audience']) ?></td>
                    <td><?= e((string) $broadcast['channels']) ?></td>
                    <td><?= (int) $broadcast['recipients'] ?></td>
                    <td><span class="badge badge-<?= e(status_badge((string) $broadcast['status'])) ?>"><?= e((string) $broadcast['status']) ?></span></td>
                    <td><?= $broadcast['sent_at'] ? e(to_user_time((string) $broadcast['sent_at'], 'd M Y H:i')) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
