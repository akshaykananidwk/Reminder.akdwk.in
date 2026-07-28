<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\MetaPricingService;
use App\Services\MetaSignupService;
use App\Services\WabaAccountService;
use App\Services\WaTemplateService;

/**
 * The WhatsApp Business Platform admin: connected accounts, templates,
 * conversations, billing and the raw API/webhook logs.
 *
 * Every page is scoped to one `waba_accounts` row. That is what makes the
 * multi-tenant story real rather than aspirational — an admin looking at
 * templates is looking at one business's templates, and the account id is
 * carried in the query string so it can never be implied by session state.
 */
class MetaController extends Controller
{
    private const PER_PAGE = 25;

    /* -------------------------------------------------------------- Accounts */

    public function index(): void
    {
        $this->requireAdmin();

        $accounts = WabaAccountService::all();
        $phones = [];

        foreach ($accounts as $account) {
            $phones[(int) $account['id']] = $this->phonesFor((int) $account['id']);
        }

        $this->view('admin/meta', [
            'title'       => 'WhatsApp Business Platform',
            'pageTitle'   => 'WhatsApp Business Platform',
            'settings'    => App::i()->settings(),
            'signup'      => MetaSignupService::status(),
            'browser'     => MetaSignupService::browserConfig(),
            'accounts'    => $accounts,
            'phones'      => $phones,
            'callback'    => MetaSignupService::callbackUrl(),
            'verifyToken' => MetaSignupService::verifyToken(),
            'steps'       => Session::get('meta_steps', []),
        ], 'layouts/admin');

        Session::forget('meta_steps');
    }

    /** Save the platform-level Meta app credentials. */
    public function saveApp(): void
    {
        $this->requireAdmin();
        $settings = App::i()->settings();

        foreach (['meta_app_id', 'meta_config_id', 'meta_graph_version'] as $key) {
            $value = trim((string) Request::post($key, ''));

            if ($value !== '') {
                $settings->set($key, $value, false, 'meta');
            }
        }

        // A blank secret box means "keep what is stored", never "erase it".
        $secret = trim((string) Request::post('meta_app_secret', ''));

        if ($secret !== '') {
            $settings->set('meta_app_secret', $secret, true, 'meta');
        }

        $settings->set('meta_only_mode', Request::bool('meta_only_mode') ? '1' : '0', false, 'meta');
        $settings->set(
            'meta_price_markup_percent',
            (string) max(0, (float) Request::post('meta_price_markup_percent', 0)),
            false,
            'meta'
        );

        $settings->refresh();
        AuditService::log('meta.app_updated', 'settings');

        Session::flash('success', 'Saved.');
        Response::redirect(url('/admin/meta'));
    }

    /** Finish an Embedded Signup started in the browser. */
    public function connect(): void
    {
        $this->requireAdmin();

        $result = MetaSignupService::connect(
            trim((string) Request::post('code', '')),
            trim((string) Request::post('waba_id', '')) ?: null,
            trim((string) Request::post('phone_number_id', '')) ?: null,
            null,
            trim((string) Request::post('pin', '')) ?: null
        );

        Session::set('meta_steps', $result['steps']);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        AuditService::log('meta.connect', 'waba_accounts', $result['account_id']);

        Response::redirect(url('/admin/meta'));
    }

    /** Connect with a System User token pasted by hand. */
    public function connectToken(): void
    {
        $this->requireAdmin();

        $result = MetaSignupService::connectWithToken(
            (string) Request::post('access_token', ''),
            trim((string) Request::post('waba_id', '')) ?: null,
            null,
            trim((string) Request::post('app_secret', '')) ?: null
        );

        Session::set('meta_steps', $result['steps']);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        AuditService::log('meta.connect_token', 'waba_accounts', $result['account_id']);

        Response::redirect(url('/admin/meta'));
    }

    public function subscribe(): void
    {
        $this->requireAdmin();

        $result = WabaAccountService::subscribeWebhook($this->accountId());
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        Response::back(url('/admin/meta'));
    }

    public function syncPhones(): void
    {
        $this->requireAdmin();

        $result = WabaAccountService::syncPhoneNumbers($this->accountId());
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        Response::back(url('/admin/meta'));
    }

