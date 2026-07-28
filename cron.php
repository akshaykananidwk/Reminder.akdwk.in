<?php

/**
 * Web-cron fallback: https://reminder.akdwk.in/cron.php?job=dispatcher&token=SECRET
 *
 * Use this only when the host cannot run PHP-CLI cron. Every job is still
 * flock-protected, so overlapping web calls are safe.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$app = App::i();

if (!$app->isInstalled()) {
    http_response_code(503);
    echo "Not installed.\n";
    exit;
}

$expected = (string) $app->config('security.cron_token', '');
$given = (string) (Request::get('token', '') ?? '');

if ($expected === '' || !hash_equals($expected, $given)) {
    // Slow down anyone probing for the token.
    RateLimiter::attempt('cron_web_' . Request::ip(), 10, 300);
    Logger::warn('Web cron rejected', ['ip' => Request::ip()], 'cron');

    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$allowed = [
    'dispatcher', 'ai_queue', 'wa_queue', 'recurrence', 'google_sync', 'meta_sync',
    'morning_brief', 'daily_summary', 'subscriptions', 'backup', 'cleanup',
];

$job = (string) (Request::get('job', '') ?? '');

if ($job === 'all') {
    // A single call that runs the one-minute jobs — handy for hosts that only
    // allow one scheduled URL.
    $jobs = ['dispatcher', 'ai_queue', 'wa_queue'];
} elseif (in_array($job, $allowed, true)) {
    $jobs = [$job];
} else {
    http_response_code(400);
    echo "Unknown job. Allowed: " . implode(', ', $allowed) . ", all\n";
    exit;
}

@set_time_limit(300);
ignore_user_abort(true);

foreach ($jobs as $name) {
    $script = __DIR__ . '/cron/' . $name . '.php';

    if (!is_file($script)) {
        echo "[$name] script missing\n";
        continue;
    }

    echo "[$name] running…\n";

    try {
        // Each script bootstraps defensively and is safe to include here.
        include $script;
    } catch (Throwable $e) {
        Logger::exception($e, 'cron');
        echo "[$name] error: " . $e->getMessage() . "\n";
    }
}

echo "done\n";
