<?php

/**
 * Proves the updater's rollback actually works — by deliberately breaking an
 * update part-way through and checking what survives.
 *
 * This runs entirely on a throwaway directory tree in the system temp folder.
 * It never touches the live site, never contacts GitHub and needs no database,
 * which is exactly why it is safe to run before an update is ever attempted on
 * a real host. The staging drill in docs/UPDATER-ROLLBACK-DRILL.md covers the
 * parts that need a live server (database restore, maintenance mode, the admin
 * WhatsApp alert); this covers the file-level behaviour those depend on.
 *
 * What it forces to fail: the copy step hits a file it cannot write, half way
 * through the tree, with earlier files already overwritten. That is the worst
 * realistic case — a partly-applied release.
 *
 * Run:  php tests/verify_update_rollback.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\UpdateService;

$pass = 0;
$fail = 0;

function assertThat(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %-58s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  — ' . $detail : '');
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        @chmod($path, 0777);
        is_dir($path) ? rmTree($path) : @unlink($path);
    }

    @rmdir($dir);
}

function writeFile(string $path, string $contents): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
}

$base = sys_get_temp_dir() . '/kr-rollback-' . getmypid();
rmTree($base);

$site   = $base . '/site';       // stands in for the live document root
$source = $base . '/release';    // stands in for the extracted new release
$backup = $base . '/backup.zip';

register_shutdown_function(static fn () => rmTree($base));

echo "\n=== 1. A site that looks like a real install ===\n\n";

// Application files — these are what an update is allowed to replace.
writeFile($site . '/index.php', "<?php // v1 front controller\n");
writeFile($site . '/app/core/App.php', "<?php // v1 core\n");
writeFile($site . '/app/services/GeminiService.php', "<?php // v1 gemini\n");
writeFile($site . '/public/css/app.css', "body{color:#1B3A6B}/*v1*/\n");

// In this release "app/legacy" is a plain file. In the new release it becomes
// a directory — see below. That collision is what breaks the update.
writeFile($site . '/app/legacy', "v1 legacy shim\n");

// Everything below must survive an update AND a rollback untouched.
writeFile($site . '/config/config.php', "<?php return ['db'=>['pass'=>'REAL-PASSWORD']];\n");
writeFile($site . '/config/install.lock', "locked 2026-07-27\n");
writeFile($site . '/.env', "APP_KEY=REAL-KEY\n");
writeFile($site . '/uploads/2026/07/receipt.jpg', "REAL USER UPLOAD\n");
writeFile($site . '/storage/logs/app-2026-07-27.log', "REAL LOG\n");
writeFile($site . '/backups/backup-2026-07-26.zip', "REAL BACKUP\n");
writeFile($site . '/.git/HEAD', "ref: refs/heads/main\n");

$sacred = [
    'config/config.php'              => "<?php return ['db'=>['pass'=>'REAL-PASSWORD']];\n",
    'config/install.lock'            => "locked 2026-07-27\n",
    '.env'                           => "APP_KEY=REAL-KEY\n",
    'uploads/2026/07/receipt.jpg'    => "REAL USER UPLOAD\n",
    'storage/logs/app-2026-07-27.log' => "REAL LOG\n",
    'backups/backup-2026-07-26.zip'  => "REAL BACKUP\n",
    '.git/HEAD'                      => "ref: refs/heads/main\n",
];

assertThat('site tree created', is_file($site . '/index.php') && is_file($site . '/config/config.php'));

echo "\n=== 2. Backup taken before the update, in the real archive format ===\n\n";

// Mirrors BackupService::run(): every file under files/, plus database.sql.
$zip = new ZipArchive();
$zip->open($backup, ZipArchive::CREATE | ZipArchive::OVERWRITE);

$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$archived = 0;

foreach ($walk as $item) {
    $relative = substr($item->getPathname(), strlen($site) + 1);

    if ($item->isFile()) {
        $zip->addFile($item->getPathname(), 'files/' . $relative);
        $archived++;
    }
}

$zip->addFromString('database.sql', "-- schema dump\n");
$zip->addFromString('manifest.json', json_encode(['type' => 'full', 'trigger' => 'update']));
$zip->close();

assertThat('backup archive written', is_file($backup) && filesize($backup) > 0, $archived . ' file(s)');

echo "\n=== 3. The new release, which collides with the installed tree ===\n\n";

writeFile($source . '/index.php', "<?php // v2 front controller\n");
writeFile($source . '/app/core/App.php', "<?php // v2 core\n");
writeFile($source . '/app/services/GeminiService.php', "<?php // v2 gemini\n");
writeFile($source . '/public/css/app.css', "body{color:#1B3A6B}/*v2*/\n");
writeFile($source . '/.updateignore', "public/css/vendor\n# a comment\n");

// The break: "app/legacy" is a file on the installed site and a directory in
// this release, so mkdir() cannot succeed and the copy aborts mid-tree. A
// permission-based failure would not do — this suite may run as root, where
// chmod 0444 is no obstacle at all, and a test that silently stops testing is
// worse than no test.
writeFile($source . '/app/legacy/Helper.php', "<?php // v2 helper\n");