    public function registerPhone(): void
    {
        $this->requireAdmin();

        $result = WabaAccountService::registerPhone(
            $this->accountId(),
            trim((string) Request::post('phone_number_id', '')),
            trim((string) Request::post('pin', ''))
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Response::back(url('/admin/meta'));
    }

    /** Health check: is our app really subscribed, and what does Meta say? */
    public function health(): void
    {
        $this->requireAdmin();

        $accountId = $this->accountId();
        $webhook = WabaAccountService::webhookStatus($accountId);

        Session::flash($webhook['ok'] ? 'success' : 'error', 'Webhook: ' . $webhook['message']);
        Response::back(url('/admin/meta'));
    }

    public function disconnect(): void
    {
        $this->requireAdmin();

        $accountId = $this->accountId();

        // Deliberately not a delete: messages, conversations and billing rows
        // reference this account, and a business that reconnects tomorrow should
        // still see last month's invoice.
        App::i()->db()->update('waba_accounts', [
            'status'       => 'disconnected',
            'access_token' => null,
            'updated_at'   => now_utc(),
        ], 'id = :id', ['id' => $accountId]);

        AuditService::log('meta.disconnect', 'waba_accounts', $accountId);
        Session::flash('success', 'Disconnected. History has been kept.');

        Response::redirect(url('/admin/meta'));
    }

    /* ------------------------------------------------------------- Templates */

    public function templates(): void
    {
        $this->requireAdmin();

        $account = $this->account();

        $this->view('admin/meta_templates', [
            'title'     => 'WhatsApp templates',
            'pageTitle' => 'WhatsApp templates',
            'account'   => $account,
            'accounts'  => WabaAccountService::all(),
            'templates' => $account === null ? [] : WaTemplateService::listFor((int) $account['id']),
            'editing'   => $account === null
                ? null
                : WaTemplateService::find((int) Request::get('edit', 0), (int) $account['id']),
        ], 'layouts/admin');
    }

    public function saveTemplate(): void
    {
        $this->requireAdmin();

        $account = $this->account();

        if ($account === null) {
            Session::flash('error', 'Connect a WhatsApp account first.');
            Response::back(url('/admin/meta/templates'));
        }

        $spec = [
            'name'          => strtolower(trim((string) Request::post('name', ''))),
            'language'      => trim((string) Request::post('language', 'en')),
            'category'      => strtoupper(trim((string) Request::post('category', 'UTILITY'))),
            'header_format' => trim((string) Request::post('header_format', '')),
            'header_text'   => trim((string) Request::post('header_text', '')),
            'body'          => (string) Request::post('body', ''),
            'footer'        => trim((string) Request::post('footer', '')),
            'body_examples' => array_values(array_filter(array_map(
                'trim',
                explode('|', (string) Request::post('body_examples', ''))
            ), static fn (string $v): bool => $v !== '')),
            'buttons'       => $this->buttonsFromPost(),
        ];

        $templateId = (int) Request::post('template_id', 0);
        $result = WaTemplateService::saveLocal($account, $spec, $templateId > 0 ? $templateId : null);

        if (!$result['ok']) {
            Session::flash('error', implode(' ', $result['errors']));
            Response::back(url('/admin/meta/templates'));
        }

        AuditService::log('meta.template_saved', 'wa_templates', $result['id']);

        if (Request::bool('submit_now') && $result['id'] !== null) {
            $submitted = WaTemplateService::submit($account, (int) $result['id']);
            Session::flash($submitted['ok'] ? 'success' : 'error', $submitted['message']);
        } else {
            Session::flash('success', 'Saved as a draft. Submit it when you are ready.');
        }

        Response::redirect(url('/admin/meta/templates?account=' . (int) $account['id']));
    }

    public function submitTemplate(): void
    {
        $this->requireAdmin();

        $account = $this->account();
        $templateId = (int) Request::post('template_id', 0);

        if ($account === null || $templateId <= 0) {
            Session::flash('error', 'Nothing to submit.');
            Response::back(url('/admin/meta/templates'));
        }

        $template = WaTemplateService::find($templateId, (int) $account['id']);

        $result = ($template !== null && !empty($template['meta_template_id']))
            ? WaTemplateService::pushEdit($account, $templateId)
            : WaTemplateService::submit($account, $templateId);

        AuditService::log('meta.template_submitted', 'wa_templates', $templateId);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        Response::back(url('/admin/meta/templates'));
    }

    public function syncTemplates(): void
    {
        $this->requireAdmin();

        $account = $this->account();

        if ($account === null) {
            Session::flash('error', 'Connect a WhatsApp account first.');
            Response::back(url('/admin/meta/templates'));
        }

        $result = WaTemplateService::syncAll($account);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        Response::back(url('/admin/meta/templates'));
    }

    public function cloneTemplate(): void
    {
        $this->requireAdmin();

        $account = $this->account();

        if ($account === null) {
            Response::back(url('/admin/meta/templates'));
        }

        $result = WaTemplateService::cloneTemplate(
            $account,
            (int) Request::post('template_id', 0),
            (string) Request::post('new_name', ''),
            trim((string) Request::post('new_language', '')) ?: null
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Copied.' : implode(' ', $result['errors']));
        Response::back(url('/admin/meta/templates'));
    }

    public function deleteTemplate(): void
    {
        $this->requireAdmin();

        $account = $this->account();

        if ($account === null) {
            Response::back(url('/admin/meta/templates'));
        }

        $result = WaTemplateService::deleteTemplate($account, (int) Request::post('template_id', 0));

        AuditService::log('meta.template_deleted', 'wa_templates', (int) Request::post('template_id', 0));
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        Response::back(url('/admin/meta/templates'));
    }

    public function exportTemplates(): void
    {
        $this->requireAdmin();

        $account = $this->account();
        $json = $account === null ? '{"templates":[]}' : WaTemplateService::export((int) $account['id']);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="whatsapp-templates.json"');
        echo $json;
        exit;
    }

    public function importTemplates(): void
    {
        $this->requireAdmin();

        $account = $this->account();

        if ($account === null) {
            Response::back(url('/admin/meta/templates'));
        }

        $json = (string) Request::post('json', '');

        if ($json === '' && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $json = (string) file_get_contents($_FILES['file']['tmp_name']);
        }

        $result = WaTemplateService::import($account, $json);

        Session::flash(
            $result['ok'] ? 'success' : 'error',
            $result['imported'] . ' imported, ' . $result['skipped'] . ' skipped. ' . implode(' ', $result['errors'])
        );

        Response::back(url('/admin/meta/templates'));
    }

    /* --------------------------------------------------------- Conversations */

    public function conversations(): void
    {
        $this->requireAdmin();

        $account = $this->account();
        $db = App::i()->db();
        $accountId = $account === null ? 0 : (int) $account['id'];
        $contact = trim((string) Request::get('contact', ''));

        $where = 'waba_account_id = ?';
        $params = [$accountId];

        if ($contact !== '') {
            $where .= ' AND contact_wa_id LIKE ?';
            $params[] = '%' . $contact . '%';
        }

        $total = (int) $db->value('SELECT COUNT(*) FROM wa_messages WHERE ' . $where, $params, 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) Request::get('page', 1)));

        $this->view('admin/meta_conversations', [
            'title'     => 'WhatsApp conversations',
            'pageTitle' => 'WhatsApp conversations',
            'account'   => $account,
            'accounts'  => WabaAccountService::all(),
            'contact'   => $contact,
            'messages'  => $db->all(
                'SELECT * FROM wa_messages WHERE ' . $where . ' ORDER BY id DESC LIMIT '
                . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
                $params
            ),
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'stats'     => [
                'in'      => (int) $db->value("SELECT COUNT(*) FROM wa_messages WHERE waba_account_id = ? AND direction = 'in'", [$accountId], 0),
                'out'     => (int) $db->value("SELECT COUNT(*) FROM wa_messages WHERE waba_account_id = ? AND direction = 'out'", [$accountId], 0),
                'failed'  => (int) $db->value("SELECT COUNT(*) FROM wa_messages WHERE waba_account_id = ? AND status = 'failed'", [$accountId], 0),
                'read'    => (int) $db->value("SELECT COUNT(*) FROM wa_messages WHERE waba_account_id = ? AND status = 'read'", [$accountId], 0),
            ],
        ], 'layouts/admin');
    }

