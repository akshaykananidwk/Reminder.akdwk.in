<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * One-click GitHub updater (Section A6).
 *
 * check()  — compares the installed commit with the latest on the branch.
 * run()    — backup → download → extract → copy (respecting the protected
 *            list) → migrate → health check, with automatic rollback.
 */
class UpdateService
{
    /** Files and folders that an update must never overwrite. */
    private const PROTECTED = [
        'config/config.php',
        'config/install.lock',
        '.env',
        'uploads',
        'storage',
        'backups',
        '.git',
        '.updateignore',
    ];

    /* ---------------------------------------------------------------- Check */

    /**
     * @return array{ok: bool, current: string, latest: string, message: string, commit: array|null, has_update: bool}
     */
    public static function check(bool $force = false): array
    {
        $settings = App::i()->settings();

        $owner = trim((string) $settings->get('github_owner', ''));
        $repo = trim((string) $settings->get('github_repo', ''));
        $branch = trim((string) $settings->get('github_branch', 'main'));

        if ($owner === '' || $repo === '') {
            return self::checkFailure('GitHub repository is not configured.');
        }

        $cacheKey = 'update_check_' . md5($owner . $repo . $branch);

        if (!$force) {
            $cached = CacheService::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $headers = ['Accept' => 'application/vnd.github+json'];
        $token = (string) $settings->get('github_token', '');

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = HttpClient::get(
            sprintf('https://api.github.com/repos/%s/%s/commits/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($branch)),
            ['headers' => $headers, 'timeout' => 20]
        );

        if (!$response['ok'] || !is_array($response['json'])) {
            return self::checkFailure('GitHub API error: HTTP ' . $response['status'] . ' ' . mb_substr($response['body'], 0, 200));
        }

        $commit = $response['json'];
        $sha = (string) ($commit['sha'] ?? '');
        $current = (string) $settings->get('installed_commit', '');

        $result = [
            'ok'         => true,
            'current'    => $current !== '' ? substr($current, 0, 7) : (string) App::i()->config('app.version', '1.0.0'),
            'latest'     => substr($sha, 0, 7),
            'has_update' => $sha !== '' && $sha !== $current,
            'message'    => '',
            'commit'     => [
                'sha'     => $sha,
                'short'   => substr($sha, 0, 7),
                'message' => (string) ($commit['commit']['message'] ?? ''),
                'author'  => (string) ($commit['commit']['author']['name'] ?? ''),
                'date'    => (string) ($commit['commit']['author']['date'] ?? ''),
                'files'   => count($commit['files'] ?? []),
                'url'     => (string) ($commit['html_url'] ?? ''),
            ],
        ];

        CacheService::put($cacheKey, $result, 900);

        return $result;
    }

    private static function checkFailure(string $message): array
    {
        return [
            'ok'         => false,
            'current'    => (string) App::i()->settings()->get('installed_commit', ''),
            'latest'     => '',
            'has_update' => false,
            'message'    => $message,
            'commit'     => null,
        ];
    }

    /* ------------------------------------------------------------------ Run */

    /**
     * @return array{ok: bool, steps: array, error: string|null, history_id: int}
     */
    public static function run(?int $adminId = null): array
    {
        $app = App::i();
        $settings = $app->settings();
        $db = $app->db();

        $steps = [];
        $historyId = $db->insert('update_history', [
            'from_version' => (string) $app->config('app.version', '1.0.0'),
            'commit_sha'   => null,
            'status'       => 'running',
            'admin_id'     => $adminId,
            'started_at'   => now_utc(),
        ]);

        $backupPath = null;
        $tempDir = null;

        try {
            /* 1. Pre-flight ------------------------------------------------ */
            $check = self::check(true);

            if (!$check['ok']) {
                throw new \RuntimeException($check['message']);
            }

            $free = @disk_free_space($app->root());

            if ($free !== false && $free < 104857600) {
                throw new \RuntimeException('Less than 100 MB of free disk space.');
            }

            if (!is_writable($app->root())) {
                throw new \RuntimeException('The application directory is not writable.');
            }

            $steps[] = ['step' => 'preflight', 'status' => 'ok', 'detail' => 'Disk and permissions verified'];

            $settings->set('maintenance_mode', '1');
            $steps[] = ['step' => 'maintenance_on', 'status' => 'ok', 'detail' => ''];

            /* 2. Backup ---------------------------------------------------- */
            $backup = BackupService::run('full', 'update');

            if (!$backup['ok']) {
                throw new \RuntimeException('Backup failed: ' . $backup['error']);
            }

            $backupPath = $backup['path'];
            $db->update('update_history', ['backup_id' => $backup['id']], 'id = :id', ['id' => $historyId]);
            $steps[] = ['step' => 'backup', 'status' => 'ok', 'detail' => basename($backupPath) . ' (' . round($backup['size'] / 1048576, 1) . ' MB)'];

            /* 3. Download -------------------------------------------------- */
            $tempDir = $app->root() . '/storage/temp/update-' . date('YmdHis');

            if (!@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
                throw new \RuntimeException('Could not create the temporary update directory.');
            }

            $archive = $tempDir . '/source.zip';
            $owner = (string) $settings->get('github_owner', '');
            $repo = (string) $settings->get('github_repo', '');
            $branch = (string) $settings->get('github_branch', 'main');

            $headers = ['Accept' => 'application/vnd.github+json'];
            $token = (string) $settings->get('github_token', '');

            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $download = HttpClient::get(
                sprintf('https://api.github.com/repos/%s/%s/zipball/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($branch)),
                ['headers' => $headers, 'timeout' => 180, 'save_to' => $archive]
            );

            if (!is_file($archive) || filesize($archive) < 1024) {
                throw new \RuntimeException('Download failed (HTTP ' . $download['status'] . ').');
            }

            $steps[] = ['step' => 'download', 'status' => 'ok', 'detail' => round(filesize($archive) / 1048576, 2) . ' MB'];

            /* 4. Extract --------------------------------------------------- */
            $extractDir = $tempDir . '/extracted';
            @mkdir($extractDir, 0775, true);

            $zip = new \ZipArchive();

            if ($zip->open($archive) !== true) {
                throw new \RuntimeException('The downloaded archive could not be opened.');
            }

            $zip->extractTo($extractDir);
            $zip->close();

            // GitHub wraps everything in one <owner>-<repo>-<sha> folder.
            $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
            $sourceDir = (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0]))
                ? $extractDir . '/' . $entries[0]
                : $extractDir;

            $steps[] = ['step' => 'extract', 'status' => 'ok', 'detail' => basename($sourceDir)];

            /* 5. Copy ------------------------------------------------------ */
            $ignore = self::ignoreList($sourceDir);
            $copied = self::copyTree($sourceDir, $app->root(), $ignore);
            $steps[] = ['step' => 'copy', 'status' => 'ok', 'detail' => $copied . ' file(s) updated'];

            // Throw away the compiled bytecode of the files we just replaced.
            //
            // Without this the next request runs a mixture of old and new code
            // for up to opcache.revalidate_freq seconds (60 on a stock aaPanel
            // box), because opcache only re-checks a file's timestamp that
            // often. A new view calling a method that opcache still has the old
            // version of is a fatal error, which is how a completed update
            // still ends on the "Something went wrong" page — and why it works
            // again a minute later.
            $opcache = self::resetOpcache();
            $steps[] = ['step' => 'opcache', 'status' => 'ok', 'detail' => $opcache];

            /* 6. Migrations ------------------------------------------------ */
            $migrated = self::runMigrations();
            $steps[] = ['step' => 'migrations', 'status' => 'ok', 'detail' => $migrated === [] ? 'none pending' : implode(', ', $migrated)];

            /* 7. Finalise -------------------------------------------------- */
            CacheService::flush();
            TemplateService::flush();
            $settings->refresh();
            $settings->set('asset_version', (string) time());

            // Health first. Recording the new commit or reopening the site
            // before the application is known good would leave a broken
            // release marked as installed.
            $health = self::healthCheck();

            if (!$health['ok']) {
                throw new \RuntimeException('Health check failed after update: ' . $health['message']);
            }

            $steps[] = ['step' => 'health', 'status' => 'ok', 'detail' => 'Application responding'];

            $settings->set('installed_commit', (string) ($check['commit']['sha'] ?? ''));
            $settings->set('maintenance_mode', '0');

            $db->update('update_history', [
                'status'         => 'success',
                'to_version'     => substr((string) ($check['commit']['sha'] ?? ''), 0, 7),
                'commit_sha'     => (string) ($check['commit']['sha'] ?? ''),
                'commit_message' => mb_substr((string) ($check['commit']['message'] ?? ''), 0, 500),
                'steps'          => json_encode($steps, JSON_UNESCAPED_UNICODE),
                'finished_at'    => now_utc(),
            ], 'id = :id', ['id' => $historyId]);

            self::cleanup($tempDir);

            return ['ok' => true, 'steps' => $steps, 'error' => null, 'history_id' => $historyId];
        } catch (\Throwable $e) {
            Logger::error('Update failed', ['error' => $e->getMessage()], 'update');

            $steps[] = ['step' => 'error', 'status' => 'failed', 'detail' => $e->getMessage()];

            $rolledBack = false;

            if ($backupPath !== null && is_file($backupPath)) {
                $rolledBack = self::rollback($backupPath);
                $steps[] = ['step' => 'rollback', 'status' => $rolledBack ? 'ok' : 'failed', 'detail' => basename($backupPath)];
            }

            $settings->set('maintenance_mode', '0');

            $db->update('update_history', [
                'status'      => $rolledBack ? 'rolled_back' : 'failed',
                'steps'       => json_encode($steps, JSON_UNESCAPED_UNICODE),
                'error'       => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now_utc(),
            ], 'id = :id', ['id' => $historyId]);

            self::notifyAdminFailure($e->getMessage(), $rolledBack);

            if ($tempDir !== null) {
                self::cleanup($tempDir);
            }

            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage(), 'history_id' => $historyId];
        }
    }

