<?php /** @var array $contacts */ ?>
<div class="grid grid-2">
    <form class="card" method="post" action="<?= e(url('/client/contacts')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3><?= t('common.add') ?></h3>

        <div class="field"><label for="ct-name"><?= t('common.name') ?></label><input id="ct-name" name="name" required maxlength="120"></div>
        <div class="field"><label for="ct-phone"><?= t('common.phone') ?></label><input id="ct-phone" name="phone" type="tel" inputmode="numeric"></div>
        <div class="field"><label for="ct-role"><?= t('contacts.role') ?></label><input id="ct-role" name="role" maxlength="80" placeholder="Staff, supplier, customer…"></div>
        <div class="field"><label for="ct-email"><?= t('common.email') ?></label><input id="ct-email" name="email" type="email"></div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
        <p class="hint mt-1">If the number belongs to another Krishna Reminder user, tasks can be assigned to them.</p>
    </form>

    <div class="card">
        <h3><?= t('contacts.title') ?></h3>

        <?php if ($contacts === []): ?>
            <div class="empty"><div class="icon">👥</div><h3><?= t('contacts.none') ?></h3></div>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($contacts as $contact): ?>
                    <li class="list-item">
                        <div class="body">
                            <div class="title"><?= e($contact['name']) ?>
                                <?php if (!empty($contact['linked_user_id'])): ?>
                                    <span class="badge badge-success">staff</span>
                                <?php endif; ?>
                            </div>
                            <div class="meta"><?= e(display_phone((string) $contact['phone'])) ?> <?= $contact['role'] ? '· ' . e($contact['role']) : '' ?></div>
                        </div>
                        <form method="post" action="<?= e(url('/client/contacts/' . (int) $contact['id'] . '/delete')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" data-action="confirm" data-message="<?= t('common.confirm') ?>">🗑</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
