<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\CronService;
use App\Services\FcmService;
use App\Services\ReportService;
use App\Services\WhatsAppService;

class MonitorController extends Controller
{
    /* ----------------------------------------------------------------- Cron */

    public function cron(): void
    {
        $this->requireAdmin();

        $this->view('admin/cron', [
            'title'     => __('admin.cron'),
            'pageTitle' => __('admin.cron'),
            'status'    => CronService::status(),
            'stale'     => CronService::staleJobs(),
            'runs'      => App::i()->db()->all('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 60'),
            'cronToken' => (string) App::i()->config('security.cron_token', ''),
            'root'      => App::i()->root(),
        ], 'layouts/admin');
    }

    /**
     * "Run now" from the admin panel — the job runs in this request.
     */
    public function runCron(): void
    {
        $this->requireAdmin();

        $job = (string) Request::post('job', '');
        $allowed = ['dispatcher', 'ai_queue', 'wa_queue', 'recurrence', 'google_sync', 'morning_brief', 'daily_summary', 'subscriptions', 'backup', 'cleanup'];

        if (!in_array($job, $allowed, true)) {
            Session::flash('error', __('common.not_found'));
            Response::back(url('/admin/cron'));
        }

        $script = App::i()->root() . '/cron/' . $job . '.php';

        if (!is_file($script)) {
            Session::flash('error', 'Script missing: ' . $job);
            Response::back(url('/admin/cron'));
        }

        @set_time_limit(300);

        ob_start();

        try {
            include $script;
            $output = trim((string) ob_get_clean());
        } catch (\Throwable $e) {
            ob_end_clean();
            $output = 'Error: ' . $e->getMessage();
        }

        AuditService::log('cron.run', 'cron', null, ['job' => $job]);

        Session::flash('success', $job . ' — ' . ($output !== '' ? str_limit($output, 300) : 'completed'));
        Response::redirect(url('/admin/cron'));
    }

    /* ----------------------------------------------------------------- Logs */

