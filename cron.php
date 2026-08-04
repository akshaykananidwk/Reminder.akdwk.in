<?php

/**
 * Web-cron fallback, for hosts that cannot run PHP-CLI cron.
 *
 * Point any uptime monitor at this URL once a minute and it becomes the master
 * cron:
 *
 *     https://reminder.akdwk.in/cron.php?token=SECRET
 *
 * Everything the CLI master does, this does — the same registry, the same
 * database locks, the same schedule. A single job can still be targeted with
 * ?job=<key>, which forces it to run regardless of when it is next due.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\Scheduler;

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

@set_time_limit(300);
ignore_user_abort(true);

$job = trim((string) (Request::get('job', '') ?? ''));

/* --------------------------------------------------------- One named job */

// 'all' used to mean "the three one-minute jobs". It now means the same thing
// the master means — run whatever is due — so an old monitor URL keeps working
// and quietly gets better.
if ($job !== '' && $job !== 'all') {
    if (Scheduler::definition($job) === null) {
        http_response_code(400);
        echo "Unknown job: $job\n\nRegistered jobs:\n";

        foreach (Scheduler::definitions() as $key => $class) {
            echo '  ' . str_pad($key, 16) . $class::label() . "\n";
        }

        exit;
    }

    $result = Scheduler::runJob($job, 'web', true);

    printf("[%s] %s — %s (%d ms)\n", $result['key'], $result['status'], $result['message'], $result['duration_ms']);
    echo "done\n";
    exit;
}

/* ------------------------------------------------------------- Full pass */

try {
    // A web request has less headroom than a CLI process, so the pass is given
    // a smaller budget: better to start the quick jobs and leave a heavy one
    // for the next minute than to be killed halfway through it.
    $result = Scheduler::tick('web', 25);
} catch (Throwable $e) {
    Logger::exception($e, 'cron');

    http_response_code(500);
    echo 'error: ' . $e->getMessage() . "\n";
    exit;
}

printf(
    "ran=%d failed=%d skipped=%d in %.2fs\n",
    $result['ran'],
    $result['failed'],
    $result['skipped'],
    $result['seconds']
);

foreach ($result['jobs'] as $entry) {
    if ($entry['status'] !== 'skipped') {
        printf("  [%s] %s — %s\n", $entry['key'], $entry['status'], mb_substr((string) $entry['message'], 0, 120));
    }
}

echo "done\n";
