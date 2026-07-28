<?php

namespace App\Services;

use App\Core\App;
use App\Core\Logger;

/**
 * Media in and out of the Cloud API.
 *
 * Meta keeps an uploaded file for about thirty days and hands back an id that
 * can be sent to any number of recipients. Re-uploading the same PDF for every
 * customer wastes bandwidth, burns rate limit and takes seconds per send — so
 * uploads are keyed by SHA-256 of the file contents and the id is reused until
 * it expires.
 *
 * Inbound media is the mirror image: the webhook carries an id, exchanging it
 * for a URL needs the access token, and that URL is valid for minutes. Anything
 * we want to keep has to be downloaded now, not linked to.
 */
class MetaMediaService
{
    /** Meta's own ceiling per type, in bytes. Rejecting early beats a 400. */
    private const LIMITS = [
        'image'    => 5 * 1024 * 1024,
        'audio'    => 16 * 1024 * 1024,
        'video'    => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
        'sticker'  => 500 * 1024,
    ];

    private const TYPES = [
        'image/jpeg' => 'image', 'image/png' => 'image',
        'image/webp' => 'sticker',
        'video/mp4' => 'video', 'video/3gpp' => 'video',
        'audio/aac' => 'audio', 'audio/mp4' => 'audio', 'audio/mpeg' => 'audio',
        'audio/amr' => 'audio', 'audio/ogg' => 'audio',
        'application/pdf' => 'document',
        'application/msword' => 'document',
        'application/vnd.ms-excel' => 'document',
        'application/vnd.ms-powerpoint' => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
        'text/plain' => 'document',
    ];

    /* -------------------------------------------------------------- Upload */

    /**
     * Upload a local file, or return the id of an identical one already there.
     *
     * @return array{ok: bool, media_id: string|null, cached: bool, message: string}
     */
    public static function upload(array $account, string $path, ?string $mimeType = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return self::fail('File not found: ' . basename($path));
        }

        $mime = $mimeType ?? self::detectMime($path);
        $kind = self::TYPES[$mime] ?? null;

        if ($kind === null) {
            return self::fail('WhatsApp does not accept ' . $mime . ' files.');
        }

        $size = (int) filesize($path);

        if ($size > (self::LIMITS[$kind] ?? 0)) {
            return self::fail(sprintf(
                'The file is %s; WhatsApp allows at most %s for %s.',
                self::humanBytes($size),
                self::humanBytes(self::LIMITS[$kind]),
                $kind
            ));
        }

        $sha256 = (string) hash_file('sha256', $path);
        $cached = self::cached((int) $account['id'], $sha256);

        if ($cached !== null) {
            return [
                'ok'       => true,
                'media_id' => (string) $cached['media_id'],
                'cached'   => true,
                'message'  => 'Reused the copy already uploaded to Meta.',
            ];
        }

        $phoneId = WabaAccountService::phoneNumberId($account);

        if ($phoneId === '') {
            return self::fail('No WhatsApp phone number is registered on this account.');
        }

        $result = MetaGraph::post(rawurlencode($phoneId) . '/media', [
            'token'      => WabaAccountService::token($account),
            'account_id' => (int) $account['id'],
            // A repeated upload creates a second id but sends nothing, so it is
            // safe to retry — unlike /messages.
            'idempotent' => true,
            'timeout'    => 120,
            'body'       => [
                'messaging_product' => 'whatsapp',
                'type'              => $mime,
                'file'              => new \CURLFile($path, $mime, basename($path)),
            ],
        ]);

        $mediaId = $result['json']['id'] ?? null;

        if (!$result['ok'] || !is_string($mediaId) || $mediaId === '') {
            return self::fail(MetaGraph::explain($result));
        }

        self::remember((int) $account['id'], $phoneId, $mediaId, $sha256, $mime, $path, $size);

