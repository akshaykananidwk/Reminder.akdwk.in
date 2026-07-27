<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\PaymentService;
use App\Services\ReminderService;
use App\Services\ReportService;
use App\Services\UploadService;

class PaymentController extends Controller
{
    public function index(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $db = App::i()->db();

        $status = (string) Request::get('status', 'unpaid');
        $direction = (string) Request::get('direction', '');

        $where = ['p.user_id = ?', 'p.deleted_at IS NULL'];
        $params = [$userId];

        if (in_array($status, ['unpaid', 'partial', 'paid', 'cancelled'], true)) {
            $where[] = $status === 'unpaid' ? 'p.status IN ("unpaid","partial")' : 'p.status = ?';

            if ($status !== 'unpaid') {
                $params[] = $status;
            }
        }

        if (in_array($direction, ['payable', 'receivable'], true)) {
            $where[] = 'p.direction = ?';
            $params[] = $direction;
        }

        $whereSql = implode(' AND ', $where);

        $payments = $db->all(
            "SELECT p.*, r.short_code
               FROM payments p
               LEFT JOIN reminders r ON r.id = p.reminder_id
              WHERE $whereSql
              ORDER BY p.due_date ASC
              LIMIT 300",
            $params
        );

        $this->view('client/payments', [
            'title'      => __('nav.payments'),
            'pageTitle'  => __('nav.payments'),
            'payments'   => $payments,
            'totals'     => PaymentService::totals($userId, (string) $user['timezone']),
            'series'     => PaymentService::monthlySeries($userId, 6),
            'status'     => $status,
            'direction'  => $direction,
            'contacts'   => $db->all('SELECT id, name FROM contacts WHERE user_id = ? AND deleted_at IS NULL ORDER BY name', [$userId]),
        ], 'layouts/client');
    }

    public function store(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        $party = trim((string) Request::post('party_name', ''));
        $amount = (float) Request::post('amount', 0);
        $dueDate = (string) Request::post('due_date', date('Y-m-d'));
        $direction = (string) Request::post('direction', 'payable');
        $installments = (int) Request::post('installments', 1);

        if ($party === '' || $amount <= 0) {
            Session::flash('error', __('validation.required', ['field' => __('payments.party')]));
            Response::back(url('/client/payments'));
        }

        if ($installments > 1) {
            $created = PaymentService::createEmiSeries($userId, $party, $amount, $dueDate, min(60, $installments), $direction);
            Session::flash('success', __('common.saved') . ' (' . $created . ')');
            Response::redirect(url('/client/payments'));
        }

        // A payment is always backed by a reminder so the phone still rings.
        $reminder = ReminderService::create($userId, [
            'title'       => $party . ' — ' . money($amount, 'INR'),
            'type'        => 'payment',
            'start_at'    => to_utc($dueDate . ' 10:00:00', (string) $user['timezone']),
            'amount'      => $amount,
            'person_name' => $party,
            'contact_id'  => Request::post('contact_id') ? (int) Request::post('contact_id') : null,
            'direction'   => $direction,
            'source'      => 'web',
            'timezone'    => (string) $user['timezone'],
        ]);

        if ($reminder !== null) {
            App::i()->db()->update('payments', [
                'direction' => in_array($direction, ['payable', 'receivable'], true) ? $direction : 'payable',
                'note'      => Request::post('note') ?: null,
            ], 'reminder_id = :rid', ['rid' => (int) $reminder['id']]);
        }

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/payments'));
    }

    public function markPaid(string $id): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $amount = (float) Request::post('amount', 0);

        if ($amount <= 0) {
            Session::flash('error', __('validation.min', ['field' => __('common.amount'), 'min' => '1']));
            Response::back(url('/client/payments'));
        }

        $attachmentId = null;

        if (isset($_FILES['receipt']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $upload = UploadService::store($_FILES['receipt'], $userId, 'receipts', ['payment_id' => (int) $id]);

            if ($upload['ok']) {
                $attachmentId = $upload['attachment_id'];
            }
        }

        $ok = PaymentService::recordPayment(
            (int) $id,
            $userId,
            $amount,
            (string) Request::post('method', 'cash'),
            Request::post('note'),
            $attachmentId
        );

        if ($ok) {
            // Close the linked reminder occurrence too.
            $payment = App::i()->db()->one('SELECT * FROM payments WHERE id = ? AND user_id = ?', [(int) $id, $userId]);

            if ($payment !== null && $payment['reminder_id'] !== null && $payment['status'] === 'paid') {
                $occurrence = ReminderService::activeOccurrenceFor((int) $payment['reminder_id']);

                if ($occurrence !== null) {
                    ReminderService::complete((int) $occurrence['id'], $userId, 'web');
                }
            }
        }

        Session::flash($ok ? 'success' : 'error', $ok ? __('payments.recorded') : __('common.error'));
        Response::back(url('/client/payments'));
    }

    public function export(): void
    {
        $user = $this->requireUser();

        $rows = App::i()->db()->all(
            'SELECT p.*, r.short_code FROM payments p LEFT JOIN reminders r ON r.id = p.reminder_id
              WHERE p.user_id = ? AND p.deleted_at IS NULL ORDER BY p.due_date DESC LIMIT 5000',
            [(int) $user['id']]
        );

        $mapped = array_map(static fn ($p) => [
            'code'      => $p['short_code'],
            'party'     => $p['party_name'],
            'direction' => $p['direction'],
            'amount'    => $p['amount'],
            'paid'      => $p['paid_amount'],
            'balance'   => (float) $p['amount'] - (float) $p['paid_amount'],
            'due_date'  => $p['due_date'],
            'status'    => $p['status'],
            'paid_at'   => $p['paid_at'],
        ], $rows);

        $csv = ReportService::toCsv($mapped, [
            'code' => 'Code', 'party' => 'Party', 'direction' => 'Type', 'amount' => 'Amount',
            'paid' => 'Paid', 'balance' => 'Balance', 'due_date' => 'Due date',
            'status' => 'Status', 'paid_at' => 'Paid at',
        ]);

        Response::stream($csv, 'krishna-payments.csv', 'text/csv');
    }
}
