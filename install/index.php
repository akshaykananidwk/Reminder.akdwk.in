<?php

/**
 * Krishna Reminder — one-click installer.
 *
 * Upload the ZIP, extract it, open /install. That is the whole procedure:
 * this wizard writes config/config.php itself, imports the schema, seeds the
 * data, verifies the WhatsApp gateway and Gemini live, and finishes by writing
 * config/install.lock so it can never run twice.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Crypto;
use App\Core\Database;
use App\Services\BackupService;
use App\Services\GeminiService;
use App\Services\UpdateService;

/*
 * During installation a generic "something went wrong" page is useless — the
 * whole point of this screen is to tell you what to fix. Replace the app's
 * production error page with a readable diagnostic.
 */
set_exception_handler(static function (Throwable $e): void {
    // Drop any half-rendered page so the diagnostic is all that shows.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    $log = dirname(__DIR__) . '/storage/logs/php-' . date('Y-m-d') . '.log';

    render_shell('Installer error', '
        <div class="card">
            <h2>⚠️ The installer hit an error</h2>
            <p>This is the real message — please send it to support if it is not obvious:</p>
            <pre class="code">' . htmlspecialchars(
                get_class($e) . ': ' . $e->getMessage()
                . "\n\nat " . $e->getFile() . ':' . $e->getLine()
                . "\n\n" . $e->getTraceAsString(),
                ENT_QUOTES,
                'UTF-8'
            ) . '</pre>
            <p class="hint">PHP ' . PHP_VERSION . ' · full log: <code>' . htmlspecialchars($log, ENT_QUOTES, 'UTF-8') . '</code></p>
            <p><a class="btn" href="?step=1">← Back to the installer</a></p>
        </div>');

    exit(1);
});

session_name('KRINSTALL');

// A broken session save path is a classic shared-hosting failure; fall back to
// a directory we know is writable rather than dying on step 1.
if (!@session_start()) {
    $sessionDir = dirname(__DIR__) . '/storage/temp/sessions';

    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0770, true);
    }

    @session_save_path($sessionDir);
    @session_start();
}

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$lockFile = $root . '/config/install.lock';

/* ------------------------------------------------------- Already installed */

if (is_file($lockFile)) {
    http_response_code(403);
    render_shell('Already installed', '
        <div class="card">
            <h2>✅ Krishna Reminder is already installed</h2>
            <p>The installer is locked because <code>config/install.lock</code> exists.</p>
            <p><strong>Delete the <code>/install</code> folder from your server now.</strong></p>
            <p><a class="btn" href="../admin/login">Go to the admin panel</a></p>
        </div>');
    exit;
}

/* ---------------------------------------------------------------- Routing */

$steps = [
    1 => 'Requirements',
    2 => 'Database',
    3 => 'Site settings',
    4 => 'Admin account',
    5 => 'WhatsApp API',
    6 => 'Gemini AI',
    7 => 'Cron setup',
    8 => 'Finish',
];

$step = max(1, min(8, (int) ($_GET['step'] ?? 1)));

// Steps beyond 2 need a working config; bounce back if it is missing.
if ($step > 2 && !is_file($configFile)) {
    header('Location: ?step=2');
    exit;
}

$errors = [];
$notices = [];
$testResult = null;

/* ------------------------------------------------------------ CSRF guard */

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}

$csrf = (string) $_SESSION['install_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    $errors[] = 'Your session expired. Please reload the page and try again.';
    $_POST = [];
}

/* =============================================================== Step logic */

$post = $_SERVER['REQUEST_METHOD'] === 'POST' && $errors === [];

