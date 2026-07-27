<?php /** @var string $waNumber */ ?>
<div class="card mb-2">
    <h3>📖 WhatsApp <?= t('nav.help') ?></h3>
    <p class="text-sm text-muted">અમારા નંબર <strong><?= e(display_phone($waNumber)) ?></strong> પર આ રીતે લખો:</p>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>લખો</th><th>શું થશે</th></tr></thead>
            <tbody>
            <tr><td>કાલે સવારે 10 વાગ્યે બેંક જવાનું છે</td><td>કાલે 10:00 નું રિમાઇન્ડર</td></tr>
            <tr><td>દર સોમવારે સ્ટાફ મીટિંગ</td><td>દર સોમવારે પુનરાવર્તન</td></tr>
            <tr><td>દર મહિને 5 તારીખે લાઇટ બિલ</td><td>માસિક બિલ રિમાઇન્ડર</td></tr>
            <tr><td>15 મિનિટ પછી દવા લેવી</td><td>15 મિનિટ પછી કોલ</td></tr>
            <tr><td>5 તારીખે રમેશભાઈને 5000 આપવા</td><td>પેમેન્ટ રિમાઇન્ડર + હિસાબ</td></tr>
            <tr><td><code>યાદી</code> / <code>LIST</code></td><td>આજનાં કામ</td></tr>
            <tr><td><code>બાકી</code> / <code>PENDING</code></td><td>બાકી + ચૂકેલાં</td></tr>
            <tr><td><code>DONE A12</code></td><td>A12 પૂરું થયું</td></tr>
            <tr><td><code>SNOOZE A12 10</code></td><td>10 મિનિટ પછી ફરી</td></tr>
            <tr><td><code>CANCEL A12</code></td><td>રદ કરો</td></tr>
            <tr><td><code>PAID A12 5000</code></td><td>ચુકવણી નોંધો</td></tr>
            <tr><td><code>SUMMARY</code></td><td>આજનો રિપોર્ટ</td></tr>
            <tr><td><code>LANG GU / HI / EN</code></td><td>ભાષા બદલો</td></tr>
            <tr><td><code>STOP</code> / <code>START</code></td><td>રિમાઇન્ડર બંધ / ચાલુ</td></tr>
            <tr><td><code>મદદ</code> / <code>HELP</code></td><td>આ યાદી WhatsApp પર</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>📞 ફોન કેમ નથી વાગતો?</h3>
        <ol class="text-sm">
            <li>એપમાં બધી પરવાનગી આપો — ખાસ કરીને <strong>Exact alarm</strong> અને <strong>Full-screen notification</strong>.</li>
            <li>બેટરી ઓપ્ટિમાઇઝેશનમાંથી એપને બહાર રાખો.</li>
            <li>Xiaomi/Oppo/Vivo/Realme માં <strong>Autostart</strong> ચાલુ કરો.</li>
            <li>સેટિંગમાં Do-Not-Disturb સમય તપાસો.</li>
            <li>ડિવાઇસ પેજ પરથી <em><?= t('devices.send_test') ?></em> દબાવીને ટેસ્ટ કરો.</li>
        </ol>
    </div>

    <div class="card">
        <h3>💬 મદદ જોઈએ છે?</h3>
        <p class="text-sm text-muted">AK Computer, દ્વારકા — ગુજરાતીમાં વાત કરો.</p>
        <a class="btn btn-green btn-block" target="_blank" rel="noopener" href="https://wa.me/<?= e($waNumber) ?>">💬 WhatsApp <?= e(display_phone($waNumber)) ?></a>
        <a class="btn btn-ghost btn-block mt-1" href="<?= e(url('/faq')) ?>">FAQ</a>
    </div>
</div>
