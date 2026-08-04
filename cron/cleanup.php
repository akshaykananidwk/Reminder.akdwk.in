<?php

/**
 * cron/cleanup.php — Retention & cleanup.
 *
 * Superseded by the centralised scheduler. One crontab line now runs
 * everything:
 *
 *     * * * * * /usr/bin/php /www/wwwroot/reminder.akdwk.in/cron/run.php >/dev/null 2>&1
 *
 * This wrapper is kept so an existing crontab keeps working. It runs the same
 * job through the same scheduler, with the same lock, so nothing is executed
 * twice. Add --force to run it regardless of the schedule.
 */

$jobKey = 'cleanup';

require __DIR__ . '/_legacy.php';
