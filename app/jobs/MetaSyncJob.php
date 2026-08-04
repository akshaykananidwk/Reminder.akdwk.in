<?php

namespace App\Jobs;

use App\Core\App;
use App\Core\Logger;
use App\Services\MetaMediaService;
use App\Services\MetaWebhookService;
use App\Services\WabaAccountService;
use App\Services\WaTemplateService;

/**
 * The reconciliation pass for the WhatsApp Cloud API.
 *
 * Webhooks do the real-time work; this is the safety net for what webhooks
 * lose. A template approval that arrived during a deploy is simply gone — Meta
 * does not redeliver forever — and a template stuck at PENDING that Meta
 * approved yesterday means every reminder outside the 24-hour window is still
 * failing.
 */
class MetaSyncJob extends Job
{
    public static function key(): string
    {
        return 'meta_sync';
    }

    public static function label(): string
    {
        return 'WhatsApp platform sync';
    }

    public static function description(): string
    {
        return 'Re-syncs templates, phone quality and messaging tiers, and replays webhook events that failed to process.';
    }

    public static function group(): string
    {
        return 'messaging';
    }

    public static function priority(): int
    {
        return 41;
    }

    public static function intervalSeconds(): int
    {
        return 900;
    }

    public static function timeoutSeconds(): int
    {
        return 600;
    }

    public function skipReason(): ?string
    {
        try {
            if (!App::i()->db()->tableExists('waba_accounts')) {
                return 'Meta tables are not installed yet';
            }
        } catch (\Throwable) {
            return 'Database unavailable';
        }

        return WabaAccountService::all(true) === [] ? 'No WhatsApp Business Account is connected' : null;
    }

    public function handle(): array
    {
        $db = App::i()->db();
        $processed = 0;
        $parts = [];

        /* ------------------------------------------------- Per-account sync */

        foreach (WabaAccountService::all(true) as $account) {
            $accountId = (int) $account['id'];

            if (WabaAccountService::token($account) === '') {
                continue;
            }

            $templates = WaTemplateService::syncAll($account);

            if ($templates['ok']) {
                $processed += (int) $templates['synced'];
            } else {
                Logger::warn('Template sync failed', [
                    'account' => $accountId,
                    'error'   => $templates['message'],
                ], 'meta');
            }

            $phones = WabaAccountService::syncPhoneNumbers($accountId);

            if (!$phones['ok']) {
                Logger::warn('Phone sync failed', [
                    'account' => $accountId,
                    'error'   => $phones['message'],
                ], 'meta');
            }

            $parts[] = 'account ' . $accountId . ': ' . $templates['synced'] . ' templates, ' . $phones['count'] . ' numbers';
        }

        /* --------------------------------------- Retry unprocessed webhooks */

        $replayed = 0;

        foreach ($db->all('SELECT * FROM wa_webhook_events WHERE processed = 0 ORDER BY id LIMIT 200') as $event) {
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
            } catch (\Throwable $e) {
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

        /* ------------------------------------------ Housekeeping and alerts */

        $pruned = MetaMediaService::pruneExpired();

        if ($pruned > 0) {
            $parts[] = $pruned . ' expired media id(s) dropped';
        }

        // A number sitting on RED is about to have its throughput cut, and
        // nobody watches the dashboard. Say so where the alerting can see it.
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

        // The API log grows by a row per send. It is a debugging aid, not a
        // record of account; thirty days answers "what happened last month?"
        // without letting a busy install fill the disk.
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $db->delete('wa_api_log', 'created_at < :cutoff', ['cutoff' => $cutoff]);
        $db->delete('wa_webhook_events', 'processed = 1 AND received_at < :cutoff', ['cutoff' => $cutoff]);

        return [
            'processed' => $processed,
            'message'   => $parts === [] ? 'nothing to do' : implode('; ', $parts),
        ];
    }
}
