<?php

namespace App\Jobs;

/**
 * One scheduled task.
 *
 * A job knows three things: what it is called, when it wants to run, and how to
 * do its work. It knows nothing about locking, logging, retries, history or
 * the admin panel — the Scheduler owns all of that, which is what makes adding
 * a new background task a matter of writing `handle()` and adding one line to
 * the registry rather than writing another cron script and another crontab
 * entry.
 *
 * `handle()` returns how many things it did and a one-line summary for the
 * admin panel. It reports failure by throwing: a job that swallows its own
 * exceptions is a job that reports success forever while doing nothing, which
 * is worse than one that fails loudly.
 */
abstract class Job
{
    /** Stable identifier. Used as the primary key of the schedule row. */
    abstract public static function key(): string;

    /** Human-readable name for the admin panel. */
    abstract public static function label(): string;

    /** One sentence: what does this do, and why does it matter if it stops? */
    abstract public static function description(): string;

    /**
     * The work.
     *
     * @return array{processed: int, message: string}
     */
    abstract public function handle(): array;

    /* --------------------------------------------------------- Scheduling */

    /** 'every' | 'daily' | 'weekly' */
    public static function scheduleKind(): string
    {
        return 'every';
    }

    public static function intervalSeconds(): int
    {
        return 60;
    }

    /** Local time for a daily or weekly job, HH:MM. */
    public static function runAt(): ?string
    {
        return null;
    }

    /** 0 = Sunday … 6 = Saturday, for a weekly job. */
    public static function weekday(): ?int
    {
        return null;
    }

    /* ---------------------------------------------------------- Behaviour */

    public static function group(): string
    {
        return 'general';
    }

    /**
     * Lower runs first. Delivery beats bookkeeping: a reminder that arrives
     * three minutes late because a report was being generated is a broken
     * product, and the report does not care.
     */
    public static function priority(): int
    {
        return 50;
    }

    /**
     * A heavy job is started only after every light job in the same pass has
     * finished, and only if the master still has time. Backups and cleanups
     * take minutes; reminder dispatch takes milliseconds.
     */
    public static function isHeavy(): bool
    {
        return false;
    }

    /** How many times to try before giving up until the next scheduled run. */
    public static function maxAttempts(): int
    {
        return 1;
    }

    public static function timeoutSeconds(): int
    {
        return 300;
    }

    /** Registered but off until an operator turns it on. */
    public static function enabledByDefault(): bool
    {
        return true;
    }

    /**
     * A job can decline to run — Google sync with the integration switched
     * off, WhatsApp jobs with no provider connected. Returning a string means
     * "skip, and here is why"; the run is recorded as skipped, not failed, so
     * a disabled integration never looks like a broken cron.
     */
    public function skipReason(): ?string
    {
        return null;
    }
}
