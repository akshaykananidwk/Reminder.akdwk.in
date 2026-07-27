<?php /** @var string $phone */ ?>
<h1 style="font-size:1.5rem"><?= t('auth.verify_otp') ?></h1>
<p class="text-muted text-sm"><?= t('auth.otp_sent') ?><br><strong><?= e(display_phone($phone)) ?></strong></p>

<form method="post" action="<?= e(url('/verify')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="field">
        <label for="v-code"><?= t('auth.otp_enter') ?></label>
        <input id="v-code" class="otp-input" name="code" inputmode="numeric" maxlength="6" required autofocus autocomplete="one-time-code">
    </div>

    <button class="btn btn-block"><?= t('auth.verify_otp') ?></button>
</form>

<form method="post" action="<?= e(url('/otp/send')) ?>" class="mt-2">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="phone" value="<?= e($phone) ?>">
    <input type="hidden" name="purpose" value="register">
    <button class="btn btn-ghost btn-block btn-sm" data-otp-resend>
        <?= t('auth.resend_otp') ?> <span data-otp-timer="60"></span>
    </button>
</form>