    public function logs(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $tab = (string) Request::get('tab', 'errors');
        $page = $this->pagination(50);

        $data = match ($tab) {
            'whatsapp_in'  => $db->all('SELECT * FROM wa_inbound_raw ORDER BY id DESC LIMIT ' . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset']),
            'whatsapp_out' => $db->all('SELECT * FROM wa_outbound_log ORDER BY id DESC LIMIT ' . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset']),
            'ai'           => $db->all('SELECT a.*, u.name AS user_name FROM ai_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ' . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset']),
            'fcm'          => $db->all('SELECT da.*, d.user_id FROM delivery_attempts da LEFT JOIN deliveries d ON d.id = da.delivery_id WHERE da.channel = "fcm" ORDER BY da.id DESC LIMIT ' . (int) $page['perPage']),
            'logins'       => $db->all('SELECT * FROM login_attempts ORDER BY id DESC LIMIT ' . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset']),
            default        => $db->all('SELECT * FROM error_logs ORDER BY id DESC LIMIT ' . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset']),
        };

        $this->view('admin/logs', [
            'title'     => __('admin.logs'),
            'pageTitle' => __('admin.logs'),
            'tab'       => $tab,
            'rows'      => $data,
            'page'      => $page,
        ], 'layouts/admin');
    }

    /** Per-user AI spend — the screen that finds a runaway account. */
    public function aiReport(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $from = (string) Request::get('from', date('Y-m-01'));

        $rows = $db->all(
            'SELECT a.user_id, u.name, u.phone,
                    COUNT(*) AS calls,
                    SUM(a.total_tokens) AS tokens,
                    SUM(a.cost) AS cost,
                    SUM(a.cached) AS cached,
                    SUM(CASE WHEN a.success = 0 THEN 1 ELSE 0 END) AS failures
               FROM ai_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.created_at >= ?
              GROUP BY a.user_id, u.name, u.phone
              ORDER BY cost DESC
              LIMIT 100',
            [$from . ' 00:00:00']
        );

        $this->view('admin/ai_report', [
            'title'     => __('admin.ai_report'),
            'pageTitle' => __('admin.ai_report'),
            'rows'      => $rows,
            'series'    => ReportService::aiCostSeries(30),
            'from'      => $from,
            'budget'    => App::i()->settings()->int('ai_monthly_budget_tokens', 0),
            'used'      => (int) $db->value('SELECT COALESCE(SUM(total_tokens),0) FROM ai_logs WHERE created_at >= ?', [date('Y-m-01 00:00:00')], 0),
        ], 'layouts/admin');
    }

    /* ------------------------------------------------------------ Broadcast */

    public function broadcast(): void
    {
        $this->requireAdmin();

        $this->view('admin/broadcast', [
            'title'      => __('admin.broadcast'),
            'pageTitle'  => __('admin.broadcast'),
            'broadcasts' => App::i()->db()->all('SELECT * FROM broadcasts ORDER BY id DESC LIMIT 30'),
            'userCount'  => (int) App::i()->db()->value('SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL', [], 0),
        ], 'layouts/admin');
    }

    public function sendBroadcast(): void
    {
        $admin = $this->requireAdmin();
        $db = App::i()->db();

        $title = trim((string) Request::post('title', ''));
        $body = trim((string) Request::post('body', ''));
        $audience = (string) Request::post('audience', 'all');
        $channels = (array) Request::input('channels', ['whatsapp', 'app']);

        if ($title === '' || $body === '') {
            Session::flash('error', __('validation.required', ['field' => 'Message']));
            Response::back(url('/admin/broadcast'));
        }

        $where = 'is_active = 1 AND deleted_at IS NULL';
        $params = [];

        if ($audience === 'expiring') {
            $where .= ' AND plan_expires_at BETWEEN ? AND ?';
            $params[] = now_utc();
            $params[] = date('Y-m-d H:i:s', strtotime('+7 days'));
        } elseif ($audience === 'trial') {
            $where .= ' AND plan_id = (SELECT id FROM plans WHERE is_default = 1 LIMIT 1)';
        } elseif ($audience === 'paid') {
            $where .= ' AND plan_id <> (SELECT id FROM plans WHERE is_default = 1 LIMIT 1)';
        }

        $users = $db->all("SELECT * FROM users WHERE $where LIMIT 5000", $params);

        $broadcastId = $db->insert('broadcasts', [
            'admin_id'   => (int) $admin['id'],
            'title'      => mb_substr($title, 0, 190),
            'body'       => $body,
            'audience'   => $audience,
            'channels'   => implode(',', $channels),
            'recipients' => count($users),
            'status'     => 'queued',
            'created_at' => now_utc(),
            'sent_at'    => now_utc(),
        ]);

        foreach ($users as $user) {
            if (in_array('whatsapp', $channels, true)) {
                WhatsAppService::queue((string) $user['phone'], $body, (int) $user['id'], null, 9, 'broadcast');
            }

            if (in_array('app', $channels, true)) {
                $db->insert('notifications', [
                    'user_id'    => (int) $user['id'],
                    'title'      => mb_substr($title, 0, 190),
                    'body'       => $body,
                    'kind'       => 'broadcast',
                    'created_at' => now_utc(),
                ]);

                FcmService::sendToUser((int) $user['id'], [
                    'type'  => 'broadcast',
                    'title' => mb_substr($title, 0, 190),
                    'body'  => mb_substr($body, 0, 500),
                ]);
            }
        }

        $db->update('broadcasts', ['status' => 'sent'], 'id = :id', ['id' => $broadcastId]);
        AuditService::log('broadcast.sent', 'broadcast', $broadcastId, ['recipients' => count($users)]);

        Session::flash('success', 'Queued for ' . count($users) . ' user(s).');
        Response::redirect(url('/admin/broadcast'));
    }

    /* ---------------------------------------------------------------- Audit */

    public function audit(): void
    {
        $this->requireAdmin();
        $page = $this->pagination(50);

        $this->view('admin/audit', [
            'title'     => __('admin.audit'),
            'pageTitle' => __('admin.audit'),
            'rows'      => App::i()->db()->all(
                'SELECT * FROM audit_logs ORDER BY id DESC LIMIT ' . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset']
            ),
            'page'      => $page,
        ], 'layouts/admin');
    }

    /* --------------------------------------------------------------- Health */

    public function health(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        Response::ok([
            'database'   => 'ok',
            'cron_stale' => CronService::staleJobs(),
            'whatsapp'   => WhatsAppService::gatewayHealthy() ? 'ok' : 'failing',
            'fcm'        => FcmService::isConfigured() ? 'configured' : 'not_configured',
            'webroot'    => BackupService::auditWebroot(),
            'queue'      => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_queue WHERE status = "queued"', [], 0),
            'ai_pending' => (int) $db->value('SELECT COUNT(*) FROM ai_queue WHERE status = "pending"', [], 0),
            'disk_free_mb' => (int) round(((float) (@disk_free_space(App::i()->root()) ?: 0)) / 1048576),
            'php'        => PHP_VERSION,
        ]);
    }
}
