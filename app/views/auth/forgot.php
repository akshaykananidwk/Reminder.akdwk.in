<h1 style="font-size:1.5rem"><?= t('auth.forgot_password') ?></h1>
<p class="text-muted text-sm"><?= t('auth.phone_help') ?></p>

<form method="post" action="<?= e(url('/forgot-password')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="field">
        <label for="f-phone"><?= t('common.phone') ?></label>
        <input id="f-phone" name="phone" type="tel" inputmode="numeric" required autofocus>
    </div>

    <button class="btn btn-block"><?= t('auth.send_otp') ?></button>
</form>

<p class="text-center text-sm mt-2"><a href="<?= e(url('/login')) ?>">← <?= t('nav.login') ?></a></p>
