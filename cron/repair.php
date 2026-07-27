<?php

/**
 * cron/repair.php — run by hand, from the shell, when the site is misbehaving.
 *
 *   php /www/wwwroot/reminder.akdwk.in/cron/repair.php
 *
 * A self-updating application must have a way back that does not go through
 * itself. If an update leaves the site throwing 500s, the admin panel is
 * exactly what you cannot rely on to fix it. This does the three things that
 * recover it, prints what happened, and touches nothing else:
 *
 *   1. Applies any migrations that have not run yet, one at a time, reporting
 *      each by name — a migration that fails is otherwise retried and rolled
 *      back on every future update, so the update can never stick.
 *   2. Clears the compiled bytecode cache, which is what makes a *completed*
 *      update still serve a mixture of the old and new release for a minute.
 *   3. Clears the application cache and reloads settings.
 *
 * It never deletes data, never touches config.php, and is safe to run twice.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Services\CacheService;
use App\Services\TemplateService;
use App\Services\UpdateService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

$app = App::i();

if (!$app->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

$line = str_repeat('-', 68);

echo "\nKrishna Reminder — repair\n$line\n";

/* ------------------------------------------------------------ Migrations */

echo "\n1. Migrations\n";

try {
    $db = $app->db();

    $applied = [];

    foreach ($db->all('SELECT migration FROM schema_migrations') as $row) {
        $applied[(string) $row['migration']] = true;
    }

    $files = glob($app->root() . '/database/migrations/*.sql') ?: [];
    sort($files);

    $pending = array_values(array_filter($files, static fn (string $f): bool => !isset($applied[basename($f)])));

    if ($pending === []) {
        echo "   nothing pending (" . count($applied) . " already applied)\n";
    } else {
        echo "   " . count($pending) . " pending\n";

        // Deliberately not UpdateService::runMigrations(): that stops at the
        // first failure, and when you are already broken you want to know
        // which ones worked and exactly how the failing one failed.
        foreach ($pending as $file) {
            $name = basename($file);

            try {
                $statements = \App\Services\BackupService::runSqlScript((string) file_get_contents($file));

                $db->insert('schema_migrations', [
                    'migration'   => $name,
                    'batch'       => (int) $db->value('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations', [], 1),
                    'executed_at' => now_utc(),
                ]);

                echo "   ✅ $name  ($statements statement(s))\n";
            } catch (\Throwable $e) {
                echo "   ❌ $name\n";
                echo "      " . $e->getMessage() . "\n";
                echo "\n   Stopping here. Fix this before updating again — an unrecorded\n";
                echo "   migration is retried on every update and rolls the whole update back.\n";
                exit(1);
            }
        }
    }
} catch (\Throwable $e) {
    echo "   ❌ could not read migrations: " . $e->getMessage() . "\n";
    exit(1);
}

/* --------------------------------------------------------------- Opcache */

echo "\n2. Compiled bytecode cache\n";
echo "   " . UpdateService::resetOpcache() . "\n";

// Clearing it from the CLI only clears the CLI's own cache. The web server
// runs a separate PHP process with its own, so say so rather than implying the
// site is now definitely clean.
echo "   note: this clears the command-line cache. If the website still shows\n";
echo "   stale behaviour, restart PHP-FPM from aaPanel (Website → PHP → Service).\n";

/* --------------------------------------------------------- App-level cache */

echo "\n3. Application cache\n";

try {
    CacheService::flush();
    TemplateService::flush();
    $app->settings()->refresh();
    echo "   cleared\n";
} catch (\Throwable $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
}

/* ----------------------------------------------------------- Health check */

echo "\n4. Health\n";

try {
    $db = $app->db();
    $db->value('SELECT 1');

    $missing = [];

    foreach (['users', 'reminders', 'reminder_occurrences', 'settings', 'wa_outbound_queue'] as $table) {
        if (!$db->tableExists($table)) {
            $missing[] = $table;
        }
    }

    // Columns the current code needs. A missing one here means a migration did
    // not run, and is the difference between "works" and "500 on every page".
    $columns = [
        'users'           => ['app_pin_hash', 'telegram_chat_id'],
        'wa_outbound_log' => ['channel', 'provider'],
        'wa_outbound_queue' => ['channel'],
    ];

    $missingColumns = [];

    foreach ($columns as $table => $needed) {
        if (!$db->tableExists($table)) {
            continue;
        }

        foreach ($needed as $column) {
            $found = $db->value(
                'SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column],
                0
            );

            if ((int) $found === 0) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }

    if ($missing === [] && $missingColumns === []) {
        echo "   ✅ database looks correct\n";
    } else {
        foreach ($missing as $table) {
            echo "   ❌ missing table: $table\n";
        }

        foreach ($missingColumns as $column) {
            echo "   ❌ missing column: $column\n";
        }

        echo "\n   Run this script again; if a column is still missing, the migration\n";
        echo "   for it failed — the error is printed in section 1.\n";
    }

    $maintenance = $app->settings()->get('maintenance_mode', '0');

    if ((string) $maintenance === '1') {
        $app->settings()->set('maintenance_mode', '0');
        echo "   ⚠️  maintenance mode was left on — switched off\n";
    }
} catch (\Throwable $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n$line\nDone. Reload the site.\n\n";
