<?php

/**
 * Health endpoint used by the updater, uptime monitors and the admin panel.
 *   GET /api/health.php            -> public, minimal
 *   GET /api/health.php?full=1&token=CRON_TOKEN -> detailed
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Request;
use App\Services\BackupService;
use App\Services\CronService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$app = App::i();

$response = [
    'status'  => 'ok',
    'app'     => 'Krishna Reminder',
    'version' => (string) $app->config('app.version', '1.0.0'),
    'time'    => now_utc(),
];

if (!$app->isInstalled()) {
    http_response_code(503);
    echo json_encode(array_merge($response, ['status' => 'not_installed']));
    exit;
}

$checks = [];

try {
    $app->db()->value('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    $checks['database'] = 'error';
    $response['status'] = 'degraded';
}

$full = Request::get('full') === '1'
    && hash_equals((string) $app->config('security.cron_token', ''), (string) (Request::get('token', '') ?? ''));

if ($full) {
    $stale = CronService::staleJobs();

    $checks['cron'] = $stale === [] ? 'ok' : 'stale';
    $checks['stale_jobs'] = array_map(static fn ($job) => $job['job'], $stale);
    // The single most useful number here: how long since the one server cron
    // last woke the scheduler. Everything else is downstream of it.
    $checks['scheduler_last_tick'] = \App\Services\Scheduler::lastTick();
    $checks['scheduler_minutes_ago'] = \App\Services\Scheduler::minutesSinceTick();
    $checks['whatsapp_gateway'] = \App\Services\WhatsAppService::gatewayHealthy() ? 'ok' : 'failing';
    $checks['fcm'] = \App\Services\FcmService::isConfigured() ? 'configured' : 'not_configured';
    $checks['webroot_exposure'] = BackupService::auditWebroot();
    $checks['queue_pending'] = (int) $app->db()->value('SELECT COUNT(*) FROM wa_outbound_queue WHERE status = "queued"', [], 0);
    $checks['ai_pending'] = (int) $app->db()->value('SELECT COUNT(*) FROM ai_queue WHERE status = "pending"', [], 0);
    $checks['php_version'] = PHP_VERSION;
    $checks['disk_free_mb'] = (int) round(((float) (@disk_free_space($app->root()) ?: 0)) / 1048576);

    if ($stale !== [] || $checks['webroot_exposure'] !== []) {
        $response['status'] = 'degraded';
    }
}

$response['checks'] = $checks;

if ($response['status'] !== 'ok') {
    http_response_code(200); // Still reachable — the body carries the detail.
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
