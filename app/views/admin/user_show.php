<?php /** @var array $user */ /** @var array $usage */ /** @var array $plans */ /** @var array $numbers */
/** @var array $devices */ /** @var array $recent */ /** @var array $inbound */ /** @var array $aiLogs */ /** @var array $subscriptions */ ?>
<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="card-head">
            <h3><?= e((string) $user['name']) ?></h3>
            <span class="badge badge-<?= (int) $user['is_active'] === 1 ? 'success' : 'danger' ?>"><?= (int) $user['is_active'] === 1 ? 'active' : 'suspended' ?></span>
        </div>

        <table class="data">
            <tbody>
            <tr><th>Phone</th><td><?= e(display_phone((string) $user['phone'])) ?></td></tr>
            <tr><th>Email</th><td><?= e((string) $user['email']) ?></td></tr>
            <tr><th>City</th><td><?= e((string) $user['city']) ?></td></tr>
            <tr><th>Language</th><td><?= e((string) $user['language']) ?></td></tr>
            <tr><th>Timezone</th><td><?= e((string) $user['timezone']) ?></td></tr>
            <tr><th>Streak</th><td>🔥 <?= (int) $user['streak_days'] ?> (best <?= (int) $user['best_streak'] ?>)</td></tr>
            <tr><th>Joined</th><td><?= e(to_user_time((string) $user['created_at'], 'd M Y, h:i A')) ?></td></tr>
            <tr><th>Last login</th><td><?= $user['last_login_at'] ? e(human_diff((string) $user['last_login_at'])) : '—' ?></td></tr>
            <tr><th>Referral code</th><td><code><?= e((string) $user['referral_code']) ?></code></td></tr>
            </tbody>
        </table>

        <div class="flex-wrap mt-2">
            <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/impersonate')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm" data-action="confirm" data-message="Log in as this user? This is audit-logged."><?= t('admin.impersonate') ?></button>
            </form>
            <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/suspend')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost"><?= (int) $user['is_active'] === 1 ? __('admin.suspend') : __('admin.activate') ?></button>
            </form>
            <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/reset-otp')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost"><?= t('admin.reset_otp') ?></button>
            </form>
            <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/delete')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-danger" data-action="confirm" data-message="Delete this user permanently?">Delete</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Usage this month</h3>
        <ul class="list">
            <li class="list-item"><div class="body"><div class="title text-sm">Reminders</div></div><span><?= (int) $usage['reminders'] ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">AI messages</div></div><span><?= (int) $usage['ai_messages'] ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">AI tokens</div></div><span><?= number_format((int) $usage['ai_tokens']) ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">AI cost</div></div><span>$<?= number_format((float) $usage['ai_cost'], 4) ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">Calls</div></div><span><?= (int) $usage['calls'] ?></span></li>
            <li class="list-item"><div class="body"><div class="title text-sm">WhatsApp messages</div></div><span><?= (int) $usage['wa_messages'] ?></span></li>
        </ul>

        <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/extend')) ?>" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="flex">
                <select name="plan_id" class="grow">
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?= (int) $plan['id'] ?>"<?= (int) $user['plan_id'] === (int) $plan['id'] ? ' selected' : '' ?>><?= e((string) $plan['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="days" value="30" min="1" max="3650" style="width:100px">
                <button class="btn btn-sm"><?= t('admin.extend_plan') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>Numbers &amp; devices</h3>
        <ul class="list">
            <?php foreach ($numbers as $number): ?>
                <li class="list-item">
                    <div class="body"><div class="title text-sm"><?= e(display_phone((string) $number['number'])) ?></div>
                        <div class="meta"><?= e((string) $number['label']) ?></div></div>
                    <span class="badge badge-<?= (int) $number['is_verified'] === 1 ? 'success' : 'warning' ?>"><?= (int) $number['is_verified'] === 1 ? 'verified' : 'pending' ?></span>
                </li>
            <?php endforeach; ?>
            <?php foreach ($devices as $device): ?>
                <li class="list-item">
                    <div class="body"><div class="title text-sm">📱 <?= e((string) ($device['model'] ?: 'Android')) ?></div>
                        <div class="meta">v<?= e((string) $device['app_version']) ?> · <?= e(human_diff((string) $device['last_seen_at'])) ?></div></div>
                    <span class="badge badge-<?= !empty($device['fcm_token']) ? 'success' : 'danger' ?>"><?= !empty($device['fcm_token']) ? 'push' : 'no push' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h3>Recent reminders</h3>
        <ul class="list">
            <?php foreach ($recent as $reminder): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e(str_limit((string) $reminder['title'], 60)) ?></div>
                        <div class="meta"><code><?= e((string) $reminder['short_code']) ?></code> · <?= e((string) $reminder['source']) ?> · <?= e(to_user_time((string) $reminder['start_at'], 'd M h:i A', (string) $user['timezone'])) ?></div>
                    </div>
                    <span class="badge badge-<?= e(status_badge((string) $reminder['status'])) ?>"><?= e((string) $reminder['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="grid grid-2 mt-2">
    <div class="card">
        <h3>Recent WhatsApp in</h3>
        <ul class="list">
            <?php foreach ($inbound as $message): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e(str_limit((string) $message['body'], 80)) ?></div>
                        <div class="meta"><?= e((string) ($message['handled_by'] ?? 'queued')) ?> · <?= e(human_diff((string) $message['received_at'])) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h3>Recent AI calls</h3>
        <ul class="list">
            <?php foreach ($aiLogs as $log): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e((string) $log['model']) ?> · <?= (int) $log['total_tokens'] ?> tokens</div>
                        <div class="meta">$<?= number_format((float) $log['cost'], 6) ?> · <?= (int) $log['latency_ms'] ?> ms · <?= e(human_diff((string) $log['created_at'])) ?></div>
                    </div>
                    <span class="badge badge-<?= (int) $log['success'] === 1 ? 'success' : 'danger' ?>"><?= (int) $log['cached'] === 1 ? 'cached' : ((int) $log['success'] === 1 ? 'ok' : 'failed') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
