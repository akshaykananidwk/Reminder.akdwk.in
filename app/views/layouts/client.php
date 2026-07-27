<?php

use App\Core\App;
use App\Core\Lang;
use App\Core\Request;

/** @var string $content */
/** @var array $flash */
/** @var array|null $authUser */

$settings = App::i()->settings();
$locale = Lang::locale();
$path = Request::path();
$title = ($title ?? t('nav.dashboard')) . ' · ' . (string) $settings->get('site_name', 'Krishna Reminder');

$nav = [
    ['/client', '🏠', __('nav.home')],
    ['/client/reminders', '🔔', __('nav.reminders')],
    ['/client/calendar', '📅', __('nav.calendar')],
    ['/client/payments', '💰', __('nav.payments')],
    ['/client/notes', '📝', __('nav.notes')],
    ['/client/contacts', '👥', __('nav.contacts')],
    ['/client/reports', '📊', __('nav.reports')],
    ['/client/inbox', '💬', __('nav.inbox')],
];

$navAccount = [
    ['/client/devices', '📱', __('nav.devices')],
    ['/client/integrations', '🔗', __('nav.integrations')],
    ['/client/billing', '💳', __('nav.billing')],
    ['/client/referral', '🎁', __('nav.referral')],
    ['/client/settings', '⚙️', __('nav.settings')],
    ['/client/help', '❓', __('nav.help')],
];

$bottom = [
    ['/client', '🏠', __('nav.home')],
    ['/client/reminders', '🔔', __('nav.reminders')],
    ['/client/payments', '💰', __('nav.payments')],
    ['/client/reports', '📊', __('nav.reports')],
    ['/client/settings', '⚙️', __('nav.settings')],
];

$isActive = static fn (string $href): bool => $href === '/client' ? $path === '/client' : str_starts_with($path, $href);
?>
<!doctype html>
<html lang="<?= e($locale) ?>" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1B3A6B">
<meta name="base-url" content="<?= e(App::i()->url()) ?>">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="manifest" href="<?= e(App::i()->url('/manifest.webmanifest')) ?>">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Anek+Gujarati:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body lang="<?= e($locale) ?>">

<?php if (\App\Core\Auth::isImpersonating()): ?>
    <div class="impersonate-bar">
        You are viewing this account as an administrator.
        <form method="post" action="<?= e(url('/logout')) ?>" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= e($csrf ?? '') ?>">
            <button class="btn btn-sm btn-ghost" style="color:#fff;border-color:#fff">Exit</button>
        </form>
    </div>
<?php endif; ?>

<div class="app-shell">
    <aside class="sidebar">
        <a class="logo" href="<?= e(url('/client')) ?>">
            <span class="mark">🕉️</span>
            <span><?= e((string) $settings->get('site_name', 'Krishna Reminder')) ?></span>
        </a>

        <nav>
            <?php foreach ($nav as [$href, $icon, $label]): ?>
                <a class="<?= $isActive($href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
                    <span class="ico"><?= $icon ?></span><?= e($label) ?>
                </a>
            <?php endforeach; ?>

            <div class="group-label"><?= t('nav.profile') ?></div>

            <?php foreach ($navAccount as [$href, $icon, $label]): ?>
                <a class="<?= $isActive($href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
                    <span class="ico"><?= $icon ?></span><?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="<?= e(url('/logout')) ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= e($csrf ?? '') ?>">
            <button class="btn btn-ghost btn-block btn-sm"><?= t('nav.logout') ?></button>
        </form>
    </aside>

    <div class="app-main">
        <div class="app-topbar">
            <h1><?= e($pageTitle ?? ($title ?? '')) ?></h1>

            <div class="flex">
                <button class="icon-btn" data-action="toggle-theme" aria-label="Toggle dark mode">◐</button>
                <a class="icon-btn" href="<?= e(url('/client/settings')) ?>" aria-label="<?= t('nav.settings') ?>">⚙️</a>
                <span class="badge badge-warning mobile-only" title="<?= t('dashboard.streak') ?>">
                    🔥 <?= (int) ($authUser['streak_days'] ?? 0) ?>
                </span>
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
    <?php foreach ($bottom as [$href, $icon, $label]): ?>
        <a class="<?= $isActive($href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
            <span class="ico"><?= $icon ?></span><?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<a class="fab" href="<?= e(url('/client/reminders/create')) ?>" aria-label="<?= t('common.add') ?>">+</a>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
