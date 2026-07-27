<?php

use App\Core\App;

/** @var array $plans */
/** @var array $faqs */
/** @var array $testimonials */
/** @var array $cities */

$settings = App::i()->settings();
$supportWa = (string) $settings->get('support_whatsapp', '919978123146');
?>

<section class="hero">
    <div class="container">
        <span class="badge badge-warning" style="background:rgba(242,179,61,.22);color:#ffe0a3">🙏 જય શ્રી કૃષ્ણ · AK Computer, Dwarka</span>
        <h1 class="mt-2"><?= t('landing.hero_title') ?></h1>
        <p><?= t('landing.hero_subtitle') ?></p>

        <div class="hero-actions">
            <a class="btn btn-gold" href="<?= e(url('/register')) ?>"><?= t('landing.cta_start') ?></a>
            <a class="btn btn-ghost" href="https://wa.me/<?= e($supportWa) ?>?text=<?= rawurlencode('નમસ્તે, મારે કૃષ્ણ રિમાઇન્ડર વિશે જાણવું છે') ?>" target="_blank" rel="noopener">
                💬 WhatsApp <?= e(display_phone($supportWa)) ?>
            </a>
        </div>

        <div class="flex-wrap mt-3" style="gap:22px;font-size:14px;opacity:.9">
            <span>✅ 7 દિવસ ફ્રી</span>
            <span>✅ કાર્ડ વગર</span>
            <span>✅ ગુજરાતી · हिन्दी · English</span>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="text-center"><?= t('landing.how_title') ?></h2>
        <div class="grid grid-3 mt-3">
            <?php foreach ([1, 2, 3] as $step): ?>
                <div class="step-card">
                    <div class="num"><?= $step ?></div>
                    <h3><?= t('landing.step' . $step . '_title') ?></h3>
                    <p class="text-muted text-sm mb-0"><?= t('landing.step' . $step . '_body') ?></p>

                    <?php if ($step === 1): ?>
                        <div class="mt-2" style="background:#DCF8C6;border-radius:12px;padding:11px 13px;font-size:14px;color:#0b3d2c">
                            કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે
                        </div>
                    <?php elseif ($step === 3): ?>
                        <div class="mt-2" style="background:var(--blue);color:#fff;border-radius:12px;padding:13px;font-size:14px">
                            📞 Krishna Reminder<br>
                            <span style="opacity:.8;font-size:13px">ઓફિસ સ્ટાફને કોલ કરવાનો</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" style="background:var(--card)">
    <div class="container">
        <h2 class="text-center"><?= t('landing.features_title') ?></h2>

        <div class="grid grid-3 mt-3">
            <?php
            $features = [
                ['📞', 'Real ringing call', 'Full-screen call over the lock screen with your ringtone — not a notification you can miss.'],
                ['🗣️', 'Speaks Gujarati', 'The app reads the reminder aloud in Gujarati, Hindi or English.'],
                ['💰', 'Payment ledger', 'Party name, amount, due date, partial payments and monthly totals.'],
                ['🔁', 'Any repetition', 'Daily, weekly, monthly on the 5th, every N days — described in plain language.'],
                ['👥', 'Assign to staff', 'Give a task to an employee; they get the call, you see done/missed.'],
                ['🌙', 'Night summary', 'Every evening on WhatsApp: done, pending, missed, paid, tomorrow.'],
                ['📅', 'Google Calendar', 'Optional two-way sync. Everything works perfectly without it too.'],
                ['📶', 'Works offline', 'Local alarms fire without internet; actions sync when you are back.'],
                ['🔒', 'Only your number', 'Messages are accepted only from your verified WhatsApp number.'],
            ];

            foreach ($features as [$icon, $heading, $body]):
            ?>
                <div class="feature">
                    <span class="ico"><?= $icon ?></span>
                    <h3><?= e($heading) ?></h3>
                    <p><?= e($body) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="text-center"><?= t('landing.pricing_title') ?></h2>
        <?php \App\Core\View::partial('public/_plans', ['plans' => $plans]); ?>
    </div>
</section>

<?php if ($testimonials !== []): ?>
<section class="section" style="background:var(--card)">
    <div class="container">
        <h2 class="text-center"><?= t('landing.testimonials_title') ?></h2>
        <div class="grid grid-3 mt-3">
            <?php foreach ($testimonials as $item): ?>
                <div class="testimonial">
                    <div class="stars"><?= str_repeat('★', max(1, min(5, (int) $item['rating']))) ?></div>
                    <p class="mt-1 mb-0"><?= e($item['body']) ?></p>
                    <div class="who">
                        <strong><?= e($item['name']) ?></strong>
                        <?= $item['business'] ? ' · ' . e($item['business']) : '' ?>
                        <?= $item['city'] ? ' · ' . e($item['city']) : '' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($faqs !== []): ?>
<section class="section">
    <div class="container container-narrow">
        <h2 class="text-center"><?= t('landing.faq_title') ?></h2>
        <div class="faq mt-3">
            <?php foreach ($faqs as $faq): ?>
                <details>
                    <summary><?= e($faq['question']) ?></summary>
                    <p><?= e($faq['answer']) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<script type="application/ld+json">
<?= json_encode([
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(static fn ($faq) => [
        '@type'          => 'Question',
        'name'           => $faq['question'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
    ], $faqs),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<?php endif; ?>

<section class="section" style="background:var(--blue);color:#fff">
    <div class="container text-center">
        <h2 style="color:#fff"><?= t('landing.download_title') ?></h2>
        <p style="opacity:.9;max-width:560px;margin:0 auto 20px"><?= t('landing.download_body') ?></p>
        <a class="btn btn-gold" href="<?= e(url('/download')) ?>">📥 <?= t('nav.download') ?></a>
    </div>
</section>

<section class="section">
    <div class="container text-center">
        <h3>Krishna Reminder in your city</h3>
        <div class="flex-wrap mt-2" style="justify-content:center;gap:8px">
            <?php foreach ($cities as $slug => [$english, $gujarati]): ?>
                <a class="chip" href="<?= e(url('/reminder-app/' . $slug)) ?>"><?= e($gujarati) ?> · <?= e($english) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
