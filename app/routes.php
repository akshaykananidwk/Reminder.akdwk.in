<?php

/**
 * Route table. Handlers are [ControllerName, action] where ControllerName is
 * relative to App\Controllers.
 *
 * @var \App\Core\Router $router
 */

/* ------------------------------------------------------------ Public site */

$router->get('/', ['HomeController', 'index']);
$router->get('/features', ['HomeController', 'features']);
$router->get('/pricing', ['HomeController', 'pricing']);
$router->get('/about', ['HomeController', 'about']);
$router->get('/contact', ['HomeController', 'contact']);
$router->post('/contact', ['HomeController', 'submitContact'], ['csrf']);
$router->get('/blog', ['HomeController', 'blog']);
$router->get('/blog/{slug}', ['HomeController', 'post']);
$router->get('/faq', ['HomeController', 'faq']);
$router->get('/download', ['HomeController', 'download']);
$router->get('/reminder-app/{city}', ['HomeController', 'city']);
$router->get('/p/{slug}', ['HomeController', 'page']);
$router->get('/sitemap.xml', ['HomeController', 'sitemap']);
$router->get('/robots.txt', ['HomeController', 'robots']);
$router->get('/manifest.webmanifest', ['HomeController', 'manifest']);
$router->get('/offline', ['HomeController', 'offline']);

/* -------------------------------------------------------------------- Auth */

$router->get('/register', ['AuthController', 'showRegister'], ['guest']);
$router->post('/register', ['AuthController', 'register'], ['guest', 'csrf']);
$router->get('/login', ['AuthController', 'showLogin'], ['guest']);
$router->post('/login', ['AuthController', 'login'], ['guest', 'csrf']);
$router->post('/login/otp', ['AuthController', 'loginWithOtp'], ['guest', 'csrf']);
$router->get('/verify', ['AuthController', 'showVerify']);
$router->post('/verify', ['AuthController', 'verify'], ['csrf']);
$router->post('/otp/send', ['AuthController', 'sendOtp'], ['csrf']);
$router->get('/forgot-password', ['AuthController', 'showForgot'], ['guest']);
$router->post('/forgot-password', ['AuthController', 'forgot'], ['guest', 'csrf']);
$router->get('/reset-password', ['AuthController', 'showReset'], ['guest']);
$router->post('/reset-password', ['AuthController', 'reset'], ['guest', 'csrf']);
$router->post('/logout', ['AuthController', 'logout'], ['csrf']);
$router->get('/auth/google', ['AuthController', 'googleRedirect']);
$router->get('/auth/google/callback', ['AuthController', 'googleCallback']);

/* ---------------------------------------------------------- Client area */

