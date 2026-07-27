<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\PlanService;
use App\Services\ReminderService;
use App\Services\ReportService;

class ReportController extends Controller
{
    /* ------------------------------------------------------------ Reports */

    public function index(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $tz = (string) $user['timezone'];

        $range = (string) Request::get('range', 'month');

        [$from, $to] = match ($range) {
            'today' => [date('Y-m-d'), date('Y-m-d')],
            'week'  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
            'year'  => [date('Y-01-01'), date('Y-m-d')],
            'custom'=> [
                (string) Request::get('from', date('Y-m-01')),
                (string) Request::get('to', date('Y-m-d')),
            ],
            default => [date('Y-m-01'), date('Y-m-t')],
        };

        $report = ReportService::build($userId, $from, $to, $tz);

        $this->view('client/reports', [
            'title'     => __('nav.reports'),
            'pageTitle' => __('nav.reports'),
            'report'    => $report,
            'range'     => $range,
            'from'      => $from,
            'to'        => $to,
            'fullReports' => PlanService::can($userId, 'full_reports'),
            'summaries' => App::i()->db()->all(
                'SELECT * FROM summaries WHERE user_id = ? ORDER BY summary_date DESC LIMIT 14',
                [$userId]
            ),
        ], 'layouts/client');
    }

    public function export(string $format): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $tz = (string) $user['timezone'];

        $from = (string) Request::get('from', date('Y-m-01'));
        $to = (string) Request::get('to', date('Y-m-t'));

        [$fromUtc] = ReminderService::localDayBounds($tz, $from);
        [, $toUtc] = ReminderService::localDayBounds($tz, $to);

        $rows = App::i()->db()->all(
            'SELECT o.due_at, o.status, o.done_at, o.done_via, o.snooze_count, r.title, r.short_code, r.type, r.priority, r.amount
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              ORDER BY o.due_at ASC LIMIT 10000',
            [$userId, $fromUtc, $toUtc]
        );

        $mapped = array_map(static fn ($row) => [
            'code'    => $row['short_code'],
            'title'   => $row['title'],
            'type'    => $row['type'],
            'priority'=> $row['priority'],
            'due'     => to_user_time((string) $row['due_at'], 'Y-m-d H:i', $tz),
            'status'  => $row['status'],
            'done_at' => $row['done_at'] ? to_user_time((string) $row['done_at'], 'Y-m-d H:i', $tz) : '',
            'done_via'=> $row['done_via'],
            'snoozes' => $row['snooze_count'],
            'amount'  => $row['amount'],
        ], $rows);

        if ($format === 'csv') {
            Response::stream(
                ReportService::toCsv($mapped, [
                    'code' => 'Code', 'title' => 'Title', 'type' => 'Type', 'priority' => 'Priority',
                    'due' => 'Due', 'status' => 'Status', 'done_at' => 'Completed at',
                    'done_via' => 'Completed via', 'snoozes' => 'Snoozes', 'amount' => 'Amount',
                ]),
                'krishna-report-' . $from . '-to-' . $to . '.csv',
                'text/csv'
            );
        }

        // "PDF" = print-optimised HTML; the browser's Save-as-PDF produces a
        // clean document without shipping a PDF library.
        $this->view('client/report_print', [
            'title'  => 'Report ' . $from . ' – ' . $to,
            'rows'   => $mapped,
            'report' => ReportService::build($userId, $from, $to, $tz),
            'user'   => $user,
            'from'   => $from,
            'to'     => $to,
        ], null);
    }

    /* ----------------------------------------------------------- Calendar */

    public function calendar(): void
    {
        $user = $this->requireUser();
        $tz = (string) $user['timezone'];

        $month = (string) Request::get('month', (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m'));

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $this->view('client/calendar', [
            'title'     => __('nav.calendar'),
            'pageTitle' => __('nav.calendar'),
            'month'     => $month,
            'days'      => $this->monthGrid($month, (int) $user['id'], $tz),
            'tz'        => $tz,
        ], 'layouts/client');
    }

    /** JSON feed used when the user drags an event to a new date. */
    public function calendarEvents(): void
    {
        $user = $this->requireUser();
        $tz = (string) $user['timezone'];

        $from = (string) Request::get('from', date('Y-m-01'));
        $to = (string) Request::get('to', date('Y-m-t'));

        [$fromUtc] = ReminderService::localDayBounds($tz, $from);
        [, $toUtc] = ReminderService::localDayBounds($tz, $to);

        $rows = App::i()->db()->all(
            'SELECT o.id, o.due_at, o.status, r.title, r.short_code, r.type, r.color
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              ORDER BY o.due_at ASC LIMIT 1000',
            [(int) $user['id'], $fromUtc, $toUtc]
        );

        Response::ok(array_map(static fn ($row) => [
            'id'     => (int) $row['id'],
            'title'  => (string) $row['title'],
            'code'   => (string) $row['short_code'],
            'type'   => (string) $row['type'],
            'status' => (string) $row['status'],
            'start'  => to_user_time((string) $row['due_at'], 'c', $tz),
            'date'   => to_user_time((string) $row['due_at'], 'Y-m-d', $tz),
            'time'   => to_user_time((string) $row['due_at'], 'H:i', $tz),
        ], $rows));
    }

    /**
     * Build a 6x7 month grid with the occurrences of each local day.
     */
    private function monthGrid(string $month, int $userId, string $tz): array
    {
        $first = new \DateTime($month . '-01', new \DateTimeZone($tz));
        $lastDay = (int) $first->format('t');

        [$fromUtc] = ReminderService::localDayBounds($tz, $first->format('Y-m-01'));
        [, $toUtc] = ReminderService::localDayBounds($tz, $first->format('Y-m-') . $lastDay);

        $rows = App::i()->db()->all(
            'SELECT o.id, o.due_at, o.status, r.title, r.short_code, r.type
               FROM reminder_occurrences o
               JOIN reminders r ON r.id = o.reminder_id
              WHERE o.user_id = ? AND r.deleted_at IS NULL AND o.due_at BETWEEN ? AND ?
              ORDER BY o.due_at ASC LIMIT 1000',
            [$userId, $fromUtc, $toUtc]
        );

        $byDay = [];

        foreach ($rows as $row) {
            $day = to_user_time((string) $row['due_at'], 'Y-m-d', $tz);
            $byDay[$day][] = $row;
        }

        // Start the grid on Monday.
        $start = clone $first;
        $offset = ((int) $first->format('N')) - 1;
        $start->modify('-' . $offset . ' days');

        $today = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
        $grid = [];

        for ($i = 0; $i < 42; $i++) {
            $date = (clone $start)->modify('+' . $i . ' days');
            $key = $date->format('Y-m-d');

            $grid[] = [
                'date'     => $key,
                'day'      => (int) $date->format('j'),
                'in_month' => $date->format('Y-m') === $month,
                'is_today' => $key === $today,
                'items'    => $byDay[$key] ?? [],
            ];
        }

        return $grid;
    }
}
