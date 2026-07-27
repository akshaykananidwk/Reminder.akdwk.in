<?php
/**
 * @var array|null $error Populated only when a signed-in admin hit the error.
 */
$t = static fn (string $k): string => htmlspecialchars(\App\Core\Lang::get($k), ENT_QUOTES, 'UTF-8');
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$error = $error ?? null;
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Core\Lang::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>500</title>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
<style>
    .err-detail { max-width: 900px; margin: 18px auto 0; text-align: left; }
    .err-detail pre {
        background: #10192b; color: #e6edf7; padding: 14px; border-radius: 12px;
        overflow-x: auto; font-size: 12.5px; line-height: 1.55; white-space: pre;
    }
    .err-where { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; word-break: break-all; }
</style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card text-center">
        <div style="font-size:56px">🛠️</div>
        <h1><?= $t('errors.500_title') ?></h1>

        <?php if ($error === null): ?>
            <p class="text-muted"><?= $t('errors.500_body') ?></p>
        <?php else: ?>
            <p class="text-muted">You are seeing the details because you are signed in as an admin.</p>

            <div class="err-detail">
                <div class="alert danger">
                    <strong><?= $h((string) $error['type']) ?></strong><br>
                    <?= $h((string) $error['message']) ?>
                </div>

                <p class="err-where"><?= $h((string) $error['file']) ?>:<?= (int) $error['line'] ?></p>

                <details>
                    <summary class="text-sm text-muted">Stack trace</summary>
                    <pre><?= $h((string) $error['trace']) ?></pre>
                </details>

                <p class="text-sm text-muted">Full log: <code><?= $h((string) $error['log']) ?></code></p>

                <div class="alert warn text-sm">
                    <strong>Did this appear right after an update?</strong>
                    Then it is almost certainly cached bytecode. PHP can keep serving the old
                    compiled version of a file for up to a minute after the file is replaced, so
                    the site briefly runs a mix of the old and new release. Wait a minute and
                    reload, or restart PHP-FPM. The updater clears that cache itself now, and
                    <em>Admin → Updates</em> records whether it succeeded — some hosts disable
                    <code>opcache_reset()</code>.
                </div>
            </div>
        <?php endif; ?>

        <a class="btn" href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= $t('errors.go_home') ?></a>
    </div>
</div>
</body>
</html>