$router->group('/client', ['auth'], function ($r): void {
    $r->get('', ['Client\DashboardController', 'index']);
    $r->get('/stats', ['Client\DashboardController', 'stats']);

    // Reminders
    $r->get('/reminders', ['Client\ReminderController', 'index']);
    $r->get('/reminders/create', ['Client\ReminderController', 'create']);
    $r->post('/reminders', ['Client\ReminderController', 'store'], ['csrf']);
    $r->get('/reminders/{id}', ['Client\ReminderController', 'show']);
    $r->get('/reminders/{id}/edit', ['Client\ReminderController', 'edit']);
    $r->post('/reminders/{id}', ['Client\ReminderController', 'update'], ['csrf']);
    $r->post('/reminders/{id}/delete', ['Client\ReminderController', 'destroy'], ['csrf']);
    $r->post('/reminders/{id}/restore', ['Client\ReminderController', 'restore'], ['csrf']);
    $r->post('/occurrences/{id}/action', ['Client\ReminderController', 'occurrenceAction'], ['csrf']);
    $r->post('/reminders/bulk', ['Client\ReminderController', 'bulk'], ['csrf']);
    $r->post('/reminders/parse', ['Client\ReminderController', 'parse'], ['csrf']);
    $r->get('/trash', ['Client\ReminderController', 'trash']);
    $r->get('/export/{format}', ['Client\ReminderController', 'export']);

    // Calendar
    $r->get('/calendar', ['Client\ReportController', 'calendar']);
    $r->get('/calendar/events', ['Client\ReportController', 'calendarEvents']);

    // Payments
    $r->get('/payments', ['Client\PaymentController', 'index']);
    $r->post('/payments/{id}/pay', ['Client\PaymentController', 'markPaid'], ['csrf']);
    $r->post('/payments', ['Client\PaymentController', 'store'], ['csrf']);
    $r->get('/payments/export', ['Client\PaymentController', 'export']);

    // Notes & contacts
    $r->get('/notes', ['Client\NoteController', 'index']);
    $r->post('/notes', ['Client\NoteController', 'store'], ['csrf']);
    $r->post('/notes/{id}/convert', ['Client\NoteController', 'convert'], ['csrf']);
    $r->post('/notes/{id}/delete', ['Client\NoteController', 'destroy'], ['csrf']);
    $r->get('/contacts', ['Client\NoteController', 'contacts']);
    $r->post('/contacts', ['Client\NoteController', 'storeContact'], ['csrf']);
    $r->post('/contacts/{id}/delete', ['Client\NoteController', 'destroyContact'], ['csrf']);

    // Reports
    $r->get('/reports', ['Client\ReportController', 'index']);
    $r->get('/reports/export/{format}', ['Client\ReportController', 'export']);

    // WhatsApp inbox
    $r->get('/inbox', ['Client\InboxController', 'index']);
    $r->post('/inbox/{id}/reprocess', ['Client\InboxController', 'reprocess'], ['csrf']);

    // Settings & account
    $r->get('/settings', ['Client\SettingsController', 'index']);
    $r->post('/settings', ['Client\SettingsController', 'save'], ['csrf']);
    $r->post('/settings/profile', ['Client\SettingsController', 'saveProfile'], ['csrf']);
    $r->post('/settings/password', ['Client\SettingsController', 'changePassword'], ['csrf']);
    $r->post('/settings/numbers', ['Client\SettingsController', 'addNumber'], ['csrf']);
    $r->post('/settings/numbers/{id}/delete', ['Client\SettingsController', 'removeNumber'], ['csrf']);
    $r->get('/devices', ['Client\SettingsController', 'devices']);
    $r->post('/devices/{id}/test', ['Client\SettingsController', 'testDevice'], ['csrf']);
    $r->post('/devices/{id}/delete', ['Client\SettingsController', 'removeDevice'], ['csrf']);
    $r->post('/sessions/{id}/revoke', ['Client\SettingsController', 'revokeSession'], ['csrf']);
    $r->get('/integrations', ['Client\SettingsController', 'integrations']);
    $r->post('/integrations/api-key', ['Client\SettingsController', 'generateApiKey'], ['csrf']);
    $r->post('/integrations/webhook', ['Client\SettingsController', 'saveWebhook'], ['csrf']);
    $r->post('/integrations/google/disconnect', ['Client\SettingsController', 'disconnectGoogle'], ['csrf']);
    $r->get('/billing', ['Client\SettingsController', 'billing']);
    $r->post('/billing/subscribe', ['Client\SettingsController', 'subscribe'], ['csrf']);
    $r->get('/billing/invoice/{id}', ['Client\SettingsController', 'invoice']);
    $r->get('/referral', ['Client\SettingsController', 'referral']);
    $r->post('/referral/payout', ['Client\SettingsController', 'requestPayout'], ['csrf']);
    $r->get('/help', ['Client\SettingsController', 'help']);
    $r->get('/account/export', ['Client\SettingsController', 'exportData']);
    $r->post('/account/delete', ['Client\SettingsController', 'deleteAccount'], ['csrf']);
});

/* ------------------------------------------------------------ Admin area */

$router->get('/admin/login', ['Admin\AdminController', 'showLogin'], ['guest_admin']);
$router->post('/admin/login', ['Admin\AdminController', 'login'], ['guest_admin', 'csrf']);
$router->post('/admin/logout', ['Admin\AdminController', 'logout'], ['csrf']);