switch ($step) {
    /* ------------------------------------------------------ 1. Requirements */
    case 1:
        $checks = requirement_checks($root);

        if ($post) {
            $blocking = array_filter($checks, static fn ($c) => !$c['ok'] && $c['required']);

            if ($blocking === []) {
                header('Location: ?step=2');
                exit;
            }

            $errors[] = 'Please fix the items marked in red before continuing.';
        }
        break;

    /* ---------------------------------------------------------- 2. Database */
    case 2:
        if ($post) {
            $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
            $port = (int) ($_POST['db_port'] ?? 3306);
            $name = trim((string) ($_POST['db_name'] ?? ''));
            $user = trim((string) ($_POST['db_user'] ?? ''));
            $pass = (string) ($_POST['db_pass'] ?? '');

            if ($name === '' || $user === '') {
                $errors[] = 'Database name and user are required.';
                break;
            }

            try {
                $db = new Database($host, $port, $name, $user, $pass);
                $db->pdo(); // Connect or throw.

                // Import schema + seed.
                $schema = (string) file_get_contents($root . '/database/schema.sql');
                run_sql_script($db, $schema);

                $seed = (string) file_get_contents($root . '/database/seed.sql');
                run_sql_script($db, $seed);

                // Write config so every later step (and the app) can boot.
                $config = [
                    'APP_NAME'       => 'Krishna Reminder',
                    'APP_URL'        => detect_base_url(),
                    'APP_TIMEZONE'   => 'Asia/Kolkata',
                    'APP_LOCALE'     => 'gu',
                    'APP_CURRENCY'   => 'INR',
                    'APP_KEY'        => Crypto::generateKey(),
                    'DB_HOST'        => $host,
                    'DB_PORT'        => (string) $port,
                    'DB_NAME'        => $name,
                    'DB_USER'        => $user,
                    'DB_PASS'        => $pass,
                    'CRON_TOKEN'     => bin2hex(random_bytes(20)),
                    'WEBHOOK_SECRET' => bin2hex(random_bytes(20)),
                ];

                write_config($root, $config);

                $_SESSION['install'] = ['db' => true];

                header('Location: ?step=3');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Could not connect or import: ' . $e->getMessage();
            }
        }
        break;

    /* ----------------------------------------------------- 3. Site settings */
    case 3:
        if ($post) {
            $siteName = trim((string) ($_POST['site_name'] ?? 'Krishna Reminder'));
            $siteUrl = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
            $timezone = (string) ($_POST['timezone'] ?? 'Asia/Kolkata');
            $language = (string) ($_POST['language'] ?? 'en');
            $currency = strtoupper(substr((string) ($_POST['currency'] ?? 'INR'), 0, 3));

            if ($siteName === '' || $siteUrl === '') {
                $errors[] = 'Site name and URL are required.';
                break;
            }

            if (!in_array($timezone, timezone_identifiers_list(), true)) {
                $timezone = 'Asia/Kolkata';
            }

            update_config($root, [
                'app' => [
                    'name'     => $siteName,
                    'url'      => $siteUrl,
                    'timezone' => $timezone,
                    'locale'   => $language,
                    'currency' => $currency,
                ],
            ]);

            $settings = App::i()->settings();
            $settings->setMany([
                'site_name'         => $siteName,
                'default_language'  => $language,
                'default_timezone'  => $timezone,
                'currency'          => $currency,
                'company_name'      => trim((string) ($_POST['company_name'] ?? 'AK Computer')),
                'company_address'   => trim((string) ($_POST['company_address'] ?? '')),
                'company_phone'     => trim((string) ($_POST['company_phone'] ?? '')),
                'company_email'     => trim((string) ($_POST['company_email'] ?? '')),
                'company_gstin'     => trim((string) ($_POST['company_gstin'] ?? '')),
            ]);

            header('Location: ?step=4');
            exit;
        }
        break;

    /* ----------------------------------------------------- 4. Admin account */
    case 4:
        if ($post) {
            $name = trim((string) ($_POST['admin_name'] ?? ''));
            $email = trim((string) ($_POST['admin_email'] ?? ''));
            $phone = normalize_phone((string) ($_POST['admin_phone'] ?? ''));
            $password = (string) ($_POST['admin_password'] ?? '');

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
                $errors[] = 'Please enter a name, a valid email and a valid WhatsApp number.';
                break;
            }

            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Password must be at least 8 characters and contain letters and numbers.';
                break;
            }

            $db = App::i()->db();

            $existing = $db->one('SELECT id FROM admins WHERE email = ?', [$email]);

            $data = [
                'name'          => $name,
                'email'         => $email,
                'phone'         => $phone,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'role'          => 'owner',
                'is_active'     => 1,
            ];

            if ($existing === null) {
                $db->insert('admins', array_merge($data, ['created_at' => gmdate('Y-m-d H:i:s')]));
            } else {
                $db->update('admins', $data, 'id = :id', ['id' => (int) $existing['id']]);
            }

            App::i()->settings()->setMany([
                'alert_admin_number' => $phone,
                'company_email'      => $email,
            ]);

            $_SESSION['install']['admin_phone'] = $phone;

            header('Location: ?step=5');
            exit;
        }
        break;

    /* ------------------------------------------------------ 5. WhatsApp API */
    case 5:
        if ($post) {
            $endpoint = rtrim(trim((string) ($_POST['wa_endpoint'] ?? '')), '/');
            $apiKey = trim((string) ($_POST['wa_api_key'] ?? ''));
            $sessionId = trim((string) ($_POST['wa_session_id'] ?? ''));
            $sender = normalize_phone((string) ($_POST['wa_sender_number'] ?? ''));
            $testNumber = normalize_phone((string) ($_POST['test_number'] ?? ($_SESSION['install']['admin_phone'] ?? '')));

            $settings = App::i()->settings();
            $settings->set('wa_endpoint', $endpoint);
            $settings->set('wa_api_key', $apiKey, true, 'whatsapp');
            $settings->set('wa_session_id', $sessionId, true, 'whatsapp');
            $settings->set('wa_sender_number', $sender, false, 'whatsapp');

            if (isset($_POST['skip'])) {
                header('Location: ?step=6');
                exit;
            }

            if ($endpoint === '' || $apiKey === '' || $sessionId === '' || $testNumber === '') {
                $errors[] = 'Endpoint, API key, session id and a test number are all required.';
                break;
            }

            // Live test message — this must arrive before we move on.
            $result = \App\Services\WhatsAppService::sendNow(
                $testNumber,
                "🙏 *Krishna Reminder*\nInstallation test message — your WhatsApp gateway is working.\n\n" . date('d M Y, h:i A')
            );

            $testResult = $result;

            if ($result['ok'] && isset($_POST['confirmed'])) {
                header('Location: ?step=6');
                exit;
            }

            if (!$result['ok']) {
                $errors[] = 'Gateway responded with an error: ' . $result['response'];
            } else {
                $notices[] = 'Test message sent. Check the phone, then press "I received it" to continue.';
            }
        }
        break;

    /* --------------------------------------------------------- 6. Gemini AI */
    case 6:
        if ($post) {
            $apiKey = trim((string) ($_POST['gemini_api_key'] ?? ''));
            $model = trim((string) ($_POST['gemini_model'] ?? 'gemini-2.0-flash'));

            $settings = App::i()->settings();
            $settings->set('gemini_api_key', $apiKey, true, 'ai');
            $settings->set('gemini_model', $model, false, 'ai');

            if (isset($_POST['skip'])) {
                $settings->set('gemini_enabled', $apiKey === '' ? '0' : '1', false, 'ai');
                header('Location: ?step=7');
                exit;
            }

            if ($apiKey === '') {
                $errors[] = 'Enter a Gemini API key, or choose "Skip for now" to use the built-in fallback parser.';
                break;
            }

            $test = GeminiService::testConnection($apiKey, $model);
            $testResult = $test;

            if ($test['ok']) {
                $settings->set('gemini_enabled', '1', false, 'ai');
                header('Location: ?step=7');
                exit;
            }

            // A quota/rate-limit answer proves the key is real. Keep it, keep
            // Gemini switched on, and let the installation continue — blocking
            // here would be wrong, because nothing is actually misconfigured.
            if (!empty($test['usable'])) {
                $settings->set('gemini_enabled', '1', false, 'ai');
                $notices[] = $test['message'] . ' The key has been saved and Gemini stays enabled.';
            } else {
                $settings->set('gemini_enabled', '0', false, 'ai');
                $errors[] = $test['message'];
            }
        }
        break;

    /* -------------------------------------------------------- 7. Cron setup */
    case 7:
        if ($post) {
            // Verify at least one cron run has been recorded.
            $row = App::i()->db()->one('SELECT id, started_at FROM cron_runs ORDER BY id DESC LIMIT 1');

            if ($row === null && !isset($_POST['skip'])) {
                $errors[] = 'No cron run detected yet. Add the scheduled tasks and wait a minute, or press "Continue anyway".';
                break;
            }

            header('Location: ?step=8');
            exit;
        }
        break;

    /* ------------------------------------------------------------ 8. Finish */
    case 8:
        if (!is_file($lockFile)) {
            UpdateService::markAllMigrationsApplied();

            // Make sure the backup directory exists and is protected before the
            // first nightly run.
            BackupService::directory();

            $written = @file_put_contents($lockFile, json_encode([
                'installed_at' => gmdate('c'),
                'version'      => App::i()->config('app.version', '1.0.0'),
                'php'          => PHP_VERSION,
            ], JSON_PRETTY_PRINT));

            // Without this file the app considers itself uninstalled and sends
            // every visitor straight back here — so a failure must be loud.
            if ($written === false) {
                $errors[] = 'Could not write config/install.lock. Until that file exists the site keeps '
                    . 'redirecting to the installer. Fix the permission and reload this page:  '
                    . 'chown -R www:www ' . $root . '/config  &&  chmod 755 ' . $root . '/config';
            }

            App::i()->settings()->set('installed_at', gmdate('Y-m-d H:i:s'));
        }
        break;
}

