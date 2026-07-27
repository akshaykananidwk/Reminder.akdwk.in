<?php

namespace App\Services;

use App\Core\App;
use App\Core\Request;

/**
 * Append-only audit trail for admin actions and security-relevant user events.
 */
class AuditService
{
    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = [],
        string $actorType = 'admin',
        ?int $actorId = null
    ): void {
        try {
            if ($actorId === null) {
                $actorId = $actorType === 'admin'
                    ? (\App\Core\Auth::admin()['id'] ?? null)
                    : \App\Core\Auth::id();
            }

            App::i()->db()->insert('audit_logs', [
                'actor_type'  => $actorType,
                'actor_id'    => $actorId === null ? null : (int) $actorId,
                'action'      => mb_substr($action, 0, 120),
                'target_type' => $targetType === null ? null : mb_substr($targetType, 0, 60),
                'target_id'   => $targetId,
                'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'ip'          => PHP_SAPI === 'cli' ? null : Request::ip(),
                'created_at'  => now_utc(),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the action being audited.
        }
    }

    public static function loginAttempt(string $identifier, bool $success, string $context = 'user'): void
    {
        try {
            App::i()->db()->insert('login_attempts', [
                'identifier' => mb_substr($identifier, 0, 190),
                'ip'         => Request::ip(),
                'success'    => $success ? 1 : 0,
                'context'    => $context,
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable) {
            // Ignore.
        }
    }

    /**
     * Count recent failed attempts for lockout decisions.
     */
    public static function recentFailures(string $identifier, int $minutes = 15): int
    {
        try {
            return (int) App::i()->db()->value(
                'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > ?',
                [$identifier, date('Y-m-d H:i:s', time() - ($minutes * 60))],
                0
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}
