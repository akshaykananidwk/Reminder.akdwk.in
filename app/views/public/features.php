<section class="section">
    <div class="container">
        <h1><?= t('landing.features_title') ?></h1>
        <p class="text-muted"><?= t('landing.hero_subtitle') ?></p>

        <?php
        $groups = [
            'Reminders that cannot be missed' => [
                ['Ringing call at the exact minute', 'A full-screen call over the lock screen, with your chosen ringtone and vibration — three attempts two minutes apart if you do not answer.'],
                ['Speaks the reminder aloud', 'Gujarati, Hindi or English text-to-speech, twice with a pause. Falls back to a server-generated MP3 if your phone lacks the voice.'],
                ['Advance alerts', 'Silent nudges 1 day, 1 hour, 30 or 10 minutes before — configurable per reminder.'],
                ['Smart snooze', 'Snooze 5/10/15/30/60 minutes or a custom time. After five snoozes the app asks you to reschedule properly.'],
                ['Works offline', 'Every synced occurrence also gets a local exact alarm, so it fires with no internet and after a reboot.'],
                ['Do-not-disturb window', 'Quiet hours and holiday mode. Urgent reminders always ring.'],
            ],
            'WhatsApp + AI' => [
                ['Write the way you speak', '"કાલે સવારે 10 વાગ્યે બેંક જવાનું છે" becomes a reminder with the right date and time.'],
                ['Repetition understood', 'દરરોજ, દર સોમવારે, દર મહિને 5 તારીખે, દર 3 દિવસે — all handled.'],
                ['Quick commands', 'LIST, TODAY, PENDING, DONE A12, SNOOZE A12 10, PAID A12 5000, SUMMARY, LANG GU — instant and free of AI cost.'],
                ['Bill photo to payment', 'Send a photo of an invoice; the amount and due date are read and a payment reminder is created.'],
                ['Only your number works', 'Messages are processed only from a verified, active number on your account. Everything else is ignored.'],
                ['Never a silent failure', 'If AI is down or over quota, a built-in Gujarati/Hindi/English parser takes over.'],
            ],
            'Money and reports' => [
                ['Payment ledger', 'Party, amount, due date, partial payments, receipts, receivable and payable totals.'],
                ['EMI series', 'Create twelve monthly installments in one action.'],
                ['Night summary', 'Done, pending, missed, amount paid, and tomorrow’s list — on WhatsApp and in the app.'],
                ['Morning brief', 'Your day at your chosen time, default 07:30.'],
                ['Reports and export', 'Completed vs missed, category breakdown, best and worst hours, average delay, CSV / ICS / print-to-PDF.'],
                ['Streaks and score', 'A gentle nudge to finish everything, every day.'],
            ],
            'Team and integrations' => [
                ['Assign to staff', 'The employee gets the call and the WhatsApp message; you see accepted, done or missed.'],
                ['Google Calendar two-way', 'Optional. Create in Google, it appears here; edit here, it updates there.'],
                ['Personal API and webhooks', 'Create reminders from your own software with an HMAC-signed webhook back.'],
                ['Multi-device', 'All your phones ring; the first "Done" clears the rest.'],
                ['PWA and web push', 'The browser can alert you when your phone is not at hand.'],
                ['Trash with 30-day recovery', 'Nothing important is ever really lost.'],
            ],
        ];

        foreach ($groups as $heading => $items):
        ?>
            <h2 class="mt-3"><?= e($heading) ?></h2>
            <div class="grid grid-3">
                <?php foreach ($items as [$featureTitle, $featureBody]): ?>
                    <div class="feature">
                        <h3><?= e($featureTitle) ?></h3>
                        <p><?= e($featureBody) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="text-center mt-3">
            <a class="btn btn-gold" href="<?= e(url('/register')) ?>"><?= t('landing.cta_start') ?></a>
        </div>
    </div>
</section>
