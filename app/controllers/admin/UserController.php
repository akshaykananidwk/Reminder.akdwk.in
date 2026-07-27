<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\PlanService;
use App\Services\ReminderService;

class UserController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $search = trim((string) Request::get('q', ''));
        $status = (string) Request::get('status', '');
        $planId = (int) Request::get('plan', 0);
        $page = $this->pagination(30);

        $where = ['u.deleted_at IS NULL'];
        $params = [];

        if ($search !== '') {
            $where[] = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.city LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        if ($status === 'active') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'suspended') {
            $where[] = 'u.is_active = 0';
        } elseif ($status === 'expired') {
            $where[] = 'u.plan_expires_at < ?';
            $params[] = now_utc();
        }

        if ($planId > 0) {
            $where[] = 'u.plan_id = ?';
            $params[] = $planId;
        }

        $whereSql = implode(' AND ', $where);

        $users = $db->all(
            "SELECT u.*, p.name AS plan_name,
                    (SELECT COUNT(*) FROM reminders r WHERE r.user_id = u.id AND r.deleted_at IS NULL) AS reminder_count
               FROM users u
               LEFT JOIN plans p ON p.id = u.plan_id
              WHERE $whereSql
              ORDER BY u.created_at DESC
              LIMIT " . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset'],
            $params
        );

        $this->view('admin/users', [
            'title'     => __('admin.users'),
            'pageTitle' => __('admin.users'),
            'users'     => $users,
            'total'     => (int) $db->value("SELECT COUNT(*) FROM users u WHERE $whereSql", $params, 0),
            'search'    => $search,
            'status'    => $status,
            'planId'    => $planId,
            'plans'     => $db->all('SELECT id, name FROM plans WHERE deleted_at IS NULL ORDER BY sort_order'),
            'page'      => $page,
        ], 'layouts/admin');
    }

    public function show(string $id): void
    {
        $this->requireAdmin();
        $db = App::i()->db();
        $userId = (int) $id;

        $user = $db->one('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            Response::notFound();
        }

        $this->view('admin/user_show', [
            'title'      => (string) $user['name'],
            'pageTitle'  => (string) $user['name'],
            'user'       => $user,
            'usage'      => PlanService::usage($userId),
            'plans'      => $db->all('SELECT * FROM plans WHERE deleted_at IS NULL ORDER BY sort_order'),
            'numbers'    => $db->all('SELECT * FROM whatsapp_numbers WHERE user_id = ?', [$userId]),
            'devices'    => $db->all('SELECT * FROM devices WHERE user_id = ?', [$userId]),
            'settings'   => ReminderService::userSettings($userId),
            'recent'     => $db->all(
                'SELECT r.* FROM reminders r WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 15',
                [$userId]
            ),
            'inbound'    => $db->all('SELECT * FROM wa_inbound_raw WHERE user_id = ? ORDER BY received_at DESC LIMIT 10', [$userId]),
            'aiLogs'     => $db->all('SELECT * FROM ai_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', [$userId]),
            'subscriptions' => $db->all(
                'SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 10',
                [$userId]
            ),
        ], 'layouts/admin');
    }

    public function update(string $id): void
    {
        $this->requireAdmin();
        $userId = (int) $id;

        $fields = [];

        foreach (['name', 'email', 'city'] as $field) {
            $value = Request::post($field);

            if ($value !== null && $value !== '') {
                $fields[$field] = mb_substr((string) $value, 0, 190);
            }
        }

        $language = (string) Request::post('language', '');

        if (in_array($language, ['gu', 'hi', 'en'], true)) {
            $fields['language'] = $language;
        }

        $timezone = (string) Request::post('timezone', '');

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            $fields['timezone'] = $timezone;
        }

        if (Request::post('plan_id') !== null) {
            $fields['plan_id'] = (int) Request::post('plan_id') ?: null;
        }

        if ($fields !== []) {
            App::i()->db()->update('users', $fields, 'id = :id', ['id' => $userId]);
            AuditService::log('user.updated', 'user', $userId, array_keys($fields));
        }

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/users/' . $userId));
    }

    public function suspend(string $id): void
    {
        $this->requireAdmin();
        $db = App::i()->db();
        $userId = (int) $id;

        $user = $db->one('SELECT is_active FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            Response::notFound();
        }

        $newState = (int) $user['is_active'] === 1 ? 0 : 1;
        $db->update('users', ['is_active' => $newState], 'id = :id', ['id' => $userId]);

        if ($newState === 0) {
            $db->query('UPDATE sessions SET revoked = 1 WHERE user_id = ?', [$userId]);
        }

        AuditService::log($newState === 1 ? 'user.activated' : 'user.suspended', 'user', $userId);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/users/' . $userId));
    }

    public function destroy(string $id): void
    {
        $this->requireAdmin();
        $db = App::i()->db();
        $userId = (int) $id;

        $user = $db->one('SELECT phone FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            Response::notFound();
        }

        $db->update('users', [
            'deleted_at' => now_utc(),
            'is_active'  => 0,
            'phone'      => 'deleted_' . $userId . '_' . substr((string) $user['phone'], -4),
            'email'      => null,
        ], 'id = :id', ['id' => $userId]);

        $db->delete('whatsapp_numbers', 'user_id = ?', [$userId]);
        $db->query('UPDATE sessions SET revoked = 1 WHERE user_id = ?', [$userId]);

        AuditService::log('user.deleted', 'user', $userId);

        Session::flash('success', __('common.deleted'));
        Response::redirect(url('/admin/users'));
    }

    /**
     * Log in as the user. Fully audit-logged; the client layout shows a banner.
     */
    public function impersonate(string $id): void
    {
        $admin = $this->requireAdmin();
        $userId = (int) $id;

        $user = App::i()->db()->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);

        if ($user === null) {
            Response::notFound();
        }

        AuditService::log('user.impersonated', 'user', $userId, ['admin' => $admin['email']]);

        Auth::login($userId, false);
        Session::set('admin_id', (int) $admin['id']);
        Session::set('impersonating_user_id', $userId);

        Response::redirect(url('/client'));
    }

    public function extendPlan(string $id): void
    {
        $this->requireAdmin();
        $userId = (int) $id;

        $planId = (int) Request::post('plan_id', 0);
        $days = (int) Request::post('days', 30);

        if ($planId <= 0) {
            Session::flash('error', __('common.not_found'));
            Response::back(url('/admin/users/' . $userId));
        }

        PlanService::assign($userId, $planId, $days, (int) ($this->requireAdmin()['id']));

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/users/' . $userId));
    }

    public function resetOtp(string $id): void
    {
        $this->requireAdmin();
        $userId = (int) $id;

        $user = App::i()->db()->one('SELECT phone FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            Response::notFound();
        }

        $phone = (string) $user['phone'];

        RateLimiter::clear('otp_cooldown_' . $phone);
        RateLimiter::clear('otp_hour_' . $phone);

        App::i()->db()->query('UPDATE otp_codes SET is_used = 1 WHERE phone = ?', [$phone]);

        AuditService::log('user.otp_reset', 'user', $userId);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/users/' . $userId));
    }
}
