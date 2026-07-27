<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\InvoiceService;
use App\Services\PlanService;
use App\Services\WhatsAppService;

class PlanController extends Controller
{
    /* ---------------------------------------------------------------- Plans */

    public function index(): void
    {
        $this->requireAdmin();

        $this->view('admin/plans', [
            'title'     => __('admin.plans'),
            'pageTitle' => __('admin.plans'),
            'plans'     => App::i()->db()->all('SELECT * FROM plans WHERE deleted_at IS NULL ORDER BY sort_order ASC'),
        ], 'layouts/admin');
    }

    public function save(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $data = [
            'code'                  => preg_replace('/[^a-z0-9_]/i', '', (string) Request::post('code', '')) ?: 'plan',
            'name'                  => mb_substr((string) Request::post('name', 'Plan'), 0, 120),
            'name_gu'               => Request::post('name_gu') ?: null,
            'name_hi'               => Request::post('name_hi') ?: null,
            'description'           => Request::post('description') ?: null,
            'price'                 => (float) Request::post('price', 0),
            'currency'              => strtoupper(mb_substr((string) Request::post('currency', 'INR'), 0, 3)),
            'duration_days'         => max(1, (int) Request::post('duration_days', 30)),
            'trial_days'            => max(0, (int) Request::post('trial_days', 0)),
            'max_reminders_month'   => (int) Request::post('max_reminders_month', 200),
            'max_ai_messages_month' => (int) Request::post('max_ai_messages_month', 200),
            'max_ai_tokens_month'   => (int) Request::post('max_ai_tokens_month', 300000),
            'max_devices'           => (int) Request::post('max_devices', 1),
            'max_staff'             => (int) Request::post('max_staff', 0),
            'call_reminders'        => Request::bool('call_reminders') ? 1 : 0,
            'google_sync'           => Request::bool('google_sync') ? 1 : 0,
            'api_access'            => Request::bool('api_access') ? 1 : 0,
            'full_reports'          => Request::bool('full_reports') ? 1 : 0,
            'is_active'             => Request::bool('is_active') ? 1 : 0,
            'sort_order'            => (int) Request::post('sort_order', 0),
        ];

        $planId = (int) Request::post('id', 0);

        if ($planId > 0) {
            $db->update('plans', $data, 'id = :id', ['id' => $planId]);
            AuditService::log('plan.updated', 'plan', $planId);
        } else {
            $planId = $db->insert('plans', array_merge($data, ['created_at' => now_utc()]));
            AuditService::log('plan.created', 'plan', $planId);
        }

        PlanService::flush();

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/plans'));
    }

    public function destroy(string $id): void
    {
        $this->requireAdmin();

        App::i()->db()->update('plans', ['deleted_at' => now_utc(), 'is_active' => 0], 'id = :id', ['id' => (int) $id]);
        AuditService::log('plan.deleted', 'plan', (int) $id);

        Session::flash('success', __('common.deleted'));
        Response::redirect(url('/admin/plans'));
    }

    /* -------------------------------------------------------- Subscriptions */

    public function subscriptions(): void
    {
        $this->requireAdmin();

        $status = (string) Request::get('status', 'pending');

        $rows = App::i()->db()->all(
            'SELECT s.*, u.name AS user_name, u.phone, p.name AS plan_name
               FROM subscriptions s
               JOIN users u ON u.id = s.user_id
               JOIN plans p ON p.id = s.plan_id
              WHERE (? = "" OR s.status = ?)
              ORDER BY s.created_at DESC LIMIT 200',
            [$status, $status]
        );

        $this->view('admin/subscriptions', [
            'title'     => __('admin.subscriptions'),
            'pageTitle' => __('admin.subscriptions'),
            'rows'      => $rows,
            'status'    => $status,
        ], 'layouts/admin');
    }

