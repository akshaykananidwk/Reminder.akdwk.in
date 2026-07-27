<?php

/**
 * cron/subscriptions.php — daily at 09:00.
 * Sends 7/3/1-day expiry warnings and downgrades plans past the grace period.
 *
 *   0 9 * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/subscriptions.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\WhatsAppService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('subscriptions', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$db = App::i()->db();
$settings = App::i()->settings();
$status = 'ok';
$warned = 0;
$expired = 0;
$message = '';

try {
    /* ---- Expiry warnings at 7, 3 and 1 day ---- */
    foreach ([7, 3, 1] as $days) {
        $from = date('Y-m-d 00:00:00', strtotime("+$days days"));
        $to = date('Y-m-d 23:59:59', strtotime("+$days days"));

        $users = $db->all(
            'SELECT u.*, p.name AS plan_name
               FROM users u
               LEFT JOIN plans p ON p.id = u.plan_id
              WHERE u.is_active = 1 AND u.deleted_at IS NULL
                AND u.plan_expires_at BETWEEN ? AND ?',
            [$from, $to]
        );

        foreach ($users as $user) {
            WhatsAppService::queueTemplate('subscription_expiring', $user, [
                'name' => (string) $user['name'],
                'plan' => (string) ($user['plan_name'] ?? ''),
                'days' => $days,
                'url'  => App::i()->url('/client/billing'),
            ], 6);

            $warned++;
        }
    }

    /* ---- Expire past the grace period ---- */
    $graceDays = max(0, $settings->int('grace_days', 3));
    $cutoff = date('Y-m-d H:i:s', time() - ($graceDays * 86400));

    $defaultPlan = $db->one('SELECT id FROM plans WHERE is_default = 1 AND is_active = 1 LIMIT 1');

    $lapsed = $db->all(
        'SELECT u.*, p.name AS plan_name
           FROM users u
           LEFT JOIN plans p ON p.id = u.plan_id
          WHERE u.is_active = 1 AND u.deleted_at IS NULL
            AND u.plan_expires_at IS NOT NULL
            AND u.plan_expires_at < ?
            AND (u.plan_id IS NULL OR u.plan_id <> ?)',
        [$cutoff, (int) ($defaultPlan['id'] ?? 0)]
    );

    foreach ($lapsed as $user) {
        WhatsAppService::queueTemplate('subscription_expired', $user, [
            'name' => (string) $user['name'],
            'plan' => (string) ($user['plan_name'] ?? ''),
            'url'  => App::i()->url('/client/billing'),
        ], 5);

        if ($defaultPlan !== null) {
            $db->update('users', ['plan_id' => (int) $defaultPlan['id']], 'id = :id', ['id' => (int) $user['id']]);
        }

        $db->query(
            'UPDATE subscriptions SET status = "expired" WHERE user_id = ? AND status = "active" AND ends_at < ?',
            [(int) $user['id'], now_utc()]
        );

        $expired++;
    }

    $message = sprintf('warned=%d expired=%d', $warned, $expired);
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'subscriptions');
}

CronService::finish($warned + $expired, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[subscriptions] ' . $message . PHP_EOL;
}
