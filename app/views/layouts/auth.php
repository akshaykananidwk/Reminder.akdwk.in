<?php

use App\Core\App;
use App\Core\Lang;

/** @var string $content */

$settings = App::i()->settings();
?>
<!doctype html>
<html lang="<?= e(Lang::locale()) ?>" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($title ?? 'Login') . ' · ' . (string) $settings->get('site_name', 'Krishna Reminder')) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1B3A6B">
<meta name="base-url" content="<?= e(App::i()->url()) ?>">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Anek+Gujarati:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body lang="<?= e(Lang::locale()) ?>">
<div class="auth-wrap">
    <div class="auth-card">
        <a class="logo" href="<?= e(url('/')) ?>">
            <span class="mark">🕉️</span>
            <span><?= e((string) $settings->get('site_name', 'Krishna Reminder')) ?></span>
        </a>

        <?php foreach ($flash ?? [] as $item): ?>
            <div class="alert alert-<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
        <?php endforeach; ?>

        <?= $content ?>

        <div class="text-center mt-3 text-sm">
            <?php foreach (Lang::SUPPORTED as $code): ?>
                <a href="?lang=<?= e($code) ?>" class="<?= $code === Lang::locale() ? 'text-muted' : '' ?>"><?= e(Lang::nativeName($code)) ?></a>
                <?= $code !== 'en' ? ' · ' : '' ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
