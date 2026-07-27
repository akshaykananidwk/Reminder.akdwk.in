<h1 style="font-size:1.5rem"><?= t('auth.login_title') ?></h1>
<p class="text-muted text-sm"><?= t('auth.login_subtitle') ?></p>

<div class="chips mb-2">
    <button type="button" class="chip active" id="tab-password"><?= t('auth.login_with_password') ?></button>
    <button type="button" class="chip" id="tab-otp"><?= t('auth.login_with_otp') ?></button>
</div>

<form method="post" action="<?= e(url('/login')) ?>" id="form-password">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="field">
        <label for="l-id"><?= t('common.phone') ?> / <?= t('common.email') ?></label>
        <input id="l-id" name="identifier" required autocomplete="username" value="<?= old('identifier') ?>">
    </div>

    <div class="field">
        <label for="l-pw"><?= t('common.password') ?></label>
        <input id="l-pw" name="password" type="password" required autocomplete="current-password">
    </div>

    <label class="check"><input type="checkbox" name="remember" value="1" checked> <?= t('auth.remember_me') ?></label>

    <button class="btn btn-block"><?= t('nav.login') ?></button>

    <p class="text-center text-sm mt-2"><a href="<?= e(url('/forgot-password')) ?>"><?= t('auth.forgot_password') ?></a></p>
</form>

<form method="post" action="<?= e(url('/login/otp')) ?>" id="form-otp" class="hide">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="field">
        <label for="o-phone"><?= t('common.phone') ?></label>
        <input id="o-phone" name="phone" type="tel" inputmode="numeric" required placeholder="9978123146">
    </div>

    <button type="button" class="btn btn-ghost btn-block" id="send-otp" data-otp-resend><?= t('auth.send_otp') ?></button>

    <div class="field mt-2">
        <label for="o-code"><?= t('auth.otp_enter') ?></label>
        <input id="o-code" class="otp-input" name="code" inputmode="numeric" maxlength="6" required>
        <span class="hint" data-otp-holder></span>
    </div>

    <button class="btn btn-block"><?= t('auth.verify_otp') ?></button>
</form>

<div class="divider-text"><?= t('auth.or') ?></div>

<p class="text-center text-sm"><?= t('auth.no_account') ?> <a href="<?= e(url('/register')) ?>"><?= t('nav.register') ?></a></p>

<script>
(function () {
    var tabPassword = document.getElementById('tab-password');
    var tabOtp = document.getElementById('tab-otp');
    var formPassword = document.getElementById('form-password');
    var formOtp = document.getElementById('form-otp');

    tabPassword.addEventListener('click', function () {
        tabPassword.classList.add('active');
        tabOtp.classList.remove('active');
        formPassword.classList.remove('hide');
        formOtp.classList.add('hide');
    });

    tabOtp.addEventListener('click', function () {
        tabOtp.classList.add('active');
        tabPassword.classList.remove('active');
        formOtp.classList.remove('hide');
        formPassword.classList.add('hide');
    });

    document.getElementById('send-otp').addEventListener('click', async function () {
        var button = this;
        var phone = document.getElementById('o-phone').value.trim();

        if (!phone) { KR.toast('<?= e(__('auth.invalid_number')) ?>', 'error'); return; }

        KR.busy(button, true);

        try {
            var result = await KR.request('<?= e(url('/otp/send')) ?>', { json: { phone: phone, purpose: 'login' } });
            KR.toast(result.message, 'success');

            var holder = document.querySelector('[data-otp-holder]');
            var seconds = (result.data && result.data.retry_after) || 60;
            holder.textContent = seconds + 's';

            var timer = setInterval(function () {
                seconds--;
                holder.textContent = seconds > 0 ? seconds + 's' : '';
                if (seconds <= 0) { clearInterval(timer); KR.busy(button, false); }
            }, 1000);
        } catch (error) {
            KR.toast(error.message, 'error');
            KR.busy(button, false);
        }
    });
})();
</script>