    /* --------------------------------------------------------------- Billing */

    public function billing(): void
    {
        $this->requireAdmin();

        $account = $this->account();
        $accountId = $account === null ? 0 : (int) $account['id'];

        $month = (string) Request::get('month', gmdate('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = gmdate('Y-m');
        }

        $from = $month . '-01 00:00:00';
        $to = gmdate('Y-m-d H:i:s', strtotime($from . ' +1 month'));

        $db = App::i()->db();

        $this->view('admin/meta_billing', [
            'title'     => 'WhatsApp billing',
            'pageTitle' => 'WhatsApp billing',
            'account'   => $account,
            'accounts'  => WabaAccountService::all(),
            'month'     => $month,
            'summary'   => MetaPricingService::summary($accountId, $from, $to),
            'rates'     => $db->all('SELECT * FROM wa_price_rates ORDER BY country_code, category'),
            'markup'    => MetaPricingService::markupPercent(),
            'daily'     => $db->all(
                'SELECT DATE(started_at) AS day, COUNT(*) AS conversations, SUM(price) AS total
                   FROM wa_conversations
                  WHERE waba_account_id = ? AND started_at >= ? AND started_at < ?
                  GROUP BY DATE(started_at) ORDER BY day DESC',
                [$accountId, $from, $to]
            ),
        ], 'layouts/admin');
    }

    public function saveRates(): void
    {
        $this->requireAdmin();

        $db = App::i()->db();
        $prices = Request::post('price', []);
        $currencies = Request::post('currency', []);

        if (is_array($prices)) {
            foreach ($prices as $id => $price) {
                $db->update('wa_price_rates', [
                    'price'    => max(0, (float) $price),
                    'currency' => mb_substr((string) ($currencies[$id] ?? 'INR'), 0, 8),
                    'source'   => 'set by admin ' . gmdate('Y-m-d'),
                ], 'id = :id', ['id' => (int) $id]);
            }
        }

        MetaPricingService::clearCache();
        AuditService::log('meta.rates_updated', 'wa_price_rates');
        Session::flash('success', 'Rate card saved.');

        Response::back(url('/admin/meta/billing'));
    }

    /* ------------------------------------------------------------------ Logs */

    public function logs(): void
    {
        $this->requireAdmin();

        $db = App::i()->db();
        $page = max(1, (int) Request::get('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $errorsOnly = Request::get('errors') !== null;
        $where = $errorsOnly ? 'WHERE error_code IS NOT NULL OR http_code >= 400' : '';

        $this->view('admin/meta_logs', [
            'title'      => 'WhatsApp API log',
            'pageTitle'  => 'WhatsApp API log',
            'errorsOnly' => $errorsOnly,
            'calls'      => $db->all(
                'SELECT * FROM wa_api_log ' . $where . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset
            ),
            'events'     => $db->all('SELECT * FROM wa_webhook_events ORDER BY id DESC LIMIT ' . self::PER_PAGE),
            'page'       => $page,
            'pages'      => max(1, (int) ceil(
                ((int) $db->value('SELECT COUNT(*) FROM wa_api_log ' . $where, [], 0)) / self::PER_PAGE
            )),
            'unprocessed'=> (int) $db->value('SELECT COUNT(*) FROM wa_webhook_events WHERE processed = 0', [], 0),
            'unsigned'   => (int) $db->value('SELECT COUNT(*) FROM wa_webhook_events WHERE signature_valid = 0', [], 0),
        ], 'layouts/admin');
    }

    /* --------------------------------------------------------------- Helpers */

    /** The account being administered — from the query string, never implied. */
    private function account(): ?array
    {
        $id = (int) (Request::get('account', 0) ?: Request::post('account_id', 0));

        if ($id > 0) {
            $account = WabaAccountService::find($id);

            if ($account !== null) {
                return $account;
            }
        }

        return WabaAccountService::platform();
    }

    private function accountId(): int
    {
        $account = $this->account();

        return $account === null ? 0 : (int) $account['id'];
    }

    private function phonesFor(int $accountId): array
    {
        try {
            return App::i()->db()->all(
                'SELECT * FROM waba_phone_numbers WHERE waba_account_id = ? ORDER BY is_default DESC, id',
                [$accountId]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** Assemble the button rows a form posts as parallel arrays. */
    private function buttonsFromPost(): array
    {
        $types = Request::post('button_type', []);
        $texts = Request::post('button_text', []);
        $values = Request::post('button_value', []);

        if (!is_array($types)) {
            return [];
        }

        $buttons = [];

        foreach ($types as $index => $type) {
            $type = strtoupper(trim((string) $type));

            if ($type === '') {
                continue;
            }

            $buttons[] = [
                'type'         => $type,
                'text'         => (string) ($texts[$index] ?? ''),
                'url'          => $type === 'URL' ? (string) ($values[$index] ?? '') : '',
                'phone_number' => $type === 'PHONE_NUMBER' ? (string) ($values[$index] ?? '') : '',
                'example'      => (string) ($values[$index] ?? ''),
            ];
        }

        return $buttons;
    }
}