    /* ---------------------------------------------------------- Migrations */

    /**
     * Run every migration file that is not yet recorded, in filename order.
     *
     * @return array<int, string> names of the migrations that ran
     */
    public static function runMigrations(): array
    {
        $db = App::i()->db();
        $dir = App::i()->root() . '/database/migrations';

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        $applied = [];

        foreach ($db->all('SELECT migration FROM schema_migrations') as $row) {
            $applied[(string) $row['migration']] = true;
        }

        $batch = (int) $db->value('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations', [], 1);
        $ran = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (isset($applied[$name])) {
                continue;
            }

            $sql = (string) file_get_contents($file);

            if (trim($sql) === '') {
                continue;
            }

            BackupService::runSqlScript($sql);

            $db->insert('schema_migrations', [
                'migration'   => $name,
                'batch'       => $batch,
                'executed_at' => now_utc(),
            ]);

            $ran[] = $name;
        }

        return $ran;
    }

    /** Mark every current migration as applied (used by the installer). */
    public static function markAllMigrationsApplied(): void
    {
        $db = App::i()->db();
        $dir = App::i()->root() . '/database/migrations';

        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            try {
                $db->insert('schema_migrations', [
                    'migration'   => basename($file),
                    'batch'       => 1,
                    'executed_at' => now_utc(),
                ]);
            } catch (\Throwable) {
                // Already recorded.
            }
        }
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * Drop the compiled bytecode for the application's PHP files.
     *
     * `opcache_reset()` is the clean way, but many shared hosts disable it, and
     * on php-fpm it only clears the pool that happens to serve this request.
     * So it falls back to invalidating each file individually, which works
     * whenever `validate_timestamps` is on, and reports honestly which it
     * managed rather than claiming success either way.
     */
    public static function resetOpcache(): string
    {
        if (!function_exists('opcache_get_status') || !function_exists('opcache_reset')) {
            return 'opcache not installed';
        }

        $status = @opcache_get_status(false);

        if ($status === false || empty($status['opcache_enabled'])) {
            return 'opcache not enabled';
        }

        if (@opcache_reset()) {
            return 'reset';
        }

        // Reset is disabled (opcache.restrict_api, or a hardened host).
        if (!function_exists('opcache_invalidate')) {
            return 'could not be cleared — restart PHP-FPM if the site behaves oddly';
        }

        $invalidated = 0;

        foreach (['/app', '/api', '/install', '/cron', '/public'] as $dir) {
            $path = App::i()->root() . $dir;

            if (!is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && @opcache_invalidate($file->getPathname(), true)) {
                    $invalidated++;
                }
            }
        }

        foreach (['index.php'] as $rootFile) {
            $path = App::i()->root() . '/' . $rootFile;

            if (is_file($path) && @opcache_invalidate($path, true)) {
                $invalidated++;
            }
        }

        return $invalidated > 0
            ? $invalidated . ' file(s) invalidated'
            : 'could not be cleared — restart PHP-FPM if the site behaves oddly';
    }

    /** The protected list plus anything the release's .updateignore adds. */
    public static function ignoreList(string $sourceDir): array
    {
        $ignore = self::PROTECTED;
        $file = $sourceDir . '/.updateignore';

        if (is_file($file)) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
                $line = trim($line);

                if ($line !== '' && !str_starts_with($line, '#')) {
                    $ignore[] = trim($line, '/');
                }
            }
        }

        return array_values(array_unique($ignore));
    }

    /**
     * Copy the extracted release over the live tree, skipping everything in
     * $ignore. Public so tests/verify_update_rollback.php can prove that a
     * failure part-way through never touches a protected path.
     */
    public static function copyTree(string $from, string $to, array $ignore, string $relative = ''): int
    {
        $items = @scandir($from);

        if ($items === false) {
            return 0;
        }

        $count = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $rel = ($relative === '' ? '' : $relative . '/') . $item;

            foreach ($ignore as $protected) {
                if ($rel === $protected || str_starts_with($rel, $protected . '/')) {
                    continue 2;
                }
            }

            $src = $from . '/' . $item;
            $dst = $to . '/' . $item;

            if (is_dir($src)) {
                if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
                    throw new \RuntimeException('Could not create directory: ' . $rel);
                }

                $count += self::copyTree($src, $dst, $ignore, $rel);
            } elseif (is_file($src)) {
                if (!@copy($src, $dst)) {
                    throw new \RuntimeException('Could not write file: ' . $rel);
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * Put the application files back the way the backup found them.
     *
     * Kept separate from rollback() — and free of App/database access — so the
     * rollback can be exercised against a throwaway tree without a live site.
     * See tests/verify_update_rollback.php.
     *
     * @return array{ok: bool, restored: int, skipped: int, failed: int, error: string|null}
     */
    public static function restoreFiles(string $backupPath, string $root): array
    {
        $result = ['ok' => false, 'restored' => 0, 'skipped' => 0, 'failed' => 0, 'error' => null];

        if (!class_exists('ZipArchive')) {
            $result['error'] = 'The zip extension is not available.';

            return $result;
        }

        $zip = new \ZipArchive();

        if ($zip->open($backupPath) !== true) {
            $result['error'] = 'The backup archive could not be opened.';

            return $result;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!str_starts_with($name, 'files/')) {
                continue;
            }

            $relative = substr($name, 6);

            if ($relative === '' || str_ends_with($relative, '/')) {
                continue;
            }

            // A backup can contain a protected path (the archive is taken
            // before the update); restoring it would undo live configuration
            // and user uploads, so it is deliberately skipped here too.
            foreach (self::PROTECTED as $protected) {
                if ($relative === $protected || str_starts_with($relative, $protected . '/')) {
                    $result['skipped']++;
                    continue 2;
                }
            }

            // Reject any path that escapes the site root — a tampered archive
            // must not be able to write outside it.
            $target = self::safeTarget($root, $relative);

            if ($target === null) {
                $result['skipped']++;
                continue;
            }

            $dir = dirname($target);

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $result['failed']++;
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents === false || @file_put_contents($target, $contents) === false) {
                $result['failed']++;
                continue;
            }

            $result['restored']++;
        }

        $zip->close();

        // A partial restore is a failed restore: it leaves the tree mixed
        // between two releases, which is worse than either one.
        $result['ok'] = $result['failed'] === 0 && $result['restored'] > 0;

        return $result;
    }

    /**
     * Resolve $relative under $root, refusing anything that escapes it.
     * Returns null when the entry is unsafe.
     */
    private static function safeTarget(string $root, string $relative): ?string
    {
        if (str_contains($relative, "\0") || preg_match('#^([a-zA-Z]:)?[\\\\/]#', $relative) === 1) {
            return null;
        }

        $parts = [];

        foreach (preg_split('#[\\\\/]+#', $relative) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);
                continue;
            }

            $parts[] = $segment;
        }

        return $parts === [] ? null : rtrim($root, '/') . '/' . implode('/', $parts);
    }

    private static function rollback(string $backupPath): bool
    {
        try {
            $files = self::restoreFiles($backupPath, App::i()->root());

            if (!$files['ok']) {
                Logger::error('Rollback did not restore the files', $files, 'update');

                return false;
            }

            // The rollback replaced files too, so the same stale-bytecode trap
            // applies in reverse: without this the site would keep running the
            // failed release's compiled code over the restored files.
            self::resetOpcache();

            $dbRestore = BackupService::restoreDatabase($backupPath);

            // Files back but schema forward is not a rollback — say so, rather
            // than reporting success and leaving the admin to discover it.
            if (!$dbRestore['ok']) {
                Logger::error('Rollback restored files but not the database', [
                    'files' => $files['restored'],
                    'error' => $dbRestore['error'] ?? '',
                ], 'update');

                return false;
            }

            Logger::info('Rollback completed', [
                'files'   => $files['restored'],
                'skipped' => $files['skipped'],
            ], 'update');

            return true;
        } catch (\Throwable $e) {
            Logger::error('Rollback failed', ['error' => $e->getMessage()], 'update');

            return false;
        }
    }

    private static function healthCheck(): array
    {
        try {
            $db = App::i()->db();
            $db->value('SELECT 1');

            foreach (['users', 'reminders', 'reminder_occurrences', 'settings'] as $table) {
                if (!$db->tableExists($table)) {
                    return ['ok' => false, 'message' => 'Missing table: ' . $table];
                }
            }

            return ['ok' => true, 'message' => 'OK'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function notifyAdminFailure(string $error, bool $rolledBack): void
    {
        $number = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($number === '') {
            return;
        }

        WhatsAppService::queue(
            $number,
            "⚠️ *Krishna Reminder — update failed*\n\n" . mb_substr($error, 0, 400)
            . "\n\n" . ($rolledBack ? '✅ Automatic rollback completed.' : '❌ Rollback did NOT complete — check the server.'),
            null,
            null,
            1,
            'system_alert'
        );
    }

    private static function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                self::cleanup($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
