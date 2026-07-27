<?php /** @var array $plans */ /** @var array $faqs */ ?>
<section class="section">
    <div class="container">
        <h1 class="text-center"><?= t('landing.pricing_title') ?></h1>
        <?php \App\Core\View::partial('public/_plans', ['plans' => $plans]); ?>

        <?php if (!empty($faqs)): ?>
            <div class="container-narrow mt-3" style="padding:0">
                <h2 class="text-center"><?= t('landing.faq_title') ?></h2>
                <div class="faq mt-2">
                    <?php foreach ($faqs as $faq): ?>
                        <details><summary><?= e($faq['question']) ?></summary><p><?= e($faq['answer']) ?></p></details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
