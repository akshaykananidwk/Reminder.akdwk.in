<?php $t = static fn (string $k): string => htmlspecialchars(\App\Core\Lang::get($k), ENT_QUOTES, 'UTF-8'); ?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Core\Lang::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404</title>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card text-center">
        <div style="font-size:56px">🔍</div>
        <h1><?= $t('errors.404_title') ?></h1>
        <p class="text-muted"><?= $t('errors.404_body') ?></p>
        <a class="btn" href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= $t('errors.go_home') ?></a>
    </div>
</div>
</body>
</html>
