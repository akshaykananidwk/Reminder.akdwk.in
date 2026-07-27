<?php /** @var array $messages */ /** @var array $outbound */ /** @var string $tz */ ?>
<div class="card mb-2">
    <h3><?= t('inbox.title') ?></h3>
    <p class="text-sm text-muted mb-0">
        Everything we received on WhatsApp, exactly what the AI understood, and the reply we sent back.
        If something was read wrongly, press <em><?= t('inbox.reprocess') ?></em>.
    </p>
</div>

<?php if ($messages === []): ?>
    <div class="card"><div class="empty"><div class="icon">💬</div><h3><?= t('inbox.none') ?></h3></div></div>
<?php else: ?>
    <?php foreach ($messages as $message):
        $result = json_field($message['ai_result'], []);
    ?>
        <div class="card mb-2">
            <div class="flex-between mb-1">
                <div class="text-sm text-muted">
                    <?= e(to_user_time((string) $message['received_at'], 'd M Y, h:i A', $tz)) ?>
                    · <?= e(display_phone((string) $message['from_number'])) ?>
                </div>
                <span class="badge badge-<?= (int) $message['processed'] === 1 ? 'success' : 'warning' ?>">
                    <?= e((string) ($message['handled_by'] ?: 'queued')) ?>
                </span>
            </div>

            <div style="background:#DCF8C6;color:#0b3d2c;border-radius:12px;padding:11px 13px;font-size:14.5px">
                <?= nl2br(e((string) $message['body'])) ?>
            </div>

            <?php if ($result !== []): ?>
                <div class="mt-1 text-sm">
                    <strong><?= t('inbox.understood') ?>:</strong>
                    <code><?= e(json_encode($result, JSON_UNESCAPED_UNICODE)) ?></code>
                </div>
            <?php endif; ?>

            <?php if (!empty($message['reply_sent'])): ?>
                <div class="mt-1" style="background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:11px 13px;font-size:14px">
                    <strong class="text-xs text-muted"><?= t('inbox.reply_sent') ?></strong><br>
                    <?= nl2br(e((string) $message['reply_sent'])) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/client/inbox/' . (int) $message['id'] . '/reprocess')) ?>" class="mt-1">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost">🔁 <?= t('inbox.reprocess') ?></button>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/client/reminders/create')) ?>"><?= t('inbox.fix_manually') ?></a>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($outbound !== []): ?>
    <div class="card">
        <h3>Sent by Krishna Reminder</h3>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th><?= t('common.time') ?></th><th>Message</th><th><?= t('common.status') ?></th></tr></thead>
                <tbody>
                <?php foreach ($outbound as $log): ?>
                    <tr>
                        <td><?= e(to_user_time((string) $log['created_at'], 'd M h:i A', $tz)) ?></td>
                        <td style="white-space:normal;max-width:420px"><?= e(str_limit((string) $log['message'], 120)) ?></td>
                        <td><span class="badge badge-<?= (int) $log['success'] === 1 ? 'success' : 'danger' ?>"><?= (int) $log['success'] === 1 ? 'sent' : 'failed' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
