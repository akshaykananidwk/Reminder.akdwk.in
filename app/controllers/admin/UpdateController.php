<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\CacheService;
use App\Services\TemplateService;
use App\Services\UpdateService;

class UpdateController extends Controller
{
    /* --------------------------------------------------------------- System */

    public function system(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/system', [
            'title'      => __('admin.system'),
            'pageTitle'  => __('admin.system'),
            'backups'    => $db->all('SELECT * FROM backups ORDER BY id DESC LIMIT 30'),
            'backupDir'  => (string) BackupService::directory(),
            'exposure'   => BackupService::auditWebroot(),
            'maintenance'=> App::i()->settings()->bool('maintenance_mode'),
            'phpInfo'    => [
                'PHP version'      => PHP_VERSION,
                'Server'           => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
                'Memory limit'     => (string) ini_get('memory_limit'),
                'Max execution'    => (string) ini_get('max_execution_time'),
                'Upload max'       => (string) ini_get('upload_max_filesize'),
                'Post max'         => (string) ini_get('post_max_size'),
                'Timezone'         => date_default_timezone_get(),
                'Extensions'       => implode(', ', array_intersect(['pdo_mysql', 'curl', 'mbstring', 'openssl', 'zip', 'gd', 'fileinfo'], get_loaded_extensions())),
                'Disk free (MB)'   => (string) (int) round(((float) (@disk_free_space(App::i()->root()) ?: 0)) / 1048576),
                'App version'      => (string) App::i()->config('app.version', '1.0.0'),
                'Installed commit' => (string) App::i()->settings()->get('installed_commit', '—'),
            ],
            'tableSizes' => $db->all(
                'SELECT table_name AS name, table_rows AS rows_estimate,
                        ROUND((data_length + index_length) / 1048576, 2) AS size_mb
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                  ORDER BY (data_length + index_length) DESC LIMIT 15'
            ),
        ], 'layouts/admin');
    }

    public function runBackup(): void
    {
        $this->requireAdmin();

        @set_time_limit(600);
        $result = BackupService::run((string) Request::post('kind', 'full'), 'admin');

        AuditService::log('backup.run', 'backup', $result['id'], ['ok' => $result['ok']]);

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Backup created (' . round($result['size'] / 1048576, 1) . ' MB)'
            : (string) $result['error']);

        Response::redirect(url('/admin/system'));
    }

    public function restore(string $id): void
    {
        $this->requireAdmin();

        $backup = App::i()->db()->one('SELECT * FROM backups WHERE id = ?', [(int) $id]);

        if ($backup === null || !is_file((string) $backup['path'])) {
            Session::flash('error', __('common.not_found'));
            Response::back(url('/admin/system'));
        }

        @set_time_limit(600);
        $result = BackupService::restoreDatabase((string) $backup['path']);

        AuditService::log('backup.restored', 'backup', (int) $id, ['ok' => $result['ok']]);

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Database restored.' : (string) $result['error']);
        Response::redirect(url('/admin/system'));
    }

    /**
     * Backups live outside the web root, so downloading them goes through this
     * authenticated, audited action rather than a public URL.
     */
    public function downloadBackup(string $id): void
    {
        $this->requireAdmin();

        $backup = App::i()->db()->one('SELECT * FROM backups WHERE id = ?', [(int) $id]);

        if ($backup === null || !is_file((string) $backup['path'])) {
            Response::notFound();
        }

        AuditService::log('backup.downloaded', 'backup', (int) $id);

        Response::download((string) $backup['path'], (string) $backup['filename'], 'application/zip');
    }

    public function clearCache(): void
    {
        $this->requireAdmin();

        $removed = CacheService::flush();
        TemplateService::flush();
        App::i()->settings()->refresh();
        App::i()->settings()->set('asset_version', (string) time());

        Session::flash('success', 'Cache cleared (' . $removed . ' file(s)).');
        Response::redirect(url('/admin/system'));
    }

    public function toggleMaintenance(): void
    {
        $this->requireAdmin();

        $settings = App::i()->settings();
        $new = $settings->bool('maintenance_mode') ? '0' : '1';
        $settings->set('maintenance_mode', $new);

        AuditService::log('maintenance.toggled', 'settings', null, ['enabled' => $new]);

        Session::flash('success', $new === '1' ? 'Maintenance mode ON' : 'Maintenance mode OFF');
        Response::redirect(url('/admin/system'));
    }

    /* -------------------------------------------------------------- Updates */

    public function index(): void
    {
        $this->requireAdmin();

        $this->view('admin/update', [
            'title'     => __('admin.update'),
            'pageTitle' => __('admin.update'),
            'settings'  => App::i()->settings(),
            'check'     => App::i()->settings()->bool('update_auto_check', true) ? UpdateService::check(false) : null,
            'history'   => App::i()->db()->all('SELECT * FROM update_history ORDER BY id DESC LIMIT 20'),
            'backups'   => App::i()->db()->all('SELECT * FROM backups WHERE trigger_source = "update" ORDER BY id DESC LIMIT 10'),
        ], 'layouts/admin');
    }

    public function saveSettings(): void
    {
        $this->requireAdmin();
        $settings = App::i()->settings();

        $settings->set('github_owner', trim((string) Request::post('github_owner', '')), false, 'update');
        $settings->set('github_repo', trim((string) Request::post('github_repo', '')), false, 'update');
        $settings->set('github_branch', trim((string) Request::post('github_branch', 'main')), false, 'update');
        $settings->set('update_auto_check', Request::bool('update_auto_check') ? '1' : '0', false, 'update');
        $settings->set('backup_retention_days', (string) max(1, (int) Request::post('backup_retention_days', 14)), false, 'update');

        $token = (string) Request::post('github_token', '');

        if ($token !== '') {
            $settings->set('github_token', $token, true, 'update');
        }

        $settings->refresh();
        CacheService::flush();

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/update'));
    }

    public function check(): void
    {
        $this->requireAdmin();

        $result = UpdateService::check(true);

        if (Request::wantsJson()) {
            Response::ok($result);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($result['has_update'] ? 'Update available: ' . $result['latest'] : 'You are on the latest version.')
            : $result['message']);

        Response::redirect(url('/admin/update'));
    }

    public function run(): void
    {
        $admin = $this->requireAdmin();

        @set_time_limit(900);
        @ini_set('memory_limit', '512M');

        // An update takes minutes and PHP locks the session file for the whole
        // request, so without this every other tab this admin has open hangs
        // until it finishes.
        Session::pause();

        try {
            $result = UpdateService::run((int) $admin['id']);
        } finally {
            Session::resume();
        }

        AuditService::log('update.run', 'update', $result['history_id'], ['ok' => $result['ok']]);

        if (Request::wantsJson()) {
            Response::json($result, $result['ok'] ? 'Update complete' : (string) $result['error'], $result['ok'] ? 200 : 500);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Update completed successfully.'
            : 'Update failed and was rolled back: ' . $result['error']);

        Response::redirect(url('/admin/update'));
    }
}
