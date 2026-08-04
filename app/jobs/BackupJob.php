<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Logger;
use App\Services\BackupService;
use App\Services\WhatsAppService;

/**
 * The nightly database and files backup.
 *
 * Also audits the document root, because a backup archive that ends up
 * downloadable over HTTP is worse than no backup at all — it hands the whole
 * database to anyone who guesses the filename.
 */
class BackupJob extends Job
{
    public static function key(): string
    {
        return 'backup';
    }

    public static function label(): string
    {
        return 'Nightly backup';
    }

    public static function description(): string
    {
        return 'Backs up the database and uploads, and checks that nothing downloadable is exposed in the web root.';
    }

    public static function group(): string
    {
        return 'maintenance';
    }

    public static function priority(): int
    {
        return 80;
    }

    public static function scheduleKind(): string
    {
        return 'daily';
    }

    public static function runAt(): ?string
    {
        return '03:00';
    }

    public static function isHeavy(): bool
    {
        return true;
    }

    public static function timeoutSeconds(): int
    {
        return 1800;
    }

    public function handle(): array
    {
        $result = BackupService::run('full', 'cron');
        $messages = [];

        if ($result['ok']) {
            $messages[] = basename($result['path']) . ' (' . round($result['size'] / 1048576, 1) . ' MB)';
        } else {
            $this->alert((string) $result['error']);
        }

        // Safety net: nothing downloadable must sit in the document root.
        $issues = BackupService::auditWebroot();

        if ($issues !== []) {
            Logger::warn('Web root exposure detected', ['files' => $issues], 'backup');
            $messages[] = 'webroot warnings: ' . implode(', ', $issues);
        }

        if (!$result['ok']) {
            // Thrown, not returned: a failed backup must show as failed in the
            // admin panel and count towards the failure streak.
            throw new \RuntimeException((string) $result['error']);
        }

        return ['processed' => 1, 'message' => implode(' | ', $messages)];
    }

    private function alert(string $error): void
    {
        $number = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($number === '') {
            return;
        }

        WhatsAppService::queue(
            $number,
            "⚠️ *Krishna Reminder* — nightly backup failed:\n" . mb_substr($error, 0, 300),
            null,
            null,
            1,
            'system_alert'
        );
    }
}
