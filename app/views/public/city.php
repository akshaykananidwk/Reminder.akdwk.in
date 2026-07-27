<?php /** @var string $cityEn */ /** @var string $cityGu */ /** @var array $plans */ ?>
<section class="hero">
    <div class="container">
        <h1>WhatsApp Reminder App for <?= e($cityEn) ?> · <?= e($cityGu) ?></h1>
        <p><?= t('landing.hero_subtitle') ?></p>
        <div class="hero-actions">
            <a class="btn btn-gold" href="<?= e(url('/register')) ?>"><?= t('landing.cta_start') ?></a>
            <a class="btn btn-ghost" href="<?= e(url('/features')) ?>"><?= t('nav.features') ?></a>
        </div>
    </div>
</section>

<section class="section">
    <div class="container container-narrow">
        <h2>Made for businesses in <?= e($cityEn) ?></h2>
        <p>
            Shops, wholesalers, clinics, CA offices and workshops in <?= e($cityEn) ?> run on memory and paper chits.
            Krishna Reminder replaces both: send a plain Gujarati message on WhatsApp, and at the right minute your
            phone rings like a real call and speaks the task out loud.
        </p>

        <div class="grid grid-2 mt-2">
            <div class="card"><h3>💰 ઉઘરાણી ભૂલાય નહીં</h3><p class="text-sm text-muted mb-0">"5 તારીખે રમેશભાઈ પાસેથી 5000 લેવાના" — પેમેન્ટ રિમાઇન્ડર અને હિસાબ, બંને તૈયાર.</p></div>
            <div class="card"><h3>🧾 GST અને બેંક</h3><p class="text-sm text-muted mb-0">દર મહિને ફાઇલિંગ અને ચેકની તારીખ — એક વાર લખો, દર મહિને કોલ આવશે.</p></div>
            <div class="card"><h3>👥 સ્ટાફને કામ સોંપો</h3><p class="text-sm text-muted mb-0">કર્મચારીના મોબાઇલમાં કોલ જાય, અને તમને થયું કે નહીં તે દેખાય.</p></div>
            <div class="card"><h3>🌙 રાત્રે રિપોર્ટ</h3><p class="text-sm text-muted mb-0">દિવસના અંતે WhatsApp પર આખો હિસાબ અને કાલની યાદી.</p></div>
        </div>

        <h2 class="mt-3"><?= t('landing.pricing_title') ?></h2>
        <?php \App\Core\View::partial('public/_plans', ['plans' => $plans]); ?>

        <div class="card mt-3">
            <h3>Local support in Gujarati</h3>
            <p class="text-sm text-muted">AK Computer, Dwarka — call or WhatsApp <?= e((string) setting('company_phone', '')) ?>. We set it up with you.</p>
            <a class="btn btn-green" target="_blank" rel="noopener" href="https://wa.me/<?= e((string) setting('support_whatsapp', '919978123146')) ?>">💬 WhatsApp</a>
        </div>
    </div>
</section>
