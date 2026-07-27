<section class="section">
    <div class="container">
        <div class="grid grid-2">
            <div>
                <h1><?= t('landing.contact_title') ?></h1>
                <p class="text-muted">Message us on WhatsApp for the fastest answer, or use the form.</p>

                <div class="card mb-2">
                    <h3>AK Computer</h3>
                    <p class="text-sm mb-1"><?= e((string) setting('company_address', '')) ?></p>
                    <p class="text-sm mb-1">📞 <a href="tel:<?= e((string) setting('company_phone', '')) ?>"><?= e((string) setting('company_phone', '')) ?></a></p>
                    <p class="text-sm mb-0">✉️ <a href="mailto:<?= e((string) setting('company_email', '')) ?>"><?= e((string) setting('company_email', '')) ?></a></p>
                </div>

                <a class="btn btn-green btn-block" target="_blank" rel="noopener"
                   href="https://wa.me/<?= e((string) setting('support_whatsapp', '919978123146')) ?>">💬 Chat on WhatsApp</a>
            </div>

            <form class="card" method="post" action="<?= e(url('/contact')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                <div class="field">
                    <label for="c-name"><?= t('common.name') ?></label>
                    <input id="c-name" name="name" required maxlength="120" value="<?= old('name') ?>">
                </div>

                <div class="field">
                    <label for="c-phone"><?= t('common.phone') ?></label>
                    <input id="c-phone" name="phone" type="tel" maxlength="20" value="<?= old('phone') ?>">
                </div>

                <div class="field">
                    <label for="c-email"><?= t('common.email') ?> <span class="hint"><?= t('common.optional') ?></span></label>
                    <input id="c-email" name="email" type="email" maxlength="190" value="<?= old('email') ?>">
                </div>

                <div class="field">
                    <label for="c-subject">Subject</label>
                    <input id="c-subject" name="subject" maxlength="190" value="<?= old('subject') ?>">
                </div>

                <div class="field">
                    <label for="c-message">Message</label>
                    <textarea id="c-message" name="message" required minlength="5" maxlength="3000"><?= old('message') ?></textarea>
                </div>

                <button class="btn btn-block"><?= t('common.submit') ?></button>
            </form>
        </div>
    </div>
</section>
