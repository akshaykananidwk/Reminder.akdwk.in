<?php /** @var array $pages */ /** @var array $posts */ /** @var array $faqs */ /** @var array $messages */ ?>
<div class="grid grid-2 mb-2">
    <form class="card" method="post" action="<?= e(url('/admin/content/page')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Page</h3>
        <div class="field"><label>Slug<input name="slug" required placeholder="privacy-policy"></label></div>
        <div class="field"><label>Title<input name="title" required></label></div>
        <div class="field"><label>Body (HTML)<textarea name="body" rows="8"></textarea></label></div>
        <div class="field"><label>Meta description<input name="meta_description" maxlength="300"></label></div>
        <label class="check"><input type="checkbox" name="is_published" value="1" checked> Published</label>
        <button class="btn"><?= t('common.save') ?></button>

        <h4 class="mt-2">Existing</h4>
        <ul class="list">
            <?php foreach ($pages as $page): ?>
                <li class="list-item">
                    <div class="body"><div class="title text-sm"><?= e((string) $page['title']) ?></div>
                        <div class="meta"><code>/p/<?= e((string) $page['slug']) ?></code></div></div>
                    <a class="btn btn-sm btn-ghost" target="_blank" href="<?= e(url('/p/' . $page['slug'])) ?>">↗</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </form>

    <form class="card" method="post" action="<?= e(url('/admin/content/post')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>Blog post</h3>
        <div class="field"><label>Title<input name="title" required></label></div>
        <div class="field"><label>Slug <span class="hint">blank = from title</span><input name="slug"></label></div>
        <div class="field"><label>Excerpt<textarea name="excerpt" rows="2" maxlength="500"></textarea></label></div>
        <div class="field"><label>Body (HTML)<textarea name="body" rows="8"></textarea></label></div>
        <div class="field"><label>Language
            <select name="lang"><option value="gu">ગુજરાતી</option><option value="hi">हिन्दी</option><option value="en">English</option></select></label></div>
        <label class="check"><input type="checkbox" name="is_published" value="1" checked> Published</label>
        <button class="btn"><?= t('common.save') ?></button>

        <h4 class="mt-2">Existing</h4>
        <ul class="list">
            <?php foreach ($posts as $post): ?>
                <li class="list-item">
                    <div class="body"><div class="title text-sm"><?= e((string) $post['title']) ?></div>
                        <div class="meta"><?= (int) $post['views'] ?> views · <?= e((string) $post['lang']) ?></div></div>
                    <a class="btn btn-sm btn-ghost" target="_blank" href="<?= e(url('/blog/' . $post['slug'])) ?>">↗</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </form>
</div>

<div class="grid grid-2">
    <form class="card" method="post" action="<?= e(url('/admin/content/faq')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3>FAQ</h3>
        <div class="field"><label>Question<input name="question" required maxlength="300"></label></div>
        <div class="field"><label>Answer<textarea name="answer" rows="4" required></textarea></label></div>
        <div class="grid grid-2">
            <div class="field"><label>Language
                <select name="lang"><option value="gu">ગુજરાતી</option><option value="hi">हिन्दी</option><option value="en">English</option></select></label></div>
            <div class="field"><label>Sort<input name="sort_order" type="number" value="0"></label></div>
        </div>
        <label class="check"><input type="checkbox" name="is_published" value="1" checked> Published</label>
        <button class="btn"><?= t('common.save') ?></button>

        <ul class="list mt-2">
            <?php foreach ($faqs as $faq): ?>
                <li class="list-item"><div class="body"><div class="title text-sm"><?= e((string) $faq['question']) ?></div>
                    <div class="meta"><?= e((string) $faq['lang']) ?> · #<?= (int) $faq['sort_order'] ?></div></div></li>
            <?php endforeach; ?>
        </ul>
    </form>

    <div class="card">
        <h3>Contact messages</h3>
        <ul class="list">
            <?php foreach ($messages as $message): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e((string) $message['name']) ?> — <?= e((string) $message['subject']) ?></div>
                        <div class="meta"><?= e(display_phone((string) $message['phone'])) ?> · <?= e(human_diff((string) $message['created_at'])) ?></div>
                        <p class="text-sm mt-1 mb-0"><?= e(str_limit((string) $message['message'], 200)) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if ($messages === []): ?><li class="list-item text-sm text-muted">No messages.</li><?php endif; ?>
        </ul>
    </div>
</div>