$router->group('/admin', ['admin'], function ($r): void {
    $r->get('', ['Admin\AdminController', 'dashboard']);
    $r->get('/health', ['Admin\MonitorController', 'health']);

    // Users
    $r->get('/users', ['Admin\UserController', 'index']);
    $r->get('/users/{id}', ['Admin\UserController', 'show']);
    $r->post('/users/{id}', ['Admin\UserController', 'update'], ['csrf']);
    $r->post('/users/{id}/suspend', ['Admin\UserController', 'suspend'], ['csrf']);
    $r->post('/users/{id}/delete', ['Admin\UserController', 'destroy'], ['csrf']);
    $r->post('/users/{id}/impersonate', ['Admin\UserController', 'impersonate'], ['csrf']);
    $r->post('/users/{id}/extend', ['Admin\UserController', 'extendPlan'], ['csrf']);
    $r->post('/users/{id}/reset-otp', ['Admin\UserController', 'resetOtp'], ['csrf']);

    // Plans, subscriptions, invoices, coupons
    $r->get('/plans', ['Admin\PlanController', 'index']);
    $r->post('/plans', ['Admin\PlanController', 'save'], ['csrf']);
    $r->post('/plans/{id}/delete', ['Admin\PlanController', 'destroy'], ['csrf']);
    $r->get('/subscriptions', ['Admin\PlanController', 'subscriptions']);
    $r->post('/subscriptions/{id}/approve', ['Admin\PlanController', 'approve'], ['csrf']);
    $r->get('/invoices', ['Admin\PlanController', 'invoices']);
    $r->get('/invoices/{id}', ['Admin\PlanController', 'invoice']);
    $r->get('/coupons', ['Admin\PlanController', 'coupons']);
    $r->post('/coupons', ['Admin\PlanController', 'saveCoupon'], ['csrf']);

    // Configuration
    $r->get('/settings', ['Admin\ConfigController', 'general']);
    $r->post('/settings', ['Admin\ConfigController', 'saveGeneral'], ['csrf']);
    $r->get('/whatsapp', ['Admin\ConfigController', 'whatsapp']);
    $r->post('/whatsapp', ['Admin\ConfigController', 'saveWhatsapp'], ['csrf']);
    $r->post('/whatsapp/test', ['Admin\ConfigController', 'testWhatsapp'], ['csrf']);
    $r->post('/whatsapp/test-cloud', ['Admin\ConfigController', 'testCloud'], ['csrf']);
    $r->get('/ai', ['Admin\ConfigController', 'ai']);
    $r->post('/ai', ['Admin\ConfigController', 'saveAi'], ['csrf']);
    $r->post('/ai/test', ['Admin\ConfigController', 'testAi'], ['csrf']);
    $r->get('/templates', ['Admin\ConfigController', 'templates']);
    $r->post('/templates', ['Admin\ConfigController', 'saveTemplate'], ['csrf']);
    $r->get('/content', ['Admin\ConfigController', 'content']);
    $r->post('/content/page', ['Admin\ConfigController', 'savePage'], ['csrf']);
    $r->post('/content/post', ['Admin\ConfigController', 'savePost'], ['csrf']);
    $r->post('/content/faq', ['Admin\ConfigController', 'saveFaq'], ['csrf']);

    // Monitoring
    $r->get('/cron', ['Admin\MonitorController', 'cron']);
    $r->post('/cron/run', ['Admin\MonitorController', 'runCron'], ['csrf']);
    $r->get('/logs', ['Admin\MonitorController', 'logs']);
    $r->get('/ai-report', ['Admin\MonitorController', 'aiReport']);
    $r->get('/broadcast', ['Admin\MonitorController', 'broadcast']);
    $r->post('/broadcast', ['Admin\MonitorController', 'sendBroadcast'], ['csrf']);
    $r->get('/audit', ['Admin\MonitorController', 'audit']);

    // System & updates
    $r->get('/system', ['Admin\UpdateController', 'system']);
    $r->post('/system/backup', ['Admin\UpdateController', 'runBackup'], ['csrf']);
    $r->post('/system/restore/{id}', ['Admin\UpdateController', 'restore'], ['csrf']);
    $r->get('/system/backup/{id}/download', ['Admin\UpdateController', 'downloadBackup']);
    $r->post('/system/cache/clear', ['Admin\UpdateController', 'clearCache'], ['csrf']);
    $r->post('/system/maintenance', ['Admin\UpdateController', 'toggleMaintenance'], ['csrf']);
    $r->get('/update', ['Admin\UpdateController', 'index']);
    $r->post('/update/settings', ['Admin\UpdateController', 'saveSettings'], ['csrf']);
    $r->post('/update/check', ['Admin\UpdateController', 'check'], ['csrf']);
    $r->post('/update/run', ['Admin\UpdateController', 'run'], ['csrf']);
});
