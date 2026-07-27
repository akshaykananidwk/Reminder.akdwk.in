<?php /** @var \App\Core\Settings $settings */ ?>
<form class="card" method="post" action="<?= e(url('/admin/settings')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <h3>Site</h3>
    <div class="grid grid-2">
        <div class="field"><label>Site name<input name="site_name" value="<?= e((string) $settings->get('site_name', '')) ?>"></label></div>
        <div class="field"><label>Support WhatsApp<input name="support_whatsapp" value="<?= e((string) $settings->get('support_whatsapp', '')) ?>"></label></div>
        <div class="field"><label>Default language
            <select name="default_language">
                <?php foreach (['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'] as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= (string) $settings->get('default_language') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label></div>
        <div class="field"><label>Default timezone<input name="default_timezone" value="<?= e((string) $settings->get('default_timezone', 'Asia/Kolkata')) ?>"></label></div>
        <div class="field"><label>Currency<input name="currency" value="<?= e((string) $settings->get('currency', 'INR')) ?>" maxlength="3"></label></div>
        <div class="field"><label>Alert admin number<input name="alert_admin_number" value="<?= e((string) $settings->get('alert_admin_number', '')) ?>"></label></div>
    </div>

    <h3 class="mt-3">Company &amp; invoices</h3>
    <div class="grid grid-2">
        <div class="field"><label>Company name<input name="company_name" value="<?= e((string) $settings->get('company_name', '')) ?>"></label></div>
        <div class="field"><label>Address<input name="company_address" value="<?= e((string) $settings->get('company_address', '')) ?>"></label></div>
        <div class="field"><label>Phone<input name="company_phone" value="<?= e((string) $settings->get('company_phone', '')) ?>"></label></div>
        <div class="field"><label>Email<input name="company_email" value="<?= e((string) $settings->get('company_email', '')) ?>"></label></div>
        <div class="field"><label>GSTIN<input name="company_gstin" value="<?= e((string) $settings->get('company_gstin', '')) ?>"></label></div>
        <div class="field"><label>GST rate %<input name="gst_rate" type="number" step="0.01" value="<?= e((string) $settings->get('gst_rate', '18')) ?>"></label></div>
        <div class="field"><label>Invoice prefix<input name="invoice_prefix" value="<?= e((string) $settings->get('invoice_prefix', 'KR')) ?>"></label></div>
        <div class="field"><label>Grace days<input name="grace_days" type="number" value="<?= (int) $settings->int('grace_days', 3) ?>"></label></div>
        <div class="field"><label>Referral commission %<input name="referral_commission_percent" type="number" value="<?= (int) $settings->int('referral_commission_percent', 20) ?>"></label></div>
    </div>

    <h3 class="mt-3">Push (Firebase)</h3>
    <div class="field"><label>FCM project id<input name="fcm_project_id" value="<?= e((string) $settings->get('fcm_project_id', '')) ?>"></label></div>
    <div class="field"><label>Service account JSON <span class="hint">recommended — HTTP v1</span>
        <textarea name="fcm_service_account" rows="5" placeholder="<?= $settings->get('fcm_service_account') ? 'stored — paste again to replace' : 'paste the JSON here' ?>"></textarea></label></div>
    <div class="field"><label>Legacy server key <span class="hint">optional fallback</span>
        <input name="fcm_server_key" type="password" placeholder="<?= $settings->get('fcm_server_key') ? '•••••••• stored' : 'not set' ?>"></label></div>

    <h3 class="mt-3">Google</h3>
    <div class="grid grid-2">
        <div class="field"><label>OAuth client id<input name="google_client_id" value="<?= e((string) $settings->get('google_client_id', '')) ?>"></label></div>
        <div class="field"><label>OAuth client secret<input name="google_client_secret" type="password" placeholder="<?= $settings->get('google_client_secret') ? '•••••••• stored' : 'not set' ?>"></label></div>
        <div class="field"><label>Cloud TTS API key <span class="hint">optional server-side speech</span>
            <input name="google_tts_api_key" type="password" placeholder="<?= $settings->get('google_tts_api_key') ? '•••••••• stored' : 'not set' ?>"></label></div>
    </div>
    <label class="check"><input type="checkbox" name="google_enabled" value="1"<?= $settings->bool('google_enabled') ? ' checked' : '' ?>> Google integration enabled</label>

    <h3 class="mt-3">Android app</h3>
    <div class="grid grid-2">
        <div class="field"><label>Latest version<input name="app_version" value="<?= e((string) $settings->get('app_version', '1.0.0')) ?>"></label></div>
        <div class="field"><label>Minimum version<input name="app_min_version" value="<?= e((string) $settings->get('app_min_version', '1.0.0')) ?>"></label></div>
        <div class="field"><label>APK / release URL<input name="apk_release_url" value="<?= e((string) $settings->get('apk_release_url', '')) ?>"></label></div>
    </div>
    <div class="field"><label>Release notes<textarea name="app_release_notes" rows="3"><?= e((string) $settings->get('app_release_notes', '')) ?></textarea></label></div>
    <label class="check"><input type="checkbox" name="app_force_update" value="1"<?= $settings->bool('app_force_update') ? ' checked' : '' ?>> Force update</label>

    <h3 class="mt-3">SEO</h3>
    <div class="grid grid-2">
        <div class="field"><label>GA4 measurement id<input name="ga4_id" value="<?= e((string) $settings->get('ga4_id', '')) ?>"></label></div>
        <div class="field"><label>Search Console verification<input name="search_console_verification" value="<?= e((string) $settings->get('search_console_verification', '')) ?>"></label></div>
    </div>
    <div class="field"><label>Meta description<textarea name="meta_description" rows="2"><?= e((string) $settings->get('meta_description', '')) ?></textarea></label></div>

    <button class="btn btn-block mt-2"><?= t('common.save') ?></button>
</form>
