<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;

/**
 * The transparency screen: every WhatsApp message we received, what the AI made
 * of it, what we replied — and a button to try again.
 */
class InboxController extends Controller
{
    public function index(): void
    {
        $user = $this->requireUser();
        $page = $this->pagination(30);
        $db = App::i()->db();

        $messages = $db->all(
            'SELECT * FROM wa_inbound_raw WHERE user_id = ? ORDER BY received_at DESC LIMIT '
            . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset'],
            [(int) $user['id']]
        );

        $total = (int) $db->value('SELECT COUNT(*) FROM wa_inbound_raw WHERE user_id = ?', [(int) $user['id']], 0);

        $outbound = $db->all(
            'SELECT * FROM wa_outbound_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
            [(int) $user['id']]
        );

        $this->view('client/inbox', [
            'title'     => __('nav.inbox'),
            'pageTitle' => __('nav.inbox'),
            'messages'  => $messages,
            'outbound'  => $outbound,
            'total'     => $total,
            'page'      => $page,
            'tz'        => (string) $user['timezone'],
        ], 'layouts/client');
    }

    /** Put the message back on the AI queue (it is re-parsed within a minute). */
    public function reprocess(string $id): void
    {
        $user = $this->requireUser();
        $db = App::i()->db();

        $message = $db->one(
            'SELECT * FROM wa_inbound_raw WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user['id']]
        );

        if ($message === null) {
            Response::notFound();
        }

        $db->insert('ai_queue', [
            'user_id'    => (int) $user['id'],
            'inbound_id' => (int) $message['id'],
            'source'     => 'whatsapp',
            'text'       => (string) $message['body'],
            'media_url'  => $message['media_url'],
            'status'     => 'pending',
            'created_at' => now_utc(),
        ]);

        $db->update('wa_inbound_raw', ['processed' => 0], 'id = :id', ['id' => (int) $message['id']]);

        Session::flash('success', __('inbox.requeued'));
        Response::back(url('/client/inbox'));
    }
}
