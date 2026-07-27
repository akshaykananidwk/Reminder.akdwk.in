<?php /** @var array $posts */ ?>
<section class="section">
    <div class="container">
        <h1><?= t('nav.blog') ?></h1>

        <?php if ($posts === []): ?>
            <div class="empty"><div class="icon">📝</div><h3><?= t('common.no_data') ?></h3></div>
        <?php else: ?>
            <div class="grid grid-3 mt-2">
                <?php foreach ($posts as $post): ?>
                    <article class="card">
                        <h3><a href="<?= e(url('/blog/' . $post['slug'])) ?>"><?= e($post['title']) ?></a></h3>
                        <p class="text-sm text-muted"><?= e(str_limit((string) $post['excerpt'], 140)) ?></p>
                        <div class="text-xs text-muted">
                            <?= e(to_user_time((string) ($post['published_at'] ?: $post['created_at']), 'd M Y')) ?>
                            · <?= (int) $post['views'] ?> views
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
