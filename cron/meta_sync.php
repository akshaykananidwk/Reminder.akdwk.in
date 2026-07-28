<?php

/**
 * cron/meta_sync.php — every 15 minutes.
 *
 * The reconciliation pass for the WhatsApp Cloud API. Webhooks do the real-time
 * work; this is the safety net for everything webhooks lose, and they do lose
 * things:
 *
 *   • A template approval that arrived while the site was down, or during a
 *     deploy, is simply gone — Meta does not redeliver forever. A template
 *     stuck at PENDING that Meta approved yesterday means every reminder
 *     outside the 24-hour window is still failing.
 *   • Quality ratings and messaging tiers change without a webhook in some
 *     account states, and they are the earliest warning that deliveries are
 *     about to be throttled.
 *   • Webhook events that threw during processing are marked unprocessed and
 *     would otherwise sit there forever.
 *   • Cached Meta media ids expire after about thirty days; a stale one makes
 *     a send fail for no visible reason.
 *
 *   0,15,30,45 * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/meta_sync.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;
use App\Services\CronService;
use App\Services\MetaMediaService;
use App\Services\MetaWebhookService;
use App\Services\WabaAccountService;
use App\Services\WaTemplateService;

if (!App::i()->isInstalled()) {
    fwrite(STDERR, "Krishna Reminder is not installed.\n");
    exit(1);
}

if (!CronService::begin('meta_sync', PHP_SAPI === 'cli' ? 'cli' : 'web')) {
    return; // Previous run still in progress.
}

$db = App::i()->db();
$status = 'ok';
$processed = 0;
$parts = [];

try {
    if (!$db->tableExists('waba_accounts')) {
        CronService::finish(0, 'ok', 'Meta tables not installed yet');

        if (PHP_SAPI === 'cli') {
            echo "[meta_sync] Meta tables not installed yet" . PHP_EOL;
        }

        return;
    }

    /* ------------------------------------------------- 1. Per-account sync */

    $accounts = WabaAccountService::all(true);

    foreach ($accounts as $account) {
        $accountId = (int) $account['id'];

        if (WabaAccountService::token($account) === '') {
            continue;
        }

        // Templates: statuses, rejections, quality scores and new templates
        // created in Meta's own dashboard.
        $templates = WaTemplateService::syncAll($account);

        if ($templates['ok']) {
            $processed += (int) $templates['synced'];
        } else {
            Logger::warn('Template sync failed', [
                'account' => $accountId,
                'error'   => $templates['message'],
            ], 'meta');
        }

        // Phone numbers: quality rating and messaging tier.
        $phones = WabaAccountService::syncPhoneNumbers($accountId);

        if (!$phones['ok']) {
            Logger::warn('Phone sync failed', [
                'account' => $accountId,
                'error'   => $phones['message'],
            ], 'meta');
        }

        $parts[] = 'account ' . $accountId . ': ' . $templates['synced'] . ' templates, ' . $phones['count'] . ' numbers';
    }

    /* --------------------------------------- 2. Retry unprocessed webhooks */

    $stuck = $db->all(
        'SELECT * FROM wa_webhook_events WHERE processed = 0 ORDER BY id LIMIT 200'
    );

    $replayed = 0;

    foreach ($stuck as $event) {
        try {
            MetaWebhookService::process(
                (string) $event['field'],
                json_field($event['payload']),
                (string) ($event['waba_id'] ?? '')
            );

            $db->update('wa_webhook_events', [
                'processed'    => 1,
                'error'        => null,
                'processed_at' => now_utc(),
            ], 'id = :id', ['id' => (int) $event['id']]);

            $replayed++;
        } catch (Throwable $e) {
            $db->update('wa_webhook_events', [
                'error'        => mb_substr($e->getMessage(), 0, 500),
                'processed_at' => now_utc(),
            ], 'id = :id', ['id' => (int) $event['id']]);
        }
    }

    if ($replayed > 0) {
        $parts[] = $replayed . ' webhook event(s) replayed';
        $processed += $replayed;
    }

    /* ------------------------------------------- 3. Housekeeping and alerts */

    $pruned = MetaMediaService::pruneExpired();

    if ($pruned > 0) {
        $parts[] = $pruned . ' expired media id(s) dropped';
    }

    // A number sitting on RED is about to have its throughput cut, and nobody
    // watches the dashboard. Say so in the log where the alert cron can see it.
    $red = $db->all(
        "SELECT display_number, phone_number_id FROM waba_phone_numbers
          WHERE quality_rating = 'RED' AND is_active = 1"
    );

    foreach ($red as $number) {
        Logger::warn('WhatsApp number quality is RED', [
            'number' => (string) ($number['display_number'] ?: $number['phone_number_id']),
        ], 'meta');
    }

    if ($red !== []) {
        $parts[] = count($red) . ' number(s) at RED quality';
    }

    // Trim the API log: it grows by one row per send and is a debugging aid,
    // not a record of account. Thirty days is long enough to answer "what
    // happened last month?" without letting a busy install fill the disk.
    $db->delete('wa_api_log', 'created_at < :cutoff', ['cutoff' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    $db->delete(
        'wa_webhook_events',
        'processed = 1 AND received_at < :cutoff',
        ['cutoff' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
    );

    $message = $parts === [] ? 'nothing to do' : implode('; ', $parts);
} catch (Throwable $e) {
    $status = 'error';
    $message = $e->getMessage();
    Logger::exception($e, 'meta');
}

CronService::finish($processed, $status, $message);

if (PHP_SAPI === 'cli') {
    echo '[meta_sync] ' . $message . PHP_EOL;
}
