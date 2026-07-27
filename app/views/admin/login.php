<h1 style="font-size:1.5rem">Admin login</h1>
<p class="text-muted text-sm">Krishna Reminder control panel</p>

<form method="post" action="<?= e(url('/admin/login')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="field"><label for="a-email"><?= t('common.email') ?></label><input id="a-email" name="email" type="email" required autocomplete="username" autofocus></div>
    <div class="field"><label for="a-pw"><?= t('common.password') ?></label><input id="a-pw" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn btn-block"><?= t('nav.login') ?></button>
</form>
