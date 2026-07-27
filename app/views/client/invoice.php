<?php /** @var array $invoice */ /** @var array $user */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= e((string) $invoice['invoice_no']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>@page{margin:16mm} body{background:#fff}</style>
</head>
<body>
<div class="container" style="max-width:760px;padding:26px 16px">
    <div class="flex-between mb-3">
        <div>
            <h1 style="margin:0"><?= e((string) setting('company_name', 'AK Computer')) ?></h1>
            <p class="text-sm text-muted mb-0">
                <?= nl2br(e((string) setting('company_address', ''))) ?><br>
                <?= e((string) setting('company_phone', '')) ?> · <?= e((string) setting('company_email', '')) ?>
                <?php if ($gstin = (string) setting('company_gstin', '')): ?><br>GSTIN: <?= e($gstin) ?><?php endif; ?>
            </p>
        </div>
        <div class="text-right">
            <h2 style="margin:0">TAX INVOICE</h2>
            <p class="text-sm mb-0">
                <strong><?= e((string) $invoice['invoice_no']) ?></strong><br>
                <?= e(to_user_time((string) $invoice['issued_at'], 'd M Y')) ?>
            </p>
        </div>
    </div>

    <div class="card mb-2">
        <strong>Bill to</strong><br>
        <?= e((string) ($invoice['billing_name'] ?: $user['name'])) ?><br>
        <span class="text-sm text-muted"><?= e(display_phone((string) $user['phone'])) ?></span>
        <?php if (!empty($invoice['gstin'])): ?><br><span class="text-sm">GSTIN: <?= e((string) $invoice['gstin']) ?></span><?php endif; ?>
    </div>

    <table class="data">
        <thead><tr><th>Description</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
        <tr>
            <td>Krishna Reminder subscription — <?= e((string) ($invoice['plan_name'] ?? 'Plan')) ?></td>
            <td class="text-right"><?= e(money((float) $invoice['subtotal'], (string) $invoice['currency'])) ?></td>
        </tr>
        <?php if ((float) $invoice['discount'] > 0): ?>
            <tr><td>Discount</td><td class="text-right">− <?= e(money((float) $invoice['discount'], (string) $invoice['currency'])) ?></td></tr>
        <?php endif; ?>
        <tr><td>GST @ <?= e((string) $invoice['tax_rate']) ?>%</td><td class="text-right"><?= e(money((float) $invoice['tax_amount'], (string) $invoice['currency'])) ?></td></tr>
        <tr><th>Total</th><th class="text-right"><?= e(money((float) $invoice['total'], (string) $invoice['currency'])) ?></th></tr>
        </tbody>
    </table>

    <p class="text-sm mt-2">Status: <strong><?= e(strtoupper((string) $invoice['status'])) ?></strong>
        <?php if ($invoice['paid_at']): ?> · Paid on <?= e(to_user_time((string) $invoice['paid_at'], 'd M Y')) ?><?php endif; ?></p>

    <p class="text-xs text-muted mt-3">This is a computer-generated invoice and does not require a signature. 🙏 જય શ્રી કૃષ્ણ</p>

    <button class="btn no-print" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>
</body>
</html>
