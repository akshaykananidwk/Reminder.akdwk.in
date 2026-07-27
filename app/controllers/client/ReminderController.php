<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\GeminiService;
use App\Services\InboundProcessor;
use App\Services\PlanService;
use App\Services\RecurrenceService;
use App\Services\ReminderService;
use App\Services\ReportService;
use App\Services\UploadService;

class ReminderController extends Controller
{
    /* ----------------------------------------------------------------- List */

    public function index(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $tz = (string) $user['timezone'];
        $db = App::i()->db();

        $filter = (string) Request::get('filter', 'upcoming');
        $search = trim((string) Request::get('q', ''));
        $type = (string) Request::get('type', '');
        $priority = (string) Request::get('priority', '');
        $page = $this->pagination(25);

        $where = ['o.user_id = ?', 'r.deleted_at IS NULL'];
        $params = [$userId];

        switch ($filter) {
            case 'today':
                [$from, $to] = ReminderService::localDayBounds($tz);
                $where[] = 'o.due_at BETWEEN ? AND ?';
                $params[] = $from;
                $params[] = $to;
                break;

            case 'tomorrow':
                $tomorrow = (new \DateTime('now', new \DateTimeZone($tz)))->modify('+1 day')->format('Y-m-d');
                [$from, $to] = ReminderService::localDayBounds($tz, $tomorrow);
                $where[] = 'o.due_at BETWEEN ? AND ?';
                $params[] = $from;
                $params[] = $to;
                break;

            case 'week':
                [$from] = ReminderService::localDayBounds($tz);
                $where[] = 'o.due_at BETWEEN ? AND ?';
                $params[] = $from;
                $params[] = date('Y-m-d H:i:s', strtotime('+7 days'));
                break;

            case 'overdue':
                $where[] = 'o.due_at < ?';
                $where[] = 'o.status IN ("pending","notified","snoozed","missed")';
                $params[] = now_utc();
                break;

            case 'completed':
                $where[] = 'o.status = "done"';
                break;

            case 'cancelled':
                $where[] = 'o.status = "cancelled"';
                break;

            default: // upcoming
                $where[] = 'o.status IN ("pending","notified","snoozed")';
                $where[] = 'o.due_at >= ?';
                $params[] = date('Y-m-d H:i:s', time() - 3600);
                break;
        }

        if ($search !== '') {
            $where[] = '(r.title LIKE ? OR r.description LIKE ? OR r.short_code = ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = strtoupper($search);
        }

        if (in_array($type, ['task', 'payment', 'call', 'meeting', 'medicine', 'birthday', 'bill', 'note', 'other'], true)) {
            $where[] = 'r.type = ?';
            $params[] = $type;
        }

        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $where[] = 'r.priority = ?';
            $params[] = $priority;
        }

        $whereSql = implode(' AND ', $where);
        $order = in_array($filter, ['completed', 'overdue', 'cancelled'], true) ? 'DESC' : 'ASC';

        $total = (int) $db->value(
            "SELECT COUNT(*) FROM reminder_occurrences o JOIN reminders r ON r.id = o.reminder_id WHERE $whereSql",
            $params,
            0
        );

        $rows = $db->all(
            "SELECT o.*, r.title, r.description, r.short_code, r.type, r.priority, r.amount, r.currency,
                    r.person_name, r.color, r.recurrence, r.call_reminder, r.category_id
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE $whereSql
              ORDER BY o.due_at $order
              LIMIT " . (int) $page['perPage'] . ' OFFSET ' . (int) $page['offset'],
            $params
        );

