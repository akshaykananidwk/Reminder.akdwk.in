<?php /** @var string $apkUrl */ /** @var string $appVersion */ ?>
<section class="section">
    <div class="container container-narrow text-center">
        <div style="font-size:64px">📱</div>
        <h1><?= t('landing.download_title') ?></h1>
        <p class="text-muted"><?= t('landing.download_body') ?></p>

        <a class="btn btn-gold" href="<?= e($apkUrl) ?>" target="_blank" rel="noopener">
            📥 Download APK (v<?= e($appVersion) ?>)
        </a>

        <div class="card mt-3 text-left">
            <h3>Install in four steps</h3>
            <ol>
                <li>Tap the download button — the APK comes from our GitHub Releases page.</li>
                <li>Open the file and allow "Install unknown apps" for your browser when Android asks.</li>
                <li>Open Krishna Reminder, choose your language and log in with your WhatsApp number.</li>
                <li>Accept the permission wizard — <strong>exact alarms</strong>, <strong>full-screen notifications</strong> and
                    <strong>battery-optimisation exemption</strong> are what make the phone ring reliably.</li>
            </ol>

            <h3 class="mt-3">Xiaomi, Oppo, Vivo, Realme, Samsung</h3>
            <p class="text-sm text-muted mb-0">
                These phones stop background apps aggressively. The app links you straight to the right
                Autostart / Battery screen during setup — please allow it there, otherwise reminders may arrive late.
            </p>
        </div>

        <p class="text-sm text-muted mt-2">iOS app is not available yet. On iPhone use the web dashboard and WhatsApp reminders.</p>
    </div>
</section>