// The release also carries these — a careless updater would clobber them.
writeFile($source . '/config/config.php', "<?php return ['db'=>['pass'=>'PLACEHOLDER']];\n");
writeFile($source . '/uploads/.gitkeep', "");
writeFile($source . '/storage/logs/.gitkeep', "");

$ignore = UpdateService::ignoreList($source);

assertThat('protected list covers config/config.php', in_array('config/config.php', $ignore, true));
assertThat('protected list covers uploads', in_array('uploads', $ignore, true));
assertThat('protected list covers storage', in_array('storage', $ignore, true));
assertThat('.updateignore entries are honoured', in_array('public/css/vendor', $ignore, true));
assertThat('.updateignore comments are not treated as paths',
    !in_array('# a comment', $ignore, true));

echo "\n=== 4. The update fails half way through ===\n\n";

$threw = false;
$error = '';

try {
    UpdateService::copyTree($source, $site, $ignore);
} catch (\Throwable $e) {
    $threw = true;
    $error = $e->getMessage();
}

assertThat('the failure is raised, not swallowed', $threw, $error);

$partiallyApplied = str_contains((string) @file_get_contents($site . '/app/core/App.php'), 'v2')
    && str_contains((string) @file_get_contents($site . '/index.php'), 'v1');

assertThat('the tree really is left part-updated', $partiallyApplied,
    'v2 core, v1 front controller — otherwise the rollback below proves nothing');

echo "\n=== 5. Nothing protected was touched by the failed update ===\n\n";

foreach ($sacred as $relative => $expected) {
    assertThat(
        'survived the update: ' . $relative,
        @file_get_contents($site . '/' . $relative) === $expected
    );
}

echo "\n=== 6. Rollback puts the application files back ===\n\n";

$restore = UpdateService::restoreFiles($backup, $site);

assertThat('rollback reports success', $restore['ok'] === true,
    'restored ' . $restore['restored'] . ', skipped ' . $restore['skipped'] . ', failed ' . $restore['failed']);

assertThat('index.php is back to v1',
    str_contains((string) file_get_contents($site . '/index.php'), 'v1'));

assertThat('app/core/App.php is back to v1',
    str_contains((string) file_get_contents($site . '/app/core/App.php'), 'v1'));

assertThat('app/services/GeminiService.php is back to v1',
    str_contains((string) file_get_contents($site . '/app/services/GeminiService.php'), 'v1'));

assertThat('public/css/app.css is back to v1',
    str_contains((string) file_get_contents($site . '/public/css/app.css'), 'v1'));

assertThat('app/legacy is a file again, with its v1 contents',
    is_file($site . '/app/legacy')
    && str_contains((string) file_get_contents($site . '/app/legacy'), 'v1'));

echo "\n=== 7. Rollback did not undo live configuration or user data ===\n\n";

foreach ($sacred as $relative => $expected) {
    assertThat(
        'survived the rollback: ' . $relative,
        @file_get_contents($site . '/' . $relative) === $expected
    );
}

assertThat('protected entries were skipped, not restored', $restore['skipped'] > 0,
    $restore['skipped'] . ' skipped');

echo "\n=== 8. A tampered archive cannot write outside the site root ===\n\n";

$evil = $base . '/evil.zip';
$outside = $base . '/OWNED.txt';

$zip = new ZipArchive();
$zip->open($evil, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('files/../OWNED.txt', "pwned\n");
$zip->addFromString('files/../../OWNED.txt', "pwned\n");
$zip->addFromString('files/index.php', "<?php // v1 front controller\n");
$zip->close();

$evilResult = UpdateService::restoreFiles($evil, $site);

assertThat('path traversal is refused', !is_file($outside) && !is_file($base . '/../OWNED.txt'));
assertThat('the safe entry still restored', $evilResult['restored'] >= 1);

echo "\n=== 9. A missing or unreadable backup fails loudly ===\n\n";

$missing = UpdateService::restoreFiles($base . '/does-not-exist.zip', $site);

assertThat('missing archive -> ok is false', $missing['ok'] === false);
assertThat('missing archive -> reason given', ($missing['error'] ?? '') !== '');

$emptyZip = $base . '/empty.zip';
$zip = new ZipArchive();
$zip->open($emptyZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('database.sql', "-- nothing\n");
$zip->close();

$empty = UpdateService::restoreFiles($emptyZip, $site);

assertThat('archive with no files/ entries -> ok is false', $empty['ok'] === false,
    'restored ' . $empty['restored']);

echo "\n" . str_repeat('-', 78) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nRollback confirmed on a throwaway tree: a half-applied update is reverted,\n";
    echo "config, uploads, storage, backups and .git are never touched by either the\n";
    echo "update or the rollback, and a tampered archive cannot escape the site root.\n";
    echo "\nNothing here ran against a live site. Before the first real update, do the\n";
    echo "staging drill in docs/UPDATER-ROLLBACK-DRILL.md.\n";
}

exit($fail > 0 ? 1 : 0);
