<?php /** @var array $post */ ?>
<article class="section">
    <div class="container container-narrow">
        <a class="text-sm" href="<?= e(url('/blog')) ?>">← <?= t('nav.blog') ?></a>
        <h1 class="mt-2"><?= e($post['title']) ?></h1>
        <p class="text-sm text-muted"><?= e(to_user_time((string) ($post['published_at'] ?: $post['created_at']), 'd M Y')) ?></p>

        <?php if (!empty($post['cover_image'])): ?>
            <img src="<?= e($post['cover_image']) ?>" alt="" style="border-radius:var(--radius);margin-bottom:18px">
        <?php endif; ?>

        <div class="card card-pad-lg"><?= strip_tags((string) $post['body'], '<p><br><h2><h3><h4><ul><ol><li><strong><em><a><blockquote><code><pre><img><table><thead><tbody><tr><th><td><hr>') ?></div>
    </div>
</article>
