<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\GeminiService;
use App\Services\ReminderService;

/**
 * Free-form notes captured from WhatsApp, plus the contacts / staff book.
 */
class NoteController extends Controller
{
    public function index(): void
    {
        $user = $this->requireUser();

        $notes = App::i()->db()->all(
            'SELECT n.*, r.short_code
               FROM notes n
               LEFT JOIN reminders r ON r.id = n.converted_reminder_id
              WHERE n.user_id = ? AND n.deleted_at IS NULL
              ORDER BY n.pinned DESC, n.created_at DESC
              LIMIT 200',
            [(int) $user['id']]
        );

        $this->view('client/notes', [
            'title'     => __('nav.notes'),
            'pageTitle' => __('nav.notes'),
            'notes'     => $notes,
            'tz'        => (string) $user['timezone'],
        ], 'layouts/client');
    }

    public function store(): void
    {
        $user = $this->requireUser();
        $body = trim((string) Request::post('body', ''));

        if ($body === '') {
            Session::flash('error', __('validation.required', ['field' => __('notes.body')]));
            Response::back(url('/client/notes'));
        }

        App::i()->db()->insert('notes', [
            'user_id'    => (int) $user['id'],
            'title'      => mb_substr((string) Request::post('title', mb_substr($body, 0, 80)), 0, 255),
            'body'       => mb_substr($body, 0, 5000),
            'source'     => 'web',
            'created_at' => now_utc(),
        ]);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/notes'));
    }

    /**
     * One-click "make this note a reminder": the note text is re-parsed, and
     * if it still has no time the user is sent to the form pre-filled.
     */
    public function convert(string $id): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $db = App::i()->db();

        $note = $db->one('SELECT * FROM notes WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [(int) $id, $userId]);

        if ($note === null) {
            Response::notFound();
        }

        $parsed = GeminiService::parse((string) $note['body'], $user);
        $item = $parsed['data']['items'][0] ?? null;

        if ($item === null || empty($item['due_at'])) {
            Session::flash('warning', __('wa.need_time'));
            Session::flashInput(['title' => (string) ($note['title'] ?: $note['body'])]);
            Response::redirect(url('/client/reminders/create'));
        }

        $reminder = ReminderService::createFromParsed($item, $user, 'web', 'note:' . (int) $note['id']);

        if ($reminder === null) {
            Session::flash('error', __('reminder.quota_reached'));
            Response::back(url('/client/notes'));
        }

        $db->update('notes', ['converted_reminder_id' => (int) $reminder['id']], 'id = :id', ['id' => (int) $note['id']]);

        Session::flash('success', __('notes.converted'));
        Response::redirect(url('/client/reminders/' . (int) $reminder['id']));
    }

    public function destroy(string $id): void
    {
        $user = $this->requireUser();

        App::i()->db()->update('notes', ['deleted_at' => now_utc()], 'id = :id AND user_id = :uid', [
            'id' => (int) $id, 'uid' => (int) $user['id'],
        ]);

        Session::flash('success', __('common.deleted'));
        Response::redirect(url('/client/notes'));
    }

    /* ------------------------------------------------------------ Contacts */

    public function contacts(): void
    {
        $user = $this->requireUser();

        $contacts = App::i()->db()->all(
            'SELECT c.*, u.name AS linked_name
               FROM contacts c
               LEFT JOIN users u ON u.id = c.linked_user_id
              WHERE c.user_id = ? AND c.deleted_at IS NULL
              ORDER BY c.name ASC',
            [(int) $user['id']]
        );

        $this->view('client/contacts', [
            'title'     => __('nav.contacts'),
            'pageTitle' => __('nav.contacts'),
            'contacts'  => $contacts,
        ], 'layouts/client');
    }

    public function storeContact(): void
    {
        $user = $this->requireUser();
        $name = trim((string) Request::post('name', ''));

        if ($name === '') {
            Session::flash('error', __('validation.required', ['field' => __('common.name')]));
            Response::back(url('/client/contacts'));
        }

        $phone = normalize_phone((string) Request::post('phone', ''));

        // If this person is already a user, link them so tasks can be assigned.
        $linked = $phone === '' ? null : App::i()->db()->one(
            'SELECT id FROM users WHERE phone = ? AND deleted_at IS NULL AND id <> ?',
            [$phone, (int) $user['id']]
        );

        App::i()->db()->insert('contacts', [
            'user_id'        => (int) $user['id'],
            'name'           => mb_substr($name, 0, 120),
            'phone'          => $phone ?: null,
            'email'          => Request::post('email') ?: null,
            'role'           => Request::post('role') ?: null,
            'notes'          => Request::post('notes') ?: null,
            'linked_user_id' => $linked === null ? null : (int) $linked['id'],
            'created_at'     => now_utc(),
        ]);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/client/contacts'));
    }

    public function destroyContact(string $id): void
    {
        $user = $this->requireUser();

        App::i()->db()->update('contacts', ['deleted_at' => now_utc()], 'id = :id AND user_id = :uid', [
            'id' => (int) $id, 'uid' => (int) $user['id'],
        ]);

        Session::flash('success', __('common.deleted'));
        Response::redirect(url('/client/contacts'));
    }
}