    public function approve(string $id): void
    {
        $admin = $this->requireAdmin();
        $db = App::i()->db();
        $subscriptionId = (int) $id;

        $subscription = $db->one('SELECT * FROM subscriptions WHERE id = ?', [$subscriptionId]);

        if ($subscription === null) {
            Response::notFound();
        }

        $plan = $db->one('SELECT * FROM plans WHERE id = ?', [(int) $subscription['plan_id']]);
        $user = $db->one('SELECT * FROM users WHERE id = ?', [(int) $subscription['user_id']]);

        if ($plan === null || $user === null) {
            Response::notFound();
        }

        $db->update('subscriptions', [
            'status'      => 'active',
            'approved_by' => (int) $admin['id'],
            'approved_at' => now_utc(),
        ], 'id = :id', ['id' => $subscriptionId]);

        PlanService::assign((int) $user['id'], (int) $plan['id'], (int) $plan['duration_days'], (int) $admin['id']);

        $db->query('UPDATE invoices SET status = "paid", paid_at = ? WHERE subscription_id = ?', [now_utc(), $subscriptionId]);

        if ($subscription['coupon_id'] !== null) {
            $db->query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [(int) $subscription['coupon_id']]);
        }

        InvoiceService::creditReferral((int) $user['id'], $subscriptionId, (float) $subscription['amount']);

        $fresh = $db->one('SELECT plan_expires_at FROM users WHERE id = ?', [(int) $user['id']]);

        WhatsAppService::queueTemplate('plan_upgraded', $user, [
            'name' => (string) $user['name'],
            'plan' => (string) $plan['name'],
            'date' => to_user_time((string) ($fresh['plan_expires_at'] ?? ''), 'd M Y', (string) $user['timezone']),
        ], 3);

        AuditService::log('subscription.approved', 'subscription', $subscriptionId);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/subscriptions'));
    }

    /* ------------------------------------------------------------- Invoices */

    public function invoices(): void
    {
        $this->requireAdmin();

        $this->view('admin/invoices', [
            'title'     => __('admin.invoices'),
            'pageTitle' => __('admin.invoices'),
            'invoices'  => App::i()->db()->all(
                'SELECT i.*, u.name AS user_name, u.phone FROM invoices i JOIN users u ON u.id = i.user_id ORDER BY i.issued_at DESC LIMIT 200'
            ),
        ], 'layouts/admin');
    }

    public function invoice(string $id): void
    {
        $this->requireAdmin();

        $invoice = App::i()->db()->one(
            'SELECT i.*, p.name AS plan_name FROM invoices i
               LEFT JOIN subscriptions s ON s.id = i.subscription_id
               LEFT JOIN plans p ON p.id = s.plan_id
              WHERE i.id = ?',
            [(int) $id]
        );

        if ($invoice === null) {
            Response::notFound();
        }

        $user = App::i()->db()->one('SELECT * FROM users WHERE id = ?', [(int) $invoice['user_id']]);

        $this->view('client/invoice', [
            'title'   => 'Invoice ' . $invoice['invoice_no'],
            'invoice' => $invoice,
            'user'    => $user ?? ['name' => '', 'phone' => ''],
        ], null);
    }

    /* -------------------------------------------------------------- Coupons */

    public function coupons(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/coupons', [
            'title'     => __('admin.coupons'),
            'pageTitle' => __('admin.coupons'),
            'coupons'   => $db->all('SELECT c.*, p.name AS plan_name FROM coupons c LEFT JOIN plans p ON p.id = c.plan_id ORDER BY c.created_at DESC LIMIT 200'),
            'plans'     => $db->all('SELECT id, name FROM plans WHERE deleted_at IS NULL ORDER BY sort_order'),
        ], 'layouts/admin');
    }

    public function saveCoupon(): void
    {
        $this->requireAdmin();

        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', (string) Request::post('code', '')) ?: '');

        if ($code === '') {
            Session::flash('error', __('validation.required', ['field' => 'Code']));
            Response::back(url('/admin/coupons'));
        }

        App::i()->db()->upsert('coupons', [
            'code'           => $code,
            'discount_type'  => Request::post('discount_type') === 'fixed' ? 'fixed' : 'percent',
            'discount_value' => (float) Request::post('discount_value', 0),
            'plan_id'        => Request::post('plan_id') ? (int) Request::post('plan_id') : null,
            'max_uses'       => Request::post('max_uses') ? (int) Request::post('max_uses') : null,
            'valid_from'     => Request::post('valid_from') ?: null,
            'valid_until'    => Request::post('valid_until') ?: null,
            'is_active'      => Request::bool('is_active') ? 1 : 0,
            'created_at'     => now_utc(),
        ], ['discount_type', 'discount_value', 'plan_id', 'max_uses', 'valid_from', 'valid_until', 'is_active']);

        AuditService::log('coupon.saved', 'coupon', null, ['code' => $code]);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/coupons'));
    }
}
