<?php /** @var array $faqs */ ?>
<section class="section">
    <div class="container container-narrow">
        <h1><?= t('landing.faq_title') ?></h1>
        <div class="faq mt-2">
            <?php foreach ($faqs as $faq): ?>
                <details><summary><?= e($faq['question']) ?></summary><p><?= e($faq['answer']) ?></p></details>
            <?php endforeach; ?>
        </div>
        <div class="card mt-3">
            <h3>Still stuck?</h3>
            <p class="text-sm text-muted">Message us on WhatsApp — we usually reply the same day.</p>
            <a class="btn btn-green" target="_blank" rel="noopener" href="https://wa.me/<?= e((string) setting('support_whatsapp', '919978123146')) ?>">💬 WhatsApp</a>
        </div>
    </div>
</section>
