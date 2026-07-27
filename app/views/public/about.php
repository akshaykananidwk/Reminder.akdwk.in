<section class="section">
    <div class="container container-narrow">
        <h1><?= t('nav.about') ?></h1>
        <p>
            Krishna Reminder is built by <strong>AK Computer</strong> in Dwarka, Gujarat, for the way business
            actually works here: a shopkeeper with a WhatsApp keyboard in Gujarati, a CA juggling filing dates,
            a clinic that must call patients back, a family that must not forget the medicine.
        </p>
        <p>
            We started from one observation. People already write their to-do list on WhatsApp — to themselves,
            to their staff, to nobody. What they lack is something that <em>reads</em> it, remembers it, and then
            actually gets their attention at the right minute. A notification does not do that. A ringing phone does.
        </p>
        <h2>How we think about it</h2>
        <ul>
            <li><strong>Gujarati first.</strong> Not a translated afterthought — the default language of the product.</li>
            <li><strong>No new habit.</strong> If you can send a WhatsApp message, you can use the whole product.</li>
            <li><strong>Nothing lost.</strong> Offline alarms, reboot recovery, 30-day trash, nightly backups.</li>
            <li><strong>Honest about cost.</strong> AI usage is metered per account so a runaway bill is impossible.</li>
        </ul>
        <h2><?= t('landing.contact_title') ?></h2>
        <p>
            AK Computer<br>
            Shreeji Shopping Center, near City Palace Hotel, Dwarka, Gujarat<br>
            <a href="tel:+919978123146">+91 99781 23146</a>
        </p>
        <a class="btn btn-gold" href="<?= e(url('/register')) ?>"><?= t('landing.cta_start') ?></a>
    </div>
</section>
