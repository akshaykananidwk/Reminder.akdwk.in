<?php /** @var array $page */ ?>
<section class="section">
    <div class="container container-narrow">
        <h1><?= e($page['title']) ?></h1>
        <div class="card card-pad-lg"><?= strip_tags((string) $page['body'], '<p><br><h2><h3><h4><ul><ol><li><strong><em><a><blockquote><code><pre><table><thead><tbody><tr><th><td><hr>') ?></div>
        <p class="text-sm text-muted mt-2">Last updated <?= e(to_user_time((string) $page['updated_at'], 'd M Y')) ?></p>
    </div>
</section>
