<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Database + files backup.
 *
 * Backups are written OUTSIDE the document root when possible (../kr-backups),
 * falling back to /backups which is denied by .htaccess. Leaving .sql/.zip
 * files reachable from the web is exactly how a domain gets flagged as
 * compromised, so this is enforced in two independent ways.
 */
class BackupService
{
    /**
     * @return array{ok: bool, id: int, path: string, size: int, error: string|null}
     */
    public static function run(string $kind = 'full', string $trigger = 'cron'): array
    {
        $db = App::i()->db();
        $dir = self::directory();

        if ($dir === null) {
            return ['ok' => false, 'id' => 0, 'path' => '', 'size' => 0, 'error' => 'Backup directory is not writable'];
        }

        $stamp = date('Ymd-His');
        $filename = 'krishna-' . $kind . '-' . $stamp . '.zip';
        $target = $dir . '/' . $filename;

        $backupId = $db->insert('backups', [
            'kind'           => $kind,
            'filename'       => $filename,
            'path'           => $target,
            'trigger_source' => $trigger,
            'status'         => 'running',
            'created_at'     => now_utc(),
        ]);

        try {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('The zip PHP extension is required for backups.');
            }

            $zip = new \ZipArchive();

            if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the backup archive.');
            }

            if ($kind === 'database' || $kind === 'full') {
                $sql = self::dumpDatabase();
                $zip->addFromString('database.sql', $sql);
            }

            if ($kind === 'files' || $kind === 'full') {
                self::addDirectory($zip, App::i()->root(), '');
            }

            $zip->addFromString('manifest.json', json_encode([
                'created_at' => now_utc(),
                'kind'       => $kind,
                'version'    => App::i()->config('app.version', '1.0.0'),
                'site'       => App::i()->url(),
            ], JSON_PRETTY_PRINT));

            $zip->close();

            $size = (int) @filesize($target);

            $db->update('backups', ['status' => 'ok', 'size_bytes' => $size], 'id = :id', ['id' => $backupId]);

            self::rotate();

            return ['ok' => true, 'id' => $backupId, 'path' => $target, 'size' => $size, 'error' => null];
        } catch (\Throwable $e) {
            Logger::error('Backup failed', ['error' => $e->getMessage()], 'backup');

            $db->update('backups', [
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ], 'id = :id', ['id' => $backupId]);

            @unlink($target);

            return ['ok' => false, 'id' => $backupId, 'path' => '', 'size' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pure-PHP mysqldump replacement (shell_exec is often disabled on shared
     * hosting, so we never rely on it).
     */
    public static function dumpDatabase(): string
    {
        $db = App::i()->db();
        $pdo = $db->pdo();

        $out = "-- Krishna Reminder database backup\n"
            . '-- Generated: ' . now_utc() . " UTC\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        $tables = $db->all('SHOW TABLES');

        foreach ($tables as $row) {
            $table = (string) array_values($row)[0];

            $create = $db->one('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $out .= "DROP TABLE IF EXISTS `$table`;\n" . ($create['Create Table'] ?? '') . ";\n\n";

            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');

            if ($stmt === false) {
                continue;
            }

            $buffer = [];

            while ($record = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $values = array_map(static function ($value) use ($pdo) {
                    if ($value === null) {
                        return 'NULL';
                    }

                    return $pdo->quote((string) $value);
                }, $record);

                $buffer[] = '(' . implode(',', $values) . ')';

                if (count($buffer) >= 200) {
                    $out .= 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $buffer) . ";\n";
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $out .= 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $buffer) . ";\n";
            }

            $out .= "\n";
        }

        return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /**
     * Restore a database dump from a backup archive.
     */
    public static function restoreDatabase(string $zipPath): array
    {
        if (!is_file($zipPath) || !class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'Backup archive not found'];
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Could not open the backup archive'];
        }

        $sql = $zip->getFromName('database.sql');
        $zip->close();

        if ($sql === false || $sql === '') {
            return ['ok' => false, 'error' => 'The archive does not contain a database dump'];
        }

        try {
            self::runSqlScript($sql);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Logger::error('Restore failed', ['error' => $e->getMessage()], 'backup');

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute a multi-statement SQL script, respecting quoted semicolons.
     */
    public static function runSqlScript(string $sql): int
    {
        $pdo = App::i()->db()->pdo();
        $executed = 0;

        $statement = '';
        $inString = false;
        $stringChar = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($inString) {
                if ($char === $stringChar && $prev !== '\\') {
                    $inString = false;
                }
            } elseif ($char === "'" || $char === '"') {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === '-' && ($sql[$i + 1] ?? '') === '-' && ($statement === '' || str_ends_with($statement, "\n"))) {
                // Skip a full-line comment.
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            } elseif ($char === ';') {
                $trimmed = trim($statement);

                if ($trimmed !== '') {
                    $pdo->exec($trimmed);
                    $executed++;
                }

                $statement = '';
                continue;
            }

            $statement .= $char;
        }

        $trimmed = trim($statement);

        if ($trimmed !== '') {
            $pdo->exec($trimmed);
            $executed++;
        }

        return $executed;
    }

    /* -------------------------------------------------------------- Helpers */

    /**
     * Prefer a sibling directory of the web root; fall back to /backups with a
     * deny-all .htaccess.
     */
    public static function directory(): ?string
    {
        $root = App::i()->root();
        $candidates = [dirname($root) . '/kr-backups', $root . '/backups'];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }

            if (is_dir($dir) && is_writable($dir)) {
                self::protect($dir);

                return $dir;
            }
        }

        return null;
    }

    private static function protect(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }

        $index = $dir . '/index.html';

        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    private static function addDirectory(\ZipArchive $zip, string $path, string $prefix): void
    {
        $skip = ['.git', 'storage/cache', 'storage/temp', 'backups', 'node_modules', 'android/build', 'android/.gradle', 'vendor'];

        $items = @scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            $relative = ($prefix === '' ? '' : $prefix . '/') . $item;

            foreach ($skip as $skipPath) {
                if ($relative === $skipPath || str_starts_with($relative, $skipPath . '/')) {
                    continue 2;
                }
            }

            if (is_dir($full)) {
                $zip->addEmptyDir('files/' . $relative);
                self::addDirectory($zip, $full, $relative);
            } elseif (is_file($full) && filesize($full) < 33554432) {
                $zip->addFile($full, 'files/' . $relative);
            }
        }
    }

    /**
     * Delete backups older than the configured retention window.
     */
    public static function rotate(): int
    {
        $days = max(1, App::i()->settings()->int('backup_retention_days', 14));
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        $old = App::i()->db()->all('SELECT * FROM backups WHERE created_at < ?', [$cutoff]);
        $removed = 0;

        foreach ($old as $backup) {
            if (is_file((string) $backup['path'])) {
                @unlink((string) $backup['path']);
            }

            App::i()->db()->delete('backups', 'id = ?', [(int) $backup['id']]);
            $removed++;
        }

        return $removed;
    }

    /**
     * Safety audit used by the health endpoint and the admin System page:
     * nothing dangerous must be reachable from the document root.
     */
    public static function auditWebroot(): array
    {
        $root = App::i()->root();
        $issues = [];

        foreach (['*.sql', '*.zip', '*.tar.gz', '*.bak'] as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $file) {
                $issues[] = basename($file);
            }
        }

        $backupDir = $root . '/backups';

        if (is_dir($backupDir) && !is_file($backupDir . '/.htaccess')) {
            $issues[] = 'backups/ has no .htaccess protection';
        }

        return $issues;
    }
}
