<?php

use App\Core\App;
use App\Core\Request;

/** @var string $content */

$settings = App::i()->settings();
$path = Request::path();
$title = ($title ?? __('admin.dashboard')) . ' · Admin';

$nav = [
    ['/admin', '📊', __('admin.dashboard')],
    ['/admin/users', '👤', __('admin.users')],
    ['/admin/plans', '💠', __('admin.plans')],
    ['/admin/subscriptions', '🧾', __('admin.subscriptions')],
    ['/admin/invoices', '📄', __('admin.invoices')],
    ['/admin/coupons', '🎟️', __('admin.coupons')],
];

$navConfig = [
    ['/admin/meta', '🟢', 'WhatsApp Platform'],
    ['/admin/meta/templates', '🗂️', 'WA templates'],
    ['/admin/meta/conversations', '💬', 'WA conversations'],
    ['/admin/meta/billing', '💰', 'WA billing'],
    ['/admin/meta/logs', '📡', 'WA API log'],
    ['/admin/whatsapp', '⚙️', __('admin.whatsapp')],
    ['/admin/telegram', '✈️', 'Telegram'],
    ['/admin/ai', '🤖', __('admin.ai')],
    ['/admin/templates', '📝', __('admin.templates')],
    ['/admin/content', '🌐', __('admin.content')],
    ['/admin/settings', '⚙️', __('admin.settings')],
];

$navOps = [
    ['/admin/cron', '⏱️', __('admin.cron')],
    ['/admin/logs', '📋', __('admin.logs')],
    ['/admin/ai-report', '💸', __('admin.ai_report')],
    ['/admin/broadcast', '📢', __('admin.broadcast')],
    ['/admin/audit', '🔍', __('admin.audit')],
    ['/admin/system', '🖥️', __('admin.system')],
    ['/admin/update', '⬆️', __('admin.update')],
];

$isActive = static fn (string $href): bool => $href === '/admin' ? $path === '/admin' : str_starts_with($path, $href);
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="base-url" content="<?= e(App::i()->url()) ?>">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="app-shell" id="appShell">
    <div class="nav-scrim" data-action="close-nav" aria-hidden="true"></div>

    <aside class="sidebar" id="adminSidebar">
        <a class="logo" href="<?= e(url('/admin')) ?>">
            <span class="mark">🕉️</span><span>Admin</span>
        </a>

        <nav>
            <?php foreach ($nav as [$href, $icon, $label]): ?>
                <a class="<?= $isActive($href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><span class="ico"><?= $icon ?></span><?= e($label) ?></a>
            <?php endforeach; ?>

            <div class="group-label">Configuration</div>
            <?php foreach ($navConfig as [$href, $icon, $label]): ?>
                <a class="<?= $isActive($href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><span class="ico"><?= $icon ?></span><?= e($label) ?></a>
            <?php endforeach; ?>

            <div class="group-label">Operations</div>
            <?php foreach ($navOps as [$href, $icon, $label]): ?>
                <a class="<?= $isActive($href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><span class="ico"><?= $icon ?></span><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="<?= e(url('/admin/logout')) ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= e($csrf ?? '') ?>">
            <button class="btn btn-ghost btn-block btn-sm">Log out</button>
        </form>
    </aside>

    <div class="app-main">
        <div class="app-topbar">
            <button class="icon-btn nav-toggle" data-action="toggle-nav"
                    aria-label="Menu" aria-controls="adminSidebar" aria-expanded="false">☰</button>
            <h1 class="grow"><?= e($pageTitle ?? $title) ?></h1>
            <div class="flex">
                <span class="text-sm text-muted desktop-only"><?= e((string) ($authAdmin['name'] ?? '')) ?></span>
                <button class="icon-btn" data-action="toggle-theme" aria-label="Toggle dark mode">◐</button>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/')) ?>" target="_blank">View site ↗</a>
            </div>
        </div>

        <div class="app-content">
            <?php foreach ($flash ?? [] as $item): ?>
                <div class="alert alert-<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
            <?php endforeach; ?>

            <?= $content ?>
        </div>
    </div>
</div>

<nav class="bottom-nav">
    <a class="<?= $path === '/admin' ? 'active' : '' ?>" href="<?= e(url('/admin')) ?>"><span class="ico">📊</span>Home</a>
    <a class="<?= str_starts_with($path, '/admin/users') ? 'active' : '' ?>" href="<?= e(url('/admin/users')) ?>"><span class="ico">👤</span>Users</a>
    <a class="<?= str_starts_with($path, '/admin/cron') ? 'active' : '' ?>" href="<?= e(url('/admin/cron')) ?>"><span class="ico">⏱️</span>Cron</a>
    <a class="<?= str_starts_with($path, '/admin/logs') ? 'active' : '' ?>" href="<?= e(url('/admin/logs')) ?>"><span class="ico">📋</span>Logs</a>
    <a class="<?= str_starts_with($path, '/admin/system') ? 'active' : '' ?>" href="<?= e(url('/admin/system')) ?>"><span class="ico">🖥️</span>System</a>
</nav>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