        $this->view('client/reminders', [
            'title'      => __('nav.reminders'),
            'pageTitle'  => __('nav.reminders'),
            'rows'       => $rows,
            'filter'     => $filter,
            'search'     => $search,
            'type'       => $type,
            'priority'   => $priority,
            'total'      => $total,
            'page'       => $page,
            'tz'         => $tz,
        ], 'layouts/client');
    }

    public function trash(): void
    {
        $user = $this->requireUser();

        $rows = App::i()->db()->all(
            'SELECT * FROM reminders WHERE user_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100',
            [(int) $user['id']]
        );

        $this->view('client/trash', [
            'title'     => __('nav.trash'),
            'pageTitle' => __('nav.trash'),
            'rows'      => $rows,
        ], 'layouts/client');
    }

    /* -------------------------------------------------------------- Details */

    public function show(string $id): void
    {
        $user = $this->requireUser();
        $db = App::i()->db();

        $reminder = $db->one('SELECT * FROM reminders WHERE id = ? AND user_id = ?', [(int) $id, (int) $user['id']]);

        if ($reminder === null) {
            Response::notFound();
        }

        $occurrences = $db->all('SELECT * FROM reminder_occurrences WHERE reminder_id = ? ORDER BY due_at DESC LIMIT 60', [(int) $id]);

        $timeline = $db->all(
            'SELECT ur.*, o.due_at
               FROM user_responses ur
               JOIN reminder_occurrences o ON o.id = ur.occurrence_id
              WHERE o.reminder_id = ?
              ORDER BY ur.created_at DESC LIMIT 60',
            [(int) $id]
        );

        $deliveries = $db->all(
            'SELECT d.*, o.due_at
               FROM deliveries d
               JOIN reminder_occurrences o ON o.id = d.occurrence_id
              WHERE o.reminder_id = ?
              ORDER BY d.created_at DESC LIMIT 40',
            [(int) $id]
        );

        $this->view('client/reminder_show', [
            'title'       => (string) $reminder['title'],
            'pageTitle'   => str_limit((string) $reminder['title'], 40),
            'reminder'    => $reminder,
            'occurrences' => $occurrences,
            'timeline'    => $timeline,
            'deliveries'  => $deliveries,
            'subtasks'    => $db->all('SELECT * FROM subtasks WHERE reminder_id = ? ORDER BY sort_order', [(int) $id]),
            'attachments' => $db->all('SELECT * FROM attachments WHERE reminder_id = ?', [(int) $id]),
            'tz'          => (string) $user['timezone'],
        ], 'layouts/client');
    }

    /* ------------------------------------------------------- Create / edit */

    public function create(): void
    {
        $user = $this->requireUser();

        $this->view('client/reminder_form', [
            'title'      => __('common.add') . ' · ' . __('nav.reminders'),
            'pageTitle'  => __('common.add'),
            'reminder'   => null,
            'categories' => $this->categories((int) $user['id']),
            'contacts'   => $this->contacts((int) $user['id']),
            'tz'         => (string) $user['timezone'],
        ], 'layouts/client');
    }

    public function edit(string $id): void
    {
        $user = $this->requireUser();

        $reminder = App::i()->db()->one(
            'SELECT * FROM reminders WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [(int) $id, (int) $user['id']]
        );

        if ($reminder === null) {
            Response::notFound();
        }

        $this->view('client/reminder_form', [
            'title'      => __('common.edit'),
            'pageTitle'  => __('common.edit'),
            'reminder'   => $reminder,
            'categories' => $this->categories((int) $user['id']),
            'contacts'   => $this->contacts((int) $user['id']),
            'tz'         => (string) $user['timezone'],
        ], 'layouts/client');
    }

    public function store(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        if (!PlanService::withinReminderQuota($userId)) {
            Session::flash('error', __('reminder.quota_reached'));
            Response::redirect(url('/client/billing'));
        }

        $data = $this->formData($user);

        if ($data === null) {
            Session::flash('error', __('validation.required', ['field' => __('reminder.title')]));
            Response::back(url('/client/reminders/create'));
        }

        $reminder = ReminderService::create($userId, $data);

        if ($reminder === null) {
            Session::flash('error', __('common.error'));
            Response::back(url('/client/reminders/create'));
        }

        $this->handleAttachment($userId, (int) $reminder['id']);

        Session::flash('success', __('reminder.created'));
        Response::redirect(url('/client/reminders/' . (int) $reminder['id']));
    }

    public function update(string $id): void
    {
        $user = $this->requireUser();
        $data = $this->formData($user);

        if ($data === null) {
            Session::flash('error', __('validation.required', ['field' => __('reminder.title')]));
            Response::back();
        }

        ReminderService::update((int) $id, (int) $user['id'], $data);
        $this->handleAttachment((int) $user['id'], (int) $id);

        Session::flash('success', __('reminder.updated'));
        Response::redirect(url('/client/reminders/' . (int) $id));
    }

    public function destroy(string $id): void
    {
        $user = $this->requireUser();
        ReminderService::softDelete((int) $id, (int) $user['id']);

        Session::flash('success', __('reminder.deleted'));
        Response::redirect(url('/client/reminders'));
    }

    public function restore(string $id): void
    {
        $user = $this->requireUser();

        if (ReminderService::restore((int) $id, (int) $user['id'])) {
            $fresh = App::i()->db()->one('SELECT * FROM reminders WHERE id = ?', [(int) $id]);

            if ($fresh !== null) {
                $fresh['timezone'] = (string) $user['timezone'];
                RecurrenceService::materialise($fresh, 30);
            }
        }

        Session::flash('success', __('reminder.restored'));
        Response::redirect(url('/client/trash'));
    }

    /* ------------------------------------------------------------- Actions */

    public function occurrenceAction(string $id): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $action = (string) Request::post('action', '');

        switch ($action) {
            case 'done':
                $ok = ReminderService::complete((int) $id, $userId, 'web', Request::post('note'));
                $this->actionResponse($ok, __('reminder.completed'));

            case 'snooze':
                $minutes = (int) Request::post('minutes', 0);
                $result = ReminderService::snooze((int) $id, $userId, $minutes > 0 ? $minutes : null, 'web');

                if (!$result['ok']) {
                    $this->actionResponse(false, (string) $result['message']);
                }

                $this->actionResponse(true, __('reminder.snoozed', ['minutes' => $result['minutes']]));

            case 'reschedule':
                $local = (string) Request::post('due_at', '');

                if (strtotime($local) === false) {
                    $this->actionResponse(false, __('validation.date', ['field' => __('common.date')]));
                }

                ReminderService::reschedule((int) $id, $userId, to_utc($local, (string) $user['timezone']), 'web');
                $this->actionResponse(true, __('reminder.rescheduled'));

            case 'cancel':
                ReminderService::cancelOccurrence((int) $id, $userId, 'web');
                $this->actionResponse(true, __('reminder.cancelled'));

            default:
                $this->actionResponse(false, __('common.error'));
        }
    }

    public function bulk(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $ids = array_map('intval', (array) Request::input('ids', []));
        $action = (string) Request::post('bulk_action', '');
        $count = 0;

        foreach (array_slice($ids, 0, 200) as $occurrenceId) {
            switch ($action) {
                case 'done':
                    $count += ReminderService::complete($occurrenceId, $userId, 'web') ? 1 : 0;
                    break;

                case 'snooze':
                    $count += ReminderService::snooze($occurrenceId, $userId, (int) Request::post('minutes', 30), 'web')['ok'] ? 1 : 0;
                    break;

                case 'cancel':
                    $count += ReminderService::cancelOccurrence($occurrenceId, $userId, 'web') ? 1 : 0;
                    break;

                case 'delete':
                    $row = App::i()->db()->one('SELECT reminder_id FROM reminder_occurrences WHERE id = ? AND user_id = ?', [$occurrenceId, $userId]);

                    if ($row !== null) {
                        $count += ReminderService::softDelete((int) $row['reminder_id'], $userId) ? 1 : 0;
                    }
                    break;
            }
        }

        Session::flash('success', __('reminder.bulk_selected', ['n' => $count]));
        Response::back(url('/client/reminders'));
    }

    /** Natural-language box: "કાલે 10 વાગ્યે…" parsed server-side. */
    public function parse(): void
    {
        $user = $this->requireUser();
        $text = trim((string) Request::post('text', ''));

        if ($text === '') {
            Response::error(__('validation.required', ['field' => __('reminder.title')]), 422, 'VALIDATION_FAILED');
        }

        $parsed = GeminiService::parse($text, $user);

        if (!Request::bool('create', true)) {
            Response::ok(['parsed' => $parsed['data'], 'source' => $parsed['source']]);
        }

        $outcome = InboundProcessor::applyEnvelope($parsed['data'], $user, 'web', null, $parsed['source'], $text);

        Response::ok(
            ['result' => $outcome['result'], 'source' => $parsed['source'], 'parsed' => $parsed['data']],
            strip_tags(str_replace(['*', '_'], '', $outcome['reply']))
        );
    }

    /* -------------------------------------------------------------- Export */

    public function export(string $format): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $tz = (string) $user['timezone'];

        $reminders = App::i()->db()->all(
            'SELECT * FROM reminders WHERE user_id = ? AND deleted_at IS NULL ORDER BY start_at DESC LIMIT 5000',
            [$userId]
        );

        if ($format === 'ics') {
            Response::stream(ReportService::toIcs($reminders, $tz), 'krishna-reminders.ics', 'text/calendar');
        }

        $rows = array_map(static fn ($r) => [
            'short_code' => $r['short_code'],
            'title'      => $r['title'],
            'type'       => $r['type'],
            'priority'   => $r['priority'],
            'due'        => to_user_time((string) $r['start_at'], 'Y-m-d H:i', $tz),
            'repeat'     => (string) (json_field($r['recurrence'], ['freq' => 'none'])['freq'] ?? 'none'),
            'amount'     => $r['amount'],
            'person'     => $r['person_name'],
            'status'     => $r['status'],
            'source'     => $r['source'],
        ], $reminders);

        $csv = ReportService::toCsv($rows, [
            'short_code' => 'Code', 'title' => 'Title', 'type' => 'Type', 'priority' => 'Priority',
            'due' => 'Due', 'repeat' => 'Repeat', 'amount' => 'Amount', 'person' => 'Person',
            'status' => 'Status', 'source' => 'Source',
        ]);

        Response::stream($csv, 'krishna-reminders.csv', 'text/csv');
    }

    /* -------------------------------------------------------------- Helpers */

    private function formData(array $user): ?array
    {
        $title = trim((string) Request::post('title', ''));

        if ($title === '') {
            return null;
        }

        $tz = (string) $user['timezone'];
        $date = (string) Request::post('due_date', date('Y-m-d'));
        $time = (string) Request::post('due_time', '09:00');
        $allDay = Request::bool('all_day');

        $startUtc = to_utc($date . ' ' . ($allDay ? '09:00' : $time) . ':00', $tz);

        $freq = (string) Request::post('repeat_freq', 'none');
        $recurrence = [
            'freq'         => in_array($freq, ['none', 'daily', 'weekly', 'monthly', 'yearly'], true) ? $freq : 'none',
            'interval'     => max(1, (int) Request::post('repeat_interval', 1)),
            'by_day'       => array_values(array_filter((array) Request::input('repeat_days', []), static fn ($d) => in_array($d, ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], true))),
            'by_month_day' => Request::post('repeat_month_day') ? (int) Request::post('repeat_month_day') : null,
            'until'        => null,
            'count'        => Request::post('repeat_count') ? (int) Request::post('repeat_count') : null,
        ];

        $endsOn = (string) Request::post('ends_on', '');

        return [
            'title'              => $title,
            'description'        => (string) Request::post('description', ''),
            'type'               => (string) Request::post('type', 'task'),
            'priority'           => (string) Request::post('priority', 'normal'),
            'category_id'        => Request::post('category_id') ? (int) Request::post('category_id') : null,
            'contact_id'         => Request::post('contact_id') ? (int) Request::post('contact_id') : null,
            'start_at'           => $startUtc,
            'end_at'             => $endsOn !== '' ? to_utc($endsOn . ' 23:59:59', $tz) : null,
            'all_day'            => $allDay ? 1 : 0,
            'recurrence'         => $recurrence,
            'call_reminder'      => Request::bool('call_reminder', true) ? 1 : 0,
            'advance_alerts'     => array_map('intval', (array) Request::input('advance_alerts', [])),
            'snooze_default_min' => max(1, (int) Request::post('snooze_default_min', 5)),
            'amount'             => Request::post('amount') !== null && Request::post('amount') !== '' ? (float) Request::post('amount') : null,
            'currency'           => (string) Request::post('currency', 'INR'),
            'person_name'        => Request::post('person_name') ?: null,
            'person_phone'       => Request::post('person_phone') ? normalize_phone((string) Request::post('person_phone')) : null,
            'location'           => Request::post('location') ?: null,
            'color'              => Request::post('color') ?: null,
            'assigned_to'        => Request::post('assigned_to') ? (int) Request::post('assigned_to') : null,
            'tags'               => array_filter(array_map('trim', explode(',', (string) Request::post('tags', '')))),
            'source'             => 'web',
            'timezone'           => $tz,
        ];
    }

    private function handleAttachment(int $userId, int $reminderId): void
    {
        if (!isset($_FILES['attachment']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $result = UploadService::store($_FILES['attachment'], $userId, 'attachments', ['reminder_id' => $reminderId]);

        if (!$result['ok']) {
            Session::flash('warning', (string) $result['error']);
        }
    }

    private function categories(int $userId): array
    {
        return App::i()->db()->all(
            'SELECT * FROM categories WHERE user_id IS NULL OR user_id = ? ORDER BY sort_order ASC',
            [$userId]
        );
    }

    private function contacts(int $userId): array
    {
        return App::i()->db()->all(
            'SELECT * FROM contacts WHERE user_id = ? AND deleted_at IS NULL ORDER BY name ASC LIMIT 200',
            [$userId]
        );
    }

    private function actionResponse(bool $ok, string $message): never
    {
        if (Request::wantsJson()) {
            Response::json(null, $message, $ok ? 200 : 409, $ok ? 'OK' : 'ACTION_FAILED');
        }

        Session::flash($ok ? 'success' : 'error', $message);
        Response::back(url('/client/reminders'));
    }
}
