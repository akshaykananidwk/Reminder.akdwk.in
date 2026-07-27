<?php /** @var array $invoices */ ?>
<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Invoice</th><th>User</th><th>Subtotal</th><th>GST</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td><?= e((string) $invoice['invoice_no']) ?></td>
                    <td><a href="<?= e(url('/admin/users/' . (int) $invoice['user_id'])) ?>"><?= e((string) $invoice['user_name']) ?></a></td>
                    <td><?= e(money((float) $invoice['subtotal'], (string) $invoice['currency'])) ?></td>
                    <td><?= e(money((float) $invoice['tax_amount'], (string) $invoice['currency'])) ?></td>
                    <td><strong><?= e(money((float) $invoice['total'], (string) $invoice['currency'])) ?></strong></td>
                    <td><span class="badge badge-<?= e(status_badge((string) $invoice['status'])) ?>"><?= e((string) $invoice['status']) ?></span></td>
                    <td><?= e(to_user_time((string) $invoice['issued_at'], 'd M Y')) ?></td>
                    <td><a class="btn btn-sm btn-ghost" target="_blank" href="<?= e(url('/admin/invoices/' . (int) $invoice['id'])) ?>">🖨</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
