<?php

namespace App\Services;

use App\Core\App;

/**
 * Secure file uploads: extension + MIME whitelist, size cap, random storage
 * names, and no script execution inside /uploads (enforced by .htaccess).
 */
class UploadService
{
    private const MAX_BYTES = 10485760; // 10 MB

    private const ALLOWED = [
        'jpg'  => ['image/jpeg', 'image'],
        'jpeg' => ['image/jpeg', 'image'],
        'png'  => ['image/png', 'image'],
        'webp' => ['image/webp', 'image'],
        'heic' => ['image/heic', 'image'],
        'pdf'  => ['application/pdf', 'pdf'],
        'mp3'  => ['audio/mpeg', 'audio'],
        'ogg'  => ['audio/ogg', 'audio'],
        'm4a'  => ['audio/mp4', 'audio'],
        'opus' => ['audio/opus', 'audio'],
    ];

    /**
     * @return array{ok: bool, attachment_id: int, path: string, url: string, error: string|null}
     */
    public static function store(array $file, int $userId, string $folder = 'attachments', array $links = []): array
    {
        $fail = static fn (string $message): array => ['ok' => false, 'attachment_id' => 0, 'path' => '', 'url' => '', 'error' => $message];

        if (!isset($file['tmp_name'], $file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $fail('Upload failed.');
        }

        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return $fail('File is larger than 10 MB.');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            return $fail('This file type is not allowed.');
        }

        [$expectedMime, $kind] = self::ALLOWED[$extension];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMime = (string) $finfo->file((string) $file['tmp_name']);

        // HEIC/opus detection varies between servers; accept the family match.
        if ($actualMime !== $expectedMime && explode('/', $actualMime)[0] !== explode('/', $expectedMime)[0]) {
            return $fail('The file content does not match its extension.');
        }

        $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'attachments';
        $dir = App::i()->root() . '/uploads/' . $folder . '/' . date('Y/m');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $fail('Upload directory is not writable.');
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $dir . '/' . $stored;

        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $target)
            : rename((string) $file['tmp_name'], $target);

        if (!$moved) {
            return $fail('Could not save the uploaded file.');
        }

        @chmod($target, 0644);

        $relative = '/uploads/' . $folder . '/' . date('Y/m') . '/' . $stored;

        $attachmentId = App::i()->db()->insert('attachments', [
            'user_id'       => $userId,
            'reminder_id'   => $links['reminder_id'] ?? null,
            'payment_id'    => $links['payment_id'] ?? null,
            'note_id'       => $links['note_id'] ?? null,
            'kind'          => $kind === 'image' ? 'image' : ($kind === 'pdf' ? 'pdf' : ($kind === 'audio' ? 'audio' : 'other')),
            'original_name' => mb_substr((string) $file['name'], 0, 255),
            'stored_name'   => $relative,
            'mime'          => $actualMime,
            'size_bytes'    => (int) ($file['size'] ?? 0),
            'created_at'    => now_utc(),
        ]);

        return [
            'ok'            => true,
            'attachment_id' => $attachmentId,
            'path'          => $target,
            'url'           => App::i()->url($relative),
            'error'         => null,
        ];
    }

    public static function delete(int $attachmentId, int $userId): bool
    {
        $db = App::i()->db();

        $row = $db->one('SELECT * FROM attachments WHERE id = ? AND user_id = ?', [$attachmentId, $userId]);

        if ($row === null) {
            return false;
        }

        $path = App::i()->root() . (string) $row['stored_name'];

        if (is_file($path)) {
            @unlink($path);
        }

        return $db->delete('attachments', 'id = ? AND user_id = ?', [$attachmentId, $userId]) > 0;
    }
}