/* ==================================================================== View */

ob_start();
?>
<div class="wizard">
    <aside>
        <div class="brand">
            <div class="logo">🕉️</div>
            <div>
                <strong>Krishna Reminder</strong>
                <span>Installer</span>
            </div>
        </div>
        <ol class="steps">
            <?php foreach ($steps as $number => $label): ?>
                <li class="<?= $number < $step ? 'done' : ($number === $step ? 'current' : '') ?>">
                    <span class="num"><?= $number < $step ? '✓' : $number ?></span>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="foot">AK Computer · Dwarka<br>+91 99781 23146</p>
    </aside>

    <main>
        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($notices as $notice): ?>
            <div class="alert ok"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($step === 1): ?>
            <?php if (is_file($configFile)): ?>
                <div class="callout">
                    <strong>Configuration already exists.</strong>
                    The database is set up but <code>config/install.lock</code> is missing, which is why the
                    site keeps returning here. Jump straight to the step you need:
                    <p style="margin-top:10px">
                        <a class="btn" href="?step=8">Finish &amp; unlock the site →</a>
                        <a class="btn ghost" href="?step=5">WhatsApp</a>
                        <a class="btn ghost" href="?step=6">Gemini</a>
                        <a class="btn ghost" href="?step=7">Cron</a>
                    </p>
                </div>
            <?php endif; ?>

            <h1>Server requirements</h1>
            <p class="lead">Everything in red must be fixed before Krishna Reminder can run.</p>
            <table class="checks">
                <?php foreach (requirement_checks($root) as $check): ?>
                    <tr class="<?= $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') ?>">
                        <td><?= htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="value"><?= htmlspecialchars($check['value'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="badge"><?= $check['ok'] ? 'OK' : ($check['required'] ? 'REQUIRED' : 'OPTIONAL') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <form method="post">
                <?= csrf_field($csrf) ?>
                <button class="btn" type="submit">Re-check and continue →</button>
            </form>

        <?php elseif ($step === 2): ?>
            <h1>Database</h1>
            <p class="lead">Create an empty MySQL database in aaPanel, then enter its details here. The schema and seed data are imported automatically.</p>
            <form method="post" class="form">
                <?= csrf_field($csrf) ?>
                <div class="row">
                    <label>Host<input name="db_host" value="<?= htmlspecialchars((string) ($_POST['db_host'] ?? 'localhost'), ENT_QUOTES, 'UTF-8') ?>" required></label>
                    <label>Port<input name="db_port" type="number" value="<?= htmlspecialchars((string) ($_POST['db_port'] ?? '3306'), ENT_QUOTES, 'UTF-8') ?>" required></label>
                </div>
                <label>Database name<input name="db_name" value="<?= htmlspecialchars((string) ($_POST['db_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Database user<input name="db_user" value="<?= htmlspecialchars((string) ($_POST['db_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Database password<input name="db_pass" type="password" value=""></label>
                <button class="btn" type="submit">Test connection &amp; import →</button>
            </form>

        <?php elseif ($step === 3): ?>
            <h1>Site settings</h1>
            <form method="post" class="form">
                <?= csrf_field($csrf) ?>
                <label>Site name<input name="site_name" value="Krishna Reminder" required></label>
                <label>Site URL<input name="site_url" value="<?= htmlspecialchars(detect_base_url(), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <div class="row">
                    <label>Timezone
                        <select name="timezone">
                            <?php foreach (['Asia/Kolkata', 'Asia/Dubai', 'Asia/Karachi', 'UTC', 'Europe/London', 'America/New_York'] as $tz): ?>
                                <option value="<?= $tz ?>"<?= $tz === 'Asia/Kolkata' ? ' selected' : '' ?>><?= $tz ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Default language
                        <select name="language">
                            <option value="en" selected>English</option>
                            <option value="gu">ગુજરાતી (Gujarati)</option>
                            <option value="hi">हिन्दी (Hindi)</option>
                        </select>
                    </label>
                    <label>Currency<input name="currency" value="INR" maxlength="3"></label>
                </div>
                <h3>Company details (used on GST invoices)</h3>
                <label>Company name<input name="company_name" value="AK Computer"></label>
                <label>Address<input name="company_address" value="Shreeji Shopping Center, near City Palace Hotel, Dwarka, Gujarat"></label>
                <div class="row">
                    <label>Phone<input name="company_phone" value="+91 99781 23146"></label>
                    <label>Email<input name="company_email" type="email" value=""></label>
                    <label>GSTIN <span class="hint">optional</span><input name="company_gstin" value=""></label>
                </div>
                <button class="btn" type="submit">Save &amp; continue →</button>
            </form>

        <?php elseif ($step === 4): ?>
            <h1>Admin account</h1>
            <p class="lead">This is the owner login for <code>/admin</code>.</p>
            <form method="post" class="form">
                <?= csrf_field($csrf) ?>
                <label>Your name<input name="admin_name" required value="<?= htmlspecialchars((string) ($_POST['admin_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Email<input name="admin_email" type="email" required value="<?= htmlspecialchars((string) ($_POST['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>WhatsApp number <span class="hint">alerts and the installer test message go here</span>
                    <input name="admin_phone" required placeholder="9978123146" value="<?= htmlspecialchars((string) ($_POST['admin_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <label>Password
                    <input name="admin_password" id="pw" type="password" required minlength="8">
                    <span class="meter"><i id="meterbar"></i></span>
                    <span class="hint" id="meterlabel">At least 8 characters with letters and numbers.</span>
                </label>
                <button class="btn" type="submit">Create admin →</button>
            </form>

        <?php elseif ($step === 5): ?>
            <h1>WhatsApp gateway</h1>
            <p class="lead">Krishna Reminder sends and receives everything through your own gateway at <code>bulk.akdwk.in</code>.</p>
            <form method="post" class="form">
                <?= csrf_field($csrf) ?>
                <label>API endpoint <span class="hint">without <code>/api.php</code></span>
                    <input name="wa_endpoint" value="<?= htmlspecialchars((string) ($_POST['wa_endpoint'] ?? 'https://bulk.akdwk.in'), ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>API key<input name="wa_api_key" value="<?= htmlspecialchars((string) ($_POST['wa_api_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Session id<input name="wa_session_id" value="<?= htmlspecialchars((string) ($_POST['wa_session_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Sender device number<input name="wa_sender_number" value="<?= htmlspecialchars((string) ($_POST['wa_sender_number'] ?? '919978123146'), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Send a test message to<input name="test_number" value="<?= htmlspecialchars((string) ($_POST['test_number'] ?? ($_SESSION['install']['admin_phone'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"></label>

                <?php if ($testResult !== null && $testResult['ok']): ?>
                    <div class="alert ok">
                        <strong>Message sent.</strong> Gateway response:
                        <code><?= htmlspecialchars(substr((string) $testResult['response'], 0, 300), ENT_QUOTES, 'UTF-8') ?></code>
                    </div>
                    <button class="btn" type="submit" name="confirmed" value="1">I received it — continue →</button>
                <?php else: ?>
                    <button class="btn" type="submit">Save &amp; send test message</button>
                <?php endif; ?>
                <button class="btn ghost" type="submit" name="skip" value="1">Skip for now</button>
            </form>
            <div class="callout">
                <strong>Inbound webhook</strong><br>
                Set the gateway's outbound webhook URL to:<br>
                <code><?= htmlspecialchars(detect_base_url() . '/api/wa_webhook.php?secret=' . (string) App::i()->config('security.webhook_secret', ''), ENT_QUOTES, 'UTF-8') ?></code>
            </div>

        <?php elseif ($step === 6): ?>
            <h1>Google Gemini</h1>
            <p class="lead">Gemini turns plain Gujarati/Hindi/English messages into reminders. Without a key the built-in regex parser is used instead — the product still works, just with less understanding.</p>
            <form method="post" class="form">
                <?= csrf_field($csrf) ?>
                <label>Gemini API key<input name="gemini_api_key" value="<?= htmlspecialchars((string) ($_POST['gemini_api_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Model
                    <select name="gemini_model">
                        <option value="gemini-2.0-flash" selected>gemini-2.0-flash (recommended)</option>
                        <option value="gemini-2.0-flash-lite">gemini-2.0-flash-lite (cheapest)</option>
                        <option value="gemini-1.5-flash">gemini-1.5-flash</option>
                        <option value="gemini-1.5-pro">gemini-1.5-pro</option>
                    </select>
                </label>
                <?php if ($testResult !== null): ?>
                    <div class="alert <?= $testResult['ok'] ? 'ok' : (!empty($testResult['usable']) ? 'warn' : 'error') ?>">
                        <strong><?= htmlspecialchars((string) ($testResult['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>

                        <?php if (!empty($testResult['hint'])): ?>
                            <div style="margin-top:8px;font-size:13.5px">
                                <?= htmlspecialchars((string) $testResult['hint'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($testResult !== null && !empty($testResult['usable']) && empty($testResult['ok'])): ?>
                    <button class="btn" type="submit" name="skip" value="1">Key saved — continue →</button>
                    <button class="btn ghost" type="submit">Test again</button>
                <?php else: ?>
                    <button class="btn" type="submit">Test key &amp; continue →</button>
                    <button class="btn ghost" type="submit" name="skip" value="1">Skip for now</button>
                <?php endif; ?>
            </form>

            <div class="callout">
                <strong>Gemini is optional.</strong> Krishna Reminder ships with a Gujarati / Hindi / English
                parser that runs on the server with no API at all — it understands કાલે, પરમ દિવસે,
                દર સોમવારે, દર મહિને 5 તારીખે, 15 મિનિટ પછી and amounts like 5 હજાર.
                Gemini simply widens what can be understood, and takes over the moment a working key is available.
            </div>

        <?php elseif ($step === 7): ?>
            <?php
            $php = PHP_BINARY && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/bin/php';
            $token = (string) App::i()->config('security.cron_token', '');
            $lastRun = App::i()->db()->one('SELECT job, started_at FROM cron_runs ORDER BY id DESC LIMIT 1');
            ?>
            <h1>Scheduled tasks</h1>
            <p class="lead">Paste these into aaPanel → Cron. Everything the product does in the background runs from here.</p>
            <pre class="code">* * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/dispatcher.php >/dev/null 2>&amp;1
* * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/ai_queue.php >/dev/null 2>&amp;1
* * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/wa_queue.php >/dev/null 2>&amp;1
*/5 * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/morning_brief.php >/dev/null 2>&amp;1
*/5 * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/daily_summary.php >/dev/null 2>&amp;1
*/15 * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/google_sync.php >/dev/null 2>&amp;1
0 * * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/recurrence.php >/dev/null 2>&amp;1
0 9 * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/subscriptions.php >/dev/null 2>&amp;1
0 3 * * * <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/backup.php >/dev/null 2>&amp;1
0 4 * * 0 <?= htmlspecialchars($php, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($root, ENT_QUOTES, 'UTF-8') ?>/cron/cleanup.php >/dev/null 2>&amp;1
</pre>
            <div class="callout">
                <strong>No PHP-CLI cron available?</strong> Use a URL monitor instead, once a minute:<br>
                <code><?= htmlspecialchars(detect_base_url() . '/cron.php?job=all&token=' . $token, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
            <p class="<?= $lastRun ? 'good' : 'warn-text' ?>">
                <?php if ($lastRun): ?>
                    ✅ Detected a cron run: <strong><?= htmlspecialchars((string) $lastRun['job'], ENT_QUOTES, 'UTF-8') ?></strong>
                    at <?= htmlspecialchars((string) $lastRun['started_at'], ENT_QUOTES, 'UTF-8') ?> UTC.
                <?php else: ?>
                    ⏳ No cron run detected yet. Add the tasks above, wait one minute, then press Verify.
                <?php endif; ?>
            </p>
            <form method="post" class="form">
                <?= csrf_field($csrf) ?>
                <button class="btn" type="submit">Verify &amp; continue →</button>
                <button class="btn ghost" type="submit" name="skip" value="1">Continue anyway</button>
            </form>

        <?php else: ?>
            <?php $locked = is_file($lockFile); ?>

            <h1><?= $locked ? '🎉 Installation complete' : '⚠️ Almost there' ?></h1>

            <?php if ($locked): ?>
                <p class="lead">Krishna Reminder is live. 🙏 જય શ્રી કૃષ્ણ</p>
                <div class="alert error">
                    <strong>Do this now:</strong> delete the <code>/install</code> folder from your server.
                    The installer is already locked by <code>config/install.lock</code>, but removing it is cleaner.
                </div>
            <?php else: ?>
                <p class="lead">
                    Everything is configured, but the lock file could not be written — so the site will keep
                    sending visitors back to this installer. Fix the permission shown above, then
                    <a href="?step=8">reload this page</a>.
                </p>
            <?php endif; ?>
            <ul class="next">
                <li><a href="<?= htmlspecialchars(App::i()->url('/admin/login'), ENT_QUOTES, 'UTF-8') ?>">Open the admin panel →</a></li>
                <li><a href="<?= htmlspecialchars(App::i()->url('/'), ENT_QUOTES, 'UTF-8') ?>">Open the website →</a></li>
                <li><a href="<?= htmlspecialchars(App::i()->url('/register'), ENT_QUOTES, 'UTF-8') ?>">Create your first user account →</a></li>
            </ul>
            <div class="callout">
                <strong>Remaining setup inside the admin panel</strong>
                <ol>
                    <li>Firebase: paste the FCM service-account JSON under <em>Admin → Settings → Push</em> so phones can ring.</li>
                    <li>Google OAuth (optional): client id and secret under <em>Admin → Settings</em>.</li>
                    <li>GitHub token under <em>Admin → Updates</em> for one-click updates.</li>
                </ol>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php
render_shell('Install — Krishna Reminder', (string) ob_get_clean());

/* ================================================================ Helpers */

function requirement_checks(string $root): array
{
    $checks = [];

    $checks[] = [
        'label'    => 'PHP 8.1 or newer',
        'value'    => PHP_VERSION,
        'ok'       => version_compare(PHP_VERSION, '8.1.0', '>='),
        'required' => true,
    ];

    foreach (['pdo_mysql', 'curl', 'mbstring', 'json', 'openssl', 'fileinfo'] as $extension) {
        $checks[] = [
            'label'    => 'Extension: ' . $extension,
            'value'    => extension_loaded($extension) ? 'loaded' : 'missing',
            'ok'       => extension_loaded($extension),
            'required' => true,
        ];
    }

    foreach (['zip', 'gd'] as $extension) {
        $checks[] = [
            'label'    => 'Extension: ' . $extension . ' (backups / images)',
            'value'    => extension_loaded($extension) ? 'loaded' : 'missing',
            'ok'       => extension_loaded($extension),
            'required' => false,
        ];
    }

    foreach (['/config', '/storage', '/storage/logs', '/storage/cache', '/storage/temp', '/uploads'] as $path) {
        $full = $root . $path;

        if (!is_dir($full)) {
            @mkdir($full, 0775, true);
        }

        $checks[] = [
            'label'    => 'Writable: ' . $path,
            'value'    => is_writable($full) ? 'writable' : 'not writable',
            'ok'       => is_writable($full),
            'required' => true,
        ];
    }

    $checks[] = [
        'label'    => 'Outbound HTTPS (api.github.com)',
        'value'    => function_exists('curl_init') ? 'curl available' : 'curl missing',
        'ok'       => function_exists('curl_init'),
        'required' => true,
    ];

    $checks[] = [
        'label'    => 'mod_rewrite / pretty URLs',
        'value'    => function_exists('apache_get_modules')
            ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'enabled' : 'not detected')
            : 'cannot detect (fine on nginx/LiteSpeed)',
        'ok'       => true,
        'required' => false,
    ];

    return $checks;
}

function detect_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('/install', '', dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')), '/');

    return $scheme . '://' . $host . $path;
}

function write_config(string $root, array $values): void
{
    $template = (string) file_get_contents($root . '/config/config.sample.php');

    foreach ($values as $key => $value) {
        $template = str_replace('{{' . $key . '}}', addslashes((string) $value), $template);
    }

    if (@file_put_contents($root . '/config/config.php', $template) === false) {
        throw new RuntimeException('Could not write config/config.php — check folder permissions.');
    }

    @chmod($root . '/config/config.php', 0640);
}

function update_config(string $root, array $changes): void
{
    $file = $root . '/config/config.php';
    $current = require $file;

    foreach ($changes as $section => $values) {
        foreach ($values as $key => $value) {
            $current[$section][$key] = $value;
        }
    }

    $export = "<?php\n\n/** Krishna Reminder configuration — generated by the installer. */\n\nreturn "
        . var_export($current, true) . ";\n";

    @file_put_contents($file, $export);
    @chmod($file, 0640);
}

function run_sql_script(Database $db, string $sql): void
{
    $pdo = $db->pdo();
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
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        } elseif ($char === ';') {
            $trimmed = trim($statement);

            if ($trimmed !== '') {
                $pdo->exec($trimmed);
            }

            $statement = '';
            continue;
        }

        $statement .= $char;
    }

    $trimmed = trim($statement);

    if ($trimmed !== '') {
        $pdo->exec($trimmed);
    }
}

function csrf_field(string $token): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function render_shell(string $title, string $body): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<style>
:root{--blue:#1B3A6B;--gold:#F2B33D;--green:#1E8E5A;--red:#C0392B;--surface:#F7F8FB;--line:#e3e7ef;--text:#1a2233;--muted:#697386}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,-apple-system,"Segoe UI",Roboto,"Noto Sans Gujarati",sans-serif;background:var(--surface);color:var(--text);font-size:16px;line-height:1.6}
.wizard{display:flex;min-height:100vh;flex-wrap:wrap}
aside{background:var(--blue);color:#fff;width:290px;padding:28px 22px;flex-shrink:0}
main{flex:1;padding:36px 32px;max-width:820px}
.brand{display:flex;gap:12px;align-items:center;margin-bottom:28px}
.logo{width:44px;height:44px;border-radius:12px;background:var(--gold);display:grid;place-items:center;font-size:22px}
.brand strong{display:block;font-size:17px}
.brand span{font-size:13px;opacity:.75}
.steps{list-style:none;margin:0;padding:0}
.steps li{display:flex;gap:12px;align-items:center;padding:9px 0;opacity:.55;font-size:15px}
.steps li .num{width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.18);display:grid;place-items:center;font-size:13px;flex-shrink:0}
.steps li.current{opacity:1;font-weight:600}
.steps li.current .num{background:var(--gold);color:var(--blue)}
.steps li.done{opacity:.9}
.steps li.done .num{background:var(--green)}
.foot{margin-top:30px;font-size:12.5px;opacity:.6}
h1{margin:0 0 8px;font-size:27px;letter-spacing:-.02em}
h3{margin:22px 0 8px;font-size:16px}
.lead{color:var(--muted);margin:0 0 22px}
.form label{display:block;margin-bottom:15px;font-size:14px;font-weight:600}
.form input,.form select{display:block;width:100%;margin-top:6px;padding:11px 13px;border:1px solid var(--line);border-radius:10px;font-size:15px;font-family:inherit;background:#fff}
.form input:focus,.form select:focus{outline:2px solid var(--blue);border-color:var(--blue)}
.row{display:flex;gap:14px;flex-wrap:wrap}
.row label{flex:1;min-width:150px}
.hint{font-weight:400;color:var(--muted);font-size:12.5px}
.btn{display:inline-block;background:var(--blue);color:#fff;border:0;border-radius:12px;padding:13px 22px;font-size:15px;font-weight:600;cursor:pointer;margin-top:8px;margin-right:8px;text-decoration:none;font-family:inherit;min-height:44px}
.btn:hover{background:#16305a}
.btn.ghost{background:transparent;color:var(--muted);border:1px solid var(--line)}
.alert{padding:13px 16px;border-radius:12px;margin-bottom:16px;font-size:14.5px}
.alert.error{background:#fdecea;color:#8c231a;border:1px solid #f5c6c2}
.alert.ok{background:#e8f6ef;color:#12603c;border:1px solid #b6e2cd}
.alert.warn{background:#fdf6e3;color:#7a5a10;border:1px solid #f0dca4}
.checks{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(20,30,60,.08);margin-bottom:20px}
.checks td{padding:11px 15px;border-bottom:1px solid var(--line);font-size:14.5px}
.checks tr:last-child td{border-bottom:0}
.checks .value{color:var(--muted)}
.checks .badge{text-align:right;font-size:12px;font-weight:700;letter-spacing:.04em}
.checks tr.ok .badge{color:var(--green)}
.checks tr.bad .badge{color:var(--red)}
.checks tr.bad td{background:#fdf3f2}
.checks tr.warn .badge{color:#b7791f}
.code{background:#0f172a;color:#e2e8f0;padding:16px;border-radius:12px;font-size:12.5px;overflow-x:auto;line-height:1.7}
.callout{background:#fff;border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:12px;padding:15px 17px;margin-top:20px;font-size:14.5px}
.callout code{word-break:break-all;font-size:12.5px;background:var(--surface);padding:2px 5px;border-radius:5px}
.card{background:#fff;border-radius:16px;padding:28px;max-width:620px;margin:60px auto;box-shadow:0 2px 12px rgba(20,30,60,.1)}
.next{list-style:none;padding:0;font-size:16px}
.next li{padding:9px 0;border-bottom:1px solid var(--line)}
.next a{color:var(--blue);font-weight:600;text-decoration:none}
.good{color:var(--green);font-weight:600}
.warn-text{color:#b7791f;font-weight:600}
.meter{display:block;height:6px;background:var(--line);border-radius:3px;margin-top:7px;overflow:hidden}
.meter i{display:block;height:100%;width:0;background:var(--red);transition:.25s}
@media(max-width:820px){aside{width:100%}main{padding:24px 18px}.steps{display:flex;flex-wrap:wrap;gap:6px}.steps li{padding:4px 0;font-size:13px}}
</style>
</head>
<body>
<?= $body ?>
<script>
(function(){
  var pw=document.getElementById('pw');
  if(!pw)return;
  var bar=document.getElementById('meterbar'),label=document.getElementById('meterlabel');
  pw.addEventListener('input',function(){
    var v=pw.value,score=0;
    if(v.length>=8)score++;
    if(/[a-z]/.test(v)&&/[A-Z]/.test(v))score++;
    if(/\d/.test(v))score++;
    if(/[^\w]/.test(v))score++;
    if(v.length>=14)score++;
    var pct=[0,25,45,70,88,100][score];
    var col=score<2?'#C0392B':score<4?'#F2B33D':'#1E8E5A';
    bar.style.width=pct+'%';bar.style.background=col;
    label.textContent=score<2?'Too weak':score<4?'Decent — add a symbol or more length':'Strong password';
  });
})();
</script>
</body>
</html><?php
}
