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
use App\Services\CronService;
use App\Services\ReportService;

class AdminController extends Controller
{
    public function showLogin(): void
    {
        $this->view('admin/login', ['title' => 'Admin login'], 'layouts/auth');
    }

    public function login(): void
    {
        $email = trim((string) Request::post('email', ''));
        $password = (string) Request::post('password', '');

        if (!RateLimiter::attempt('admin_login_' . Request::ip(), 10, 900)) {
            Session::flash('error', __('api.rate_limited'));
            Response::back(url('/admin/login'));
        }

        if (AuditService::recentFailures('admin:' . $email, 15) >= 5) {
            Session::flash('error', __('auth.locked_out', ['minutes' => 15]));
            Response::back(url('/admin/login'));
        }

        $admin = App::i()->db()->one(
            'SELECT * FROM admins WHERE email = ? AND is_active = 1 AND deleted_at IS NULL',
            [$email]
        );

        if ($admin === null || !password_verify($password, (string) $admin['password_hash'])) {
            AuditService::loginAttempt('admin:' . $email, false, 'admin');
            Session::flash('error', __('auth.invalid_credentials'));
            Response::back(url('/admin/login'));
        }

        AuditService::loginAttempt('admin:' . $email, true, 'admin');
        Auth::loginAdmin((int) $admin['id']);
        AuditService::log('admin.login', 'admin', (int) $admin['id'], [], 'admin', (int) $admin['id']);

        Response::redirect(url('/admin'));
    }

    public function logout(): void
    {
        Auth::logoutAdmin();
        Auth::logout();
        Response::redirect(url('/admin/login'));
    }

    public function dashboard(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/dashboard', [
            'title'     => __('admin.dashboard'),
            'pageTitle' => __('admin.dashboard'),
            'stats'     => ReportService::adminStats(),
            'aiSeries'  => ReportService::aiCostSeries(30),
            'cron'      => CronService::status(),
            'stale'     => CronService::staleJobs(),
            'recentUsers' => $db->all('SELECT id, name, phone, city, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 8'),
            'expiring'  => $db->all(
                'SELECT u.id, u.name, u.phone, u.plan_expires_at, p.name AS plan_name
                   FROM users u LEFT JOIN plans p ON p.id = u.plan_id
                  WHERE u.deleted_at IS NULL AND u.plan_expires_at BETWEEN ? AND ?
                  ORDER BY u.plan_expires_at ASC LIMIT 10',
                [now_utc(), date('Y-m-d H:i:s', strtotime('+7 days'))]
            ),
            'errors'    => $db->all('SELECT * FROM error_logs ORDER BY id DESC LIMIT 5'),
        ], 'layouts/admin');
    }
}