        return ['ok' => true, 'media_id' => $mediaId, 'cached' => false, 'message' => 'Uploaded.'];
    }

    /** A previously uploaded, not-yet-expired copy of the same bytes. */
    private static function cached(int $accountId, string $sha256): ?array
    {
        try {
            return App::i()->db()->one(
                'SELECT * FROM wa_media
                  WHERE waba_account_id = ? AND sha256 = ?
                    AND (expires_at IS NULL OR expires_at > ?)
                  ORDER BY id DESC LIMIT 1',
                [$accountId, $sha256, now_utc()]
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private static function remember(
        int $accountId,
        string $phoneId,
        string $mediaId,
        string $sha256,
        string $mime,
        string $path,
        int $size
    ): void {
        try {
            App::i()->db()->upsert('wa_media', [
                'waba_account_id' => $accountId,
                'phone_number_id' => $phoneId,
                'media_id'        => mb_substr($mediaId, 0, 190),
                'sha256'          => $sha256,
                'mime_type'       => mb_substr($mime, 0, 64),
                'filename'        => mb_substr(basename($path), 0, 255),
                'bytes'           => $size,
                'local_path'      => mb_substr($path, 0, 512),
                // Meta says roughly thirty days; twenty-five keeps us clear of
                // the edge, because a send that fails on an expired id is worse
                // than an upload we did not strictly need.
                'expires_at'      => gmdate('Y-m-d H:i:s', time() + 25 * 86400),
                'created_at'      => now_utc(),
            ], ['media_id', 'mime_type', 'filename', 'bytes', 'local_path', 'expires_at']);
        } catch (\Throwable $e) {
            Logger::warn('Could not cache media id', ['error' => $e->getMessage()], 'meta');
        }
    }

    /* ------------------------------------------------------------ Download */

    /**
     * Resolve a media id to its (short-lived) download URL.
     *
     * @return array{ok: bool, url: string|null, mime_type: string|null, sha256: string|null, bytes: int, message: string}
     */
    public static function resolve(array $account, string $mediaId): array
    {
        if ($mediaId === '') {
            return ['ok' => false, 'url' => null, 'mime_type' => null, 'sha256' => null, 'bytes' => 0, 'message' => 'No media id.'];
        }

        $result = MetaGraph::get(rawurlencode($mediaId), [
            'token'      => WabaAccountService::token($account),
            'account_id' => (int) $account['id'],
        ]);

        $url = $result['json']['url'] ?? null;

        if (!$result['ok'] || !is_string($url) || $url === '') {
            return [
                'ok' => false, 'url' => null, 'mime_type' => null, 'sha256' => null, 'bytes' => 0,
                'message' => MetaGraph::explain($result),
            ];
        }

        return [
            'ok'        => true,
            'url'       => $url,
            'mime_type' => isset($result['json']['mime_type']) ? (string) $result['json']['mime_type'] : null,
            'sha256'    => isset($result['json']['sha256']) ? (string) $result['json']['sha256'] : null,
            'bytes'     => (int) ($result['json']['file_size'] ?? 0),
            'message'   => 'ok',
        ];
    }

    /**
     * Download inbound media to disk.
     *
     * The lookup URL needs the access token as a bearer header — a plain fetch
     * of it returns 401, which is the single most common reason people conclude
     * that inbound images "do not work".
     *
     * @return array{ok: bool, path: string|null, mime_type: string|null, message: string}
     */
    public static function download(array $account, string $mediaId, ?string $targetDir = null): array
    {
        $resolved = self::resolve($account, $mediaId);

        if (!$resolved['ok']) {
            return ['ok' => false, 'path' => null, 'mime_type' => null, 'message' => $resolved['message']];
        }

        // storage/ is denied by .htaccess: an inbound photo is a customer's
        // private message, not a public asset, so it never lands in uploads/.
        $dir = $targetDir ?? (App::i()->root() . '/storage/media/whatsapp');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'path' => null, 'mime_type' => null, 'message' => 'Could not create ' . $dir];
        }

        $extension = self::extensionFor((string) ($resolved['mime_type'] ?? ''));
        $path = rtrim($dir, '/') . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $mediaId) . $extension;

        $result = HttpClient::get((string) $resolved['url'], [
            'headers' => ['Authorization' => 'Bearer ' . WabaAccountService::token($account)],
            'timeout' => 120,
            'save_to' => $path,
        ]);

        if (!$result['ok'] || !is_file($path) || filesize($path) === 0) {
            @unlink($path);

            return [
                'ok' => false, 'path' => null, 'mime_type' => $resolved['mime_type'],
                'message' => 'Download failed (HTTP ' . $result['status'] . ').',
            ];
        }

        return ['ok' => true, 'path' => $path, 'mime_type' => $resolved['mime_type'], 'message' => 'Downloaded.'];
    }

    /** Remove a media id from Meta. Used by the media manager's delete button. */
    public static function delete(array $account, string $mediaId): bool
    {
        $result = MetaGraph::delete(rawurlencode($mediaId), [
            'token'      => WabaAccountService::token($account),
            'account_id' => (int) $account['id'],
        ]);

        if ($result['ok']) {
            try {
                App::i()->db()->delete('wa_media', 'media_id = :mid', ['mid' => $mediaId]);
            } catch (\Throwable) {
            }
        }

        return (bool) $result['ok'];
    }

    /** Drop cache rows whose Meta ids have expired, so they are re-uploaded. */
    public static function pruneExpired(): int
    {
        try {
            return App::i()->db()->delete('wa_media', 'expires_at IS NOT NULL AND expires_at < :now', ['now' => now_utc()]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /* --------------------------------------------------------------- Utils */

    public static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'mp4'  => 'video/mp4',
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'pdf'  => 'application/pdf',
            'txt'  => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'video/mp4'  => '.mp4',
            'audio/ogg'  => '.ogg',
            'audio/mpeg' => '.mp3',
            'audio/mp4', 'audio/aac' => '.m4a',
            'application/pdf' => '.pdf',
            default => '',
        };
    }

    /** The kind of message this file should be sent as. */
    public static function kindFor(string $mime): string
    {
        return self::TYPES[$mime] ?? 'document';
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }

    private static function fail(string $message): array
    {
        return ['ok' => false, 'media_id' => null, 'cached' => false, 'message' => $message];
    }
}
