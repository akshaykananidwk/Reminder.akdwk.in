<?php /** @var string $phone */ ?>
<h1 style="font-size:1.5rem"><?= t('auth.reset_password') ?></h1>
<p class="text-muted text-sm"><?= t('auth.otp_sent') ?><br><strong><?= e(display_phone($phone)) ?></strong></p>

<form method="post" action="<?= e(url('/reset-password')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="field">
        <label for="rp-code"><?= t('auth.otp_enter') ?></label>
        <input id="rp-code" class="otp-input" name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code">
    </div>

    <div class="field">
        <label for="rp-pw"><?= t('common.password') ?></label>
        <input id="rp-pw" name="password" type="password" required minlength="8" autocomplete="new-password">
    </div>

    <div class="field">
        <label for="rp-pw2"><?= t('common.password') ?> (again)</label>
        <input id="rp-pw2" name="password_confirm" type="password" required minlength="8" autocomplete="new-password">
    </div>

    <button class="btn btn-block"><?= t('auth.reset_password') ?></button>
</form>
