<h1 style="font-size:1.5rem"><?= t('auth.register_title') ?></h1>
<p class="text-muted text-sm"><?= t('auth.register_subtitle') ?></p>

<form method="post" action="<?= e(url('/register')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="ref" value="<?= e((string) \App\Core\Request::get('ref', '')) ?>">

    <div class="field">
        <label for="r-name"><?= t('common.name') ?></label>
        <input id="r-name" name="name" required maxlength="120" autocomplete="name" value="<?= old('name') ?>">
    </div>

    <div class="field">
        <label for="r-phone"><?= t('common.phone') ?></label>
        <input id="r-phone" name="phone" type="tel" required inputmode="numeric" autocomplete="tel"
               placeholder="9978123146" value="<?= old('phone') ?>">
        <span class="hint"><?= t('auth.phone_help') ?></span>
    </div>

    <div class="field">
        <label for="r-email"><?= t('common.email') ?> <span class="hint"><?= t('common.optional') ?></span></label>
        <input id="r-email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= old('email') ?>">
    </div>

    <div class="field">
        <label for="r-password"><?= t('common.password') ?></label>
        <input id="r-password" name="password" type="password" required minlength="8" autocomplete="new-password">
        <span class="hint">At least 8 characters, with letters and numbers.</span>
    </div>

    <div class="field">
        <label for="r-language"><?= t('common.language') ?></label>
        <select id="r-language" name="language">
            <option value="gu" selected>ગુજરાતી</option>
            <option value="hi">हिन्दी</option>
            <option value="en">English</option>
        </select>
    </div>

    <div class="field">
        <label for="r-city"><?= t('common.city') ?> <span class="hint"><?= t('common.optional') ?></span></label>
        <input id="r-city" name="city" maxlength="120" value="<?= old('city') ?>">
    </div>

    <input type="hidden" name="timezone" id="r-tz" value="Asia/Kolkata">

    <button class="btn btn-block"><?= t('auth.send_otp') ?></button>

    <p class="text-xs text-muted text-center mt-2"><?= t('auth.agree_terms') ?></p>
</form>

<div class="divider-text"><?= t('auth.or') ?></div>

<p class="text-center text-sm"><?= t('auth.have_account') ?> <a href="<?= e(url('/login')) ?>"><?= t('nav.login') ?></a></p>

<script>
try {
    var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz) document.getElementById('r-tz').value = tz;
} catch (e) { /* keep the default */ }
</script>
