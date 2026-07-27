<?php

use App\Core\App;
use App\Core\Lang;

/** @var string $content */
/** @var array $flash */

$settings = App::i()->settings();
$locale = Lang::locale();
$title = $title ?? (string) $settings->get('site_name', 'Krishna Reminder');
$metaDescription = $metaDescription ?? (string) $settings->get('meta_description', '');
$ga4 = (string) $settings->get('ga4_id', '');
$supportWa = (string) $settings->get('support_whatsapp', '919978123146');
$canonical = $canonical ?? App::i()->url(\App\Core\Request::path());
?>
<!doctype html>
<html lang="<?= e($locale) ?>" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#1B3A6B">
<meta name="base-url" content="<?= e(App::i()->url()) ?>">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e((string) $settings->get('site_name', 'Krishna Reminder')) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e(asset('img/og-cover.svg')) ?>">
<meta name="twitter:card" content="summary_large_image">

<?php if ($verification = (string) $settings->get('search_console_verification', '')): ?>
<meta name="google-site-verification" content="<?= e($verification) ?>">
<?php endif; ?>

<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(App::i()->url('/manifest.webmanifest')) ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Anek+Gujarati:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

<script type="application/ld+json">
<?= json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'SoftwareApplication',
    'name'        => (string) $settings->get('site_name', 'Krishna Reminder'),
    'operatingSystem' => 'Android, Web',
    'applicationCategory' => 'ProductivityApplication',
    'description' => $metaDescription,
    'url'         => App::i()->url(),
    'offers'      => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR'],
    'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '4.9', 'ratingCount' => '128'],
    'publisher'   => [
        '@type'   => 'Organization',
        'name'    => (string) $settings->get('company_name', 'AK Computer'),
        'address' => (string) $settings->get('company_address', ''),
        'telephone' => (string) $settings->get('company_phone', ''),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>

<?php if ($ga4 !== ''): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga4) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= e($ga4) ?>');</script>
<?php endif; ?>
</head>
<body lang="<?= e($locale) ?>">

<header class="site-header">
    <div class="container">
        <a class="logo" href="<?= e(url('/')) ?>">
            <span class="mark">🕉️</span>
            <span><?= e((string) $settings->get('site_name', 'Krishna Reminder')) ?></span>
        </a>

        <nav class="main-nav desktop-only">
            <a href="<?= e(url('/features')) ?>"><?= t('nav.features') ?></a>
            <a href="<?= e(url('/pricing')) ?>"><?= t('nav.pricing') ?></a>
            <a href="<?= e(url('/blog')) ?>"><?= t('nav.blog') ?></a>
            <a href="<?= e(url('/contact')) ?>"><?= t('nav.contact') ?></a>
        </nav>

        <div class="flex">
            <button class="icon-btn" data-action="toggle-theme" title="Dark mode" aria-label="Toggle dark mode">◐</button>

            <select class="desktop-only" style="width:auto;min-height:38px;padding:6px 10px;font-size:13.5px"
                    onchange="location.href='?lang='+this.value">
                <?php foreach (Lang::SUPPORTED as $code): ?>
                    <option value="<?= e($code) ?>"<?= $code === $locale ? ' selected' : '' ?>><?= e(Lang::nativeName($code)) ?></option>
                <?php endforeach; ?>
            </select>

            <?php if (($authUser ?? null) !== null): ?>
                <a class="btn btn-sm" href="<?= e(url('/client')) ?>"><?= t('nav.dashboard') ?></a>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm desktop-only" href="<?= e(url('/login')) ?>"><?= t('nav.login') ?></a>
                <a class="btn btn-gold btn-sm" href="<?= e(url('/register')) ?>"><?= t('nav.register') ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if (!empty($flash)): ?>
    <div class="container mt-2">
        <?php foreach ($flash as $item): ?>
            <div class="alert alert-<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main><?= $content ?></main>

<footer class="site-footer">
    <div class="container">
        <div class="grid grid-4">
            <div>
                <h4><?= e((string) $settings->get('site_name', 'Krishna Reminder')) ?></h4>
                <p style="font-size:14px"><?= t('app.tagline') ?></p>
            </div>
            <div>
                <h4><?= t('nav.features') ?></h4>
                <ul>
                    <li><a href="<?= e(url('/features')) ?>"><?= t('nav.features') ?></a></li>
                    <li><a href="<?= e(url('/pricing')) ?>"><?= t('nav.pricing') ?></a></li>
                    <li><a href="<?= e(url('/download')) ?>"><?= t('nav.download') ?></a></li>
                    <li><a href="<?= e(url('/faq')) ?>">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4>Legal</h4>
                <ul>
                    <li><a href="<?= e(url('/p/privacy-policy')) ?>">Privacy Policy</a></li>
                    <li><a href="<?= e(url('/p/terms')) ?>">Terms of Service</a></li>
                    <li><a href="<?= e(url('/p/refund-policy')) ?>">Refund Policy</a></li>
                    <li><a href="<?= e(url('/p/cancellation-policy')) ?>">Cancellation Policy</a></li>
                </ul>
            </div>
            <div>
                <h4><?= t('nav.contact') ?></h4>
                <ul>
                    <li><?= e((string) $settings->get('company_name', 'AK Computer')) ?></li>
                    <li><?= e((string) $settings->get('company_address', '')) ?></li>
                    <li><a href="tel:<?= e((string) $settings->get('company_phone', '')) ?>"><?= e((string) $settings->get('company_phone', '')) ?></a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom flex-between">
            <span>© <?= date('Y') ?> <?= e((string) $settings->get('company_name', 'AK Computer')) ?>. 🙏 જય શ્રી કૃષ્ણ</span>
            <span>v<?= e((string) App::i()->config('app.version', '1.0.0')) ?></span>
        </div>
    </div>
</footer>

<a class="wa-float" href="https://wa.me/<?= e($supportWa) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">💬</a>

<div class="cookie-banner hide">
    <span class="grow">We use only essential cookies to keep you logged in.</span>
    <button class="btn btn-sm" data-accept-cookies>OK</button>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
