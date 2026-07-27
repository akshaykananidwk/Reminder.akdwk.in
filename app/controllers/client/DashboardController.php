<?php

namespace App\Controllers\Client;

use App\Core\App;
use App\Core\Controller;
use App\Core\Response;
use App\Services\PaymentService;
use App\Services\ReminderService;

class DashboardController extends Controller
{
    public function index(): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $tz = (string) $user['timezone'];

        $stats = ReminderService::stats($userId, $tz);

        $hour = (int) (new \DateTime('now', new \DateTimeZone($tz)))->format('G');
        $greetingKey = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');

        $this->view('client/dashboard', [
            'title'      => __('nav.home'),
            'pageTitle'  => __('dashboard.greeting_' . $greetingKey, ['name' => explode(' ', (string) $user['name'])[0]]),
            'stats'      => $stats,
            'today'      => ReminderService::todayOccurrences($userId, $tz),
            'overdue'    => ReminderService::overdue($userId, 5),
            'payments'   => PaymentService::totals($userId, $tz),
            'duePayments'=> PaymentService::duePayments($userId, 5),
            'notifications' => App::i()->db()->all(
                'SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5',
                [$userId]
            ),
        ], 'layouts/client');
    }

    /** JSON counters for the live dashboard refresh. */
    public function stats(): void
    {
        $user = $this->requireUser();

        Response::ok(ReminderService::stats((int) $user['id'], (string) $user['timezone']));
    }
}
