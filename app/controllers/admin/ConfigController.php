<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\GeminiService;
use App\Services\MetaCloudService;
use App\Services\TelegramService;
use App\Services\TemplateService;
use App\Services\WhatsAppService;

/**
 * Admin configuration: general settings, WhatsApp gateway, Gemini, message
 * templates and website content/SEO.
 */
class ConfigController extends Controller
{
    /* -------------------------------------------------------------- General */

    public function general(): void
    {
        $this->requireAdmin();

        $this->view('admin/settings', [
            'title'     => __('admin.settings'),
            'pageTitle' => __('admin.settings'),
            'settings'  => App::i()->settings(),
        ], 'layouts/admin');
    }

    public function saveGeneral(): void
    {
        $this->requireAdmin();
        $settings = App::i()->settings();

        foreach ([
            'site_name', 'company_name', 'company_address', 'company_phone', 'company_email',
            'company_gstin', 'support_whatsapp', 'default_language', 'default_timezone', 'currency',
            'ga4_id', 'search_console_verification', 'meta_description', 'apk_release_url',
            'alert_admin_number', 'cron_alert_minutes', 'referral_commission_percent', 'gst_rate',
            'invoice_prefix', 'grace_days', 'app_version', 'app_min_version', 'app_release_notes',
            'fcm_project_id', 'google_client_id',
        ] as $key) {
            $value = Request::post($key);

            if ($value !== null) {
                $settings->set($key, (string) $value, false, 'general');
            }
        }

        foreach (['fcm_service_account', 'fcm_server_key', 'google_client_secret', 'google_tts_api_key'] as $key) {
            $value = (string) Request::post($key, '');

            // An empty box means "leave the stored secret alone".
            if ($value !== '') {
                $settings->set($key, $value, true, 'general');
            }
        }

        $settings->set('google_enabled', Request::bool('google_enabled') ? '1' : '0');
        $settings->set('app_force_update', Request::bool('app_force_update') ? '1' : '0');

        $settings->refresh();
        AuditService::log('settings.updated', 'settings');

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/settings'));
    }

    /* ------------------------------------------------------------- WhatsApp */

    public function whatsapp(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/whatsapp', [
            'title'     => __('admin.whatsapp'),
            'pageTitle' => __('admin.whatsapp'),
            'settings'  => App::i()->settings(),
            'webhook'   => App::i()->url('/api/wa_webhook.php?secret=' . (string) App::i()->config('security.webhook_secret', '')),
            // Meta appends its own query string, so its callback URL carries no secret.
            'cloudHook' => App::i()->url('/api/wa_webhook.php'),
            'cloud'     => MetaCloudService::status(),
            'providers' => WhatsAppService::providerOrder(),
            // Each list pages independently, so opening page 3 of the log does
            // not also move the inbox.
            'log'       => $this->page('wa_outbound_log', 'log_page', 'id DESC'),
            'inbound'   => $this->page('wa_inbound_raw', 'in_page', 'id DESC'),
            'queue'     => $this->page('wa_outbound_queue', 'q_page', 'id DESC'),
            'unknown'   => $this->page('unknown_inbound', 'unk_page', 'last_seen_at DESC'),
            'stats'     => [
                'queued' => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_queue WHERE status = "queued"', [], 0),
                'failed' => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_queue WHERE status = "failed"', [], 0),
                'sent24' => (int) $db->value('SELECT COUNT(*) FROM wa_outbound_log WHERE success = 1 AND created_at > ?', [date('Y-m-d H:i:s', time() - 86400)], 0),
            ],
        ], 'layouts/admin');
    }

    public function saveWhatsapp(): void
    {
        $this->requireAdmin();
        $settings = App::i()->settings();

        $settings->set('wa_endpoint', rtrim((string) Request::post('wa_endpoint', ''), '/'), false, 'whatsapp');
        $settings->set('wa_sender_number', normalize_phone((string) Request::post('wa_sender_number', '')), false, 'whatsapp');
        $settings->set('wa_rate_per_minute', (string) max(1, (int) Request::post('wa_rate_per_minute', 30)), false, 'whatsapp');
        $settings->set('wa_max_retries', (string) max(1, (int) Request::post('wa_max_retries', 3)), false, 'whatsapp');
        $settings->set('wa_enabled', Request::bool('wa_enabled') ? '1' : '0', false, 'whatsapp');
        $settings->set('wa_invite_unknown', Request::bool('wa_invite_unknown') ? '1' : '0', false, 'whatsapp');
        $settings->set('wa_invite_cooldown_days', (string) max(1, (int) Request::post('wa_invite_cooldown_days', 7)), false, 'whatsapp');

        /* --- Meta WhatsApp Cloud API (second provider) --------------------- */

        $provider = strtolower(trim((string) Request::post('wa_provider', 'bulk')));
        $settings->set('wa_provider', in_array($provider, ['bulk', 'cloud'], true) ? $provider : 'bulk', false, 'whatsapp');
        $settings->set('wa_failover', Request::bool('wa_failover') ? '1' : '0', false, 'whatsapp');

        // Digits only — pasting the phone number instead of the ID is the most
        // common setup mistake, and it fails with an unhelpful Graph error.
        $phoneId = preg_replace('/\D+/', '', (string) Request::post('wa_cloud_phone_id', ''));
        $settings->set('wa_cloud_phone_id', (string) $phoneId, false, 'whatsapp');
        $settings->set('wa_cloud_business_id', (string) preg_replace('/\D+/', '', (string) Request::post('wa_cloud_business_id', '')), false, 'whatsapp');

        $version = trim((string) Request::post('wa_cloud_api_version', 'v23.0'));
        $settings->set('wa_cloud_api_version', preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v23.0', false, 'whatsapp');

        // Generated for the operator if they leave it blank, so the Meta form
        // can always be completed in one pass.
        $verify = trim((string) Request::post('wa_cloud_verify_token', ''));

        if ($verify === '' && trim((string) $settings->get('wa_cloud_verify_token', '')) === '') {
            $verify = MetaCloudService::suggestVerifyToken();
        }

        if ($verify !== '') {
            $settings->set('wa_cloud_verify_token', $verify, false, 'whatsapp');
        }

        $settings->set('wa_cloud_template_name', trim((string) Request::post('wa_cloud_template_name', '')), false, 'whatsapp');
        $settings->set('wa_cloud_template_lang', trim((string) Request::post('wa_cloud_template_lang', 'gu')) ?: 'gu', false, 'whatsapp');

        // Secrets: a blank field means "leave the stored value alone", so the
        // masked form never wipes a working credential.
        foreach (['wa_api_key', 'wa_session_id', 'wa_cloud_token', 'wa_cloud_app_secret'] as $secret) {
            $value = trim((string) Request::post($secret, ''));

            if ($value !== '') {
                $settings->set($secret, $value, true, 'whatsapp');
            }
        }

        $settings->refresh();
        AuditService::log('settings.whatsapp_updated', 'settings');

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/whatsapp'));
    }

    public function testWhatsapp(): void
    {
        $this->requireAdmin();

        $number = normalize_phone((string) Request::post('number', ''));

        if ($number === '') {
            Session::flash('error', __('auth.invalid_number'));
            Response::back(url('/admin/whatsapp'));
        }

        $result = WhatsAppService::sendNow(
            $number,
            (string) Request::post('message', "🙏 Krishna Reminder test message — " . date('d M Y, h:i A'))
        );

        $via = ($result['provider'] ?? 'bulk') === 'cloud' ? 'Meta Cloud API' : 'bulk.akdwk.in';

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Sent via ' . $via . '. Gateway said: ' . str_limit($result['response'], 200)
            : 'Failed via ' . $via . ': ' . str_limit($result['response'], 300));

        Response::redirect(url('/admin/whatsapp'));
    }

    /* ------------------------------------------------- Paged lists + delete */

    /** Rows per page across the admin message and log lists. */
    private const PER_PAGE = 10;

    /**
     * Tables these lists are allowed to touch. Page and delete both check
     * against this, so a table name can never arrive from the request — the
     * query builder interpolates it, and an allow-list is the only safe way to
     * do that.
     */
    private const LIST_TABLES = [
        'wa_outbound_log'   => 'Outbound log',
        'wa_inbound_raw'    => 'Inbound messages',
        'wa_outbound_queue' => 'Outbound queue',
        'unknown_inbound'   => 'Unknown senders',
    ];

    /**
     * One page of a list, plus what the view needs to draw the pager.
     *
     * @return array{rows: array, page: int, pages: int, total: int, param: string, table: string}
     */
    private function page(string $table, string $param, string $order): array
    {
        if (!isset(self::LIST_TABLES[$table])) {
            return ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'param' => $param, 'table' => $table];
        }

        $db = App::i()->db();
        $total = (int) $db->value('SELECT COUNT(*) FROM `' . $table . '`', [], 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) Request::get($param, 1)));
        $offset = ($page - 1) * self::PER_PAGE;

        return [
            'rows'  => $db->all('SELECT * FROM `' . $table . '` ORDER BY ' . $order . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
            'param' => $param,
            'table' => $table,
        ];
    }

    /** Delete a single row from one of the listed tables. */
    public function deleteRow(): void
    {
        $this->requireAdmin();

        $table = (string) Request::post('table', '');
        $id = (int) Request::post('id', 0);

        if (!isset(self::LIST_TABLES[$table]) || $id <= 0) {
            Session::flash('error', 'Nothing to delete.');
            Response::back(url('/admin/whatsapp'));
        }

        App::i()->db()->query('DELETE FROM `' . $table . '` WHERE id = ?', [$id]);
        AuditService::log('admin.row_deleted', $table, $id);

        Session::flash('success', 'Deleted.');
        Response::back(url('/admin/whatsapp'));
    }

    /**
     * Empty a whole list.
     *
     * Requires the operator to type the table's name, because there is no undo
     * and a stray click here loses the delivery history that failures are
     * diagnosed from.
     */
    public function clearList(): void
    {
        $this->requireAdmin();

        $table = (string) Request::post('table', '');
        $confirm = trim((string) Request::post('confirm', ''));

        if (!isset(self::LIST_TABLES[$table])) {
            Session::flash('error', 'Unknown list.');
            Response::back(url('/admin/whatsapp'));
        }

        if ($confirm !== $table) {
            Session::flash('error', 'Type ' . $table . ' exactly to confirm. Nothing was deleted.');
            Response::back(url('/admin/whatsapp'));
        }

        $count = (int) App::i()->db()->value('SELECT COUNT(*) FROM `' . $table . '`', [], 0);

        // DELETE, not TRUNCATE: TRUNCATE cannot run inside a transaction, is
        // blocked by foreign keys, and resets AUTO_INCREMENT — which would let
        // a new row reuse an id that older logs still refer to.
        App::i()->db()->query('DELETE FROM `' . $table . '`');
        AuditService::log('admin.list_cleared', $table, null, ['rows' => $count]);

        Session::flash('success', $count . ' row(s) deleted from ' . self::LIST_TABLES[$table] . '.');
        Response::back(url('/admin/whatsapp'));
    }

    /* ------------------------------------------------------------- Telegram */

    public function telegram(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/telegram', [
            'title'     => 'Telegram',
            'pageTitle' => 'Telegram',
            'settings'  => App::i()->settings(),
            'status'    => TelegramService::status(),
            'webhook'   => App::i()->url('/api/tg_webhook.php'),
            'linked'    => (int) $db->value('SELECT COUNT(*) FROM users WHERE telegram_chat_id IS NOT NULL', [], 0),
            'recent'    => $db->all(
                'SELECT id, to_number, message, success, http_code, response, created_at
                   FROM wa_outbound_log WHERE channel = "telegram" ORDER BY id DESC LIMIT 10'
            ),
        ], 'layouts/admin');
    }

    public function saveTelegram(): void
    {
        $this->requireAdmin();
        $settings = App::i()->settings();

        $settings->set('tg_enabled', Request::bool('tg_enabled') ? '1' : '0', false, 'telegram');
        $settings->set('tg_send_reminders', Request::bool('tg_send_reminders') ? '1' : '0', false, 'telegram');

        // Blank means "keep the stored token", so the masked field cannot wipe
        // a working bot.
        $token = trim((string) Request::post('tg_bot_token', ''));

        if ($token !== '') {
            $settings->set('tg_bot_token', $token, true, 'telegram');
        }

        $settings->refresh();
        AuditService::log('settings.telegram_updated', 'settings');

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/telegram'));
    }

    public function testTelegram(): void
    {
        $this->requireAdmin();

        $result = TelegramService::testConnection();

        Session::flash($result['ok'] ? 'success' : 'error', $result['message'] . ' ' . $result['hint']);
        Response::redirect(url('/admin/telegram'));
    }

    public function registerTelegramWebhook(): void
    {
        $this->requireAdmin();

        $result = TelegramService::registerWebhook();

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Response::redirect(url('/admin/telegram'));
    }

    /**
     * Check the Cloud API credentials without sending anything — it reads the
     * phone number back, so testing costs no conversation and messages nobody.
     */
    public function testCloud(): void
    {
        $this->requireAdmin();

        $result = MetaCloudService::testConnection();

        if ($result['ok']) {
            Session::flash('success', $result['message'] . ($result['hint'] !== '' ? ' ' . $result['hint'] : ''));
        } else {
            Session::flash('error', $result['message'] . ' ' . $result['hint']);
        }

        Response::redirect(url('/admin/whatsapp'));
    }

    /* --------------------------------------------------------------- Gemini */

    public function ai(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/ai', [
            'title'     => __('admin.ai'),
            'pageTitle' => __('admin.ai'),
            'settings'  => App::i()->settings(),
            'monthTokens' => (int) $db->value('SELECT COALESCE(SUM(total_tokens),0) FROM ai_logs WHERE created_at >= ?', [date('Y-m-01 00:00:00')], 0),
            'monthCost' => (float) $db->value('SELECT COALESCE(SUM(cost),0) FROM ai_logs WHERE created_at >= ?', [date('Y-m-01 00:00:00')], 0.0),
            'failures'  => $db->all('SELECT * FROM ai_logs WHERE success = 0 ORDER BY id DESC LIMIT 15'),
            'defaultPrompt' => '',
        ], 'layouts/admin');
    }

    public function saveAi(): void
    {
        $this->requireAdmin();
        $settings = App::i()->settings();

        $settings->set('gemini_model', (string) Request::post('gemini_model', 'gemini-2.0-flash'), false, 'ai');
        $settings->set('gemini_temperature', (string) (float) Request::post('gemini_temperature', 0.2), false, 'ai');
        $settings->set('gemini_max_tokens', (string) max(128, (int) Request::post('gemini_max_tokens', 1024)), false, 'ai');
        $settings->set('gemini_enabled', Request::bool('gemini_enabled') ? '1' : '0', false, 'ai');
        $settings->set('ai_fallback_enabled', Request::bool('ai_fallback_enabled') ? '1' : '0', false, 'ai');
        $settings->set('ai_cache_hours', (string) max(0, (int) Request::post('ai_cache_hours', 24)), false, 'ai');
        $settings->set('ai_monthly_budget_tokens', (string) max(0, (int) Request::post('ai_monthly_budget_tokens', 0)), false, 'ai');
        $settings->set('ai_cost_per_1k_input', (string) (float) Request::post('ai_cost_per_1k_input', 0.000075), false, 'ai');
        $settings->set('ai_cost_per_1k_output', (string) (float) Request::post('ai_cost_per_1k_output', 0.0003), false, 'ai');
        $settings->set('ai_prompt_template', (string) Request::post('ai_prompt_template', ''), false, 'ai');

        foreach (['gemini_api_key', 'gemini_api_key_2'] as $secret) {
            $value = (string) Request::post($secret, '');

            if ($value !== '') {
                $settings->set($secret, $value, true, 'ai');
            }
        }

        $settings->refresh();
        AuditService::log('settings.ai_updated', 'settings');

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/ai'));
    }

    public function testAi(): void
    {
        $admin = $this->requireAdmin();
        $settings = App::i()->settings();

        $text = (string) Request::post('text', 'કાલે સવારે 10 વાગ્યે ઓફિસ સ્ટાફને કોલ કરવાનો છે');

        $fakeUser = [
            'id'       => 0,
            'name'     => (string) $admin['name'],
            'language' => 'en',
            'timezone' => (string) $settings->get('default_timezone', 'Asia/Kolkata'),
        ];

        $apiKey = (string) $settings->get('gemini_api_key', '');

        if ($apiKey === '') {
            Session::flash('error', 'No Gemini API key configured.');
            Response::back(url('/admin/ai'));
        }

        $connection = GeminiService::testConnection($apiKey, (string) $settings->get('gemini_model', 'gemini-2.0-flash'));

        // Parse the sentence regardless, so the admin always sees what the
        // built-in parser would do — that is what runs whenever Gemini cannot.
        $parsed = \App\Services\FallbackParser::parse($text, $fakeUser);
        $preview = json_encode($parsed['items'][0] ?? [], JSON_UNESCAPED_UNICODE);

        if ($connection['ok']) {
            Session::flash('success', 'Gemini reachable. Built-in parser preview: ' . $preview);
        } elseif (!empty($connection['usable'])) {
            // Quota exhausted: the key is valid, so this is a warning, not a failure.
            Session::flash('warning', $connection['message'] . ' ' . $connection['hint']);
        } else {
            Session::flash('error', trim($connection['message'] . ' ' . $connection['hint']));
        }

        Response::redirect(url('/admin/ai'));
    }

    /* ------------------------------------------------------------ Templates */

    public function templates(): void
    {
        $this->requireAdmin();

        $rows = App::i()->db()->all('SELECT * FROM templates ORDER BY template_key ASC, lang ASC');

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['template_key']][(string) $row['lang']] = $row;
        }

        $this->view('admin/templates', [
            'title'     => __('admin.templates'),
            'pageTitle' => __('admin.templates'),
            'templates' => $grouped,
            'keys'      => TemplateService::keys(),
        ], 'layouts/admin');
    }

    public function saveTemplate(): void
    {
        $this->requireAdmin();

        $key = preg_replace('/[^a-z0-9_]/i', '', (string) Request::post('template_key', '')) ?: '';
        $lang = (string) Request::post('lang', 'gu');
        $body = (string) Request::post('body', '');

        if ($key === '' || !in_array($lang, ['gu', 'hi', 'en'], true) || trim($body) === '') {
            Session::flash('error', __('validation.required', ['field' => 'Template']));
            Response::back(url('/admin/templates'));
        }

        App::i()->db()->upsert('templates', [
            'template_key' => $key,
            'lang'         => $lang,
            'channel'      => 'whatsapp',
            'body'         => $body,
            'is_active'    => 1,
            'created_at'   => now_utc(),
        ], ['body', 'is_active']);

        TemplateService::flush();
        AuditService::log('template.updated', 'template', null, ['key' => $key, 'lang' => $lang]);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/templates'));
    }

    /* -------------------------------------------------------------- Content */

    public function content(): void
    {
        $this->requireAdmin();
        $db = App::i()->db();

        $this->view('admin/content', [
            'title'     => __('admin.content'),
            'pageTitle' => __('admin.content'),
            'pages'     => $db->all('SELECT * FROM pages ORDER BY slug'),
            'posts'     => $db->all('SELECT * FROM posts ORDER BY created_at DESC LIMIT 50'),
            'faqs'      => $db->all('SELECT * FROM faqs ORDER BY sort_order'),
            'messages'  => $db->all('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 30'),
            'settings'  => App::i()->settings(),
        ], 'layouts/admin');
    }

    public function savePage(): void
    {
        $this->requireAdmin();

        $slug = slugify((string) Request::post('slug', ''));

        if ($slug === 'n-a') {
            Session::flash('error', __('validation.required', ['field' => 'Slug']));
            Response::back(url('/admin/content'));
        }

        App::i()->db()->upsert('pages', [
            'slug'             => $slug,
            'title'            => mb_substr((string) Request::post('title', ''), 0, 190),
            'body'             => (string) Request::post('body', ''),
            'meta_title'       => Request::post('meta_title') ?: null,
            'meta_description' => Request::post('meta_description') ?: null,
            'lang'             => in_array((string) Request::post('lang', 'en'), ['gu', 'hi', 'en'], true) ? (string) Request::post('lang') : 'en',
            'is_published'     => Request::bool('is_published') ? 1 : 0,
            'created_at'       => now_utc(),
        ], ['title', 'body', 'meta_title', 'meta_description', 'lang', 'is_published']);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/content'));
    }

    public function savePost(): void
    {
        $this->requireAdmin();

        $title = (string) Request::post('title', '');
        $slug = slugify((string) (Request::post('slug') ?: $title));

        if ($title === '') {
            Session::flash('error', __('validation.required', ['field' => 'Title']));
            Response::back(url('/admin/content'));
        }

        App::i()->db()->upsert('posts', [
            'slug'             => $slug,
            'title'            => mb_substr($title, 0, 190),
            'excerpt'          => mb_substr((string) Request::post('excerpt', ''), 0, 500),
            'body'             => (string) Request::post('body', ''),
            'meta_title'       => Request::post('meta_title') ?: null,
            'meta_description' => Request::post('meta_description') ?: null,
            'lang'             => in_array((string) Request::post('lang', 'gu'), ['gu', 'hi', 'en'], true) ? (string) Request::post('lang') : 'gu',
            'is_published'     => Request::bool('is_published') ? 1 : 0,
            'published_at'     => now_utc(),
            'created_at'       => now_utc(),
        ], ['title', 'excerpt', 'body', 'meta_title', 'meta_description', 'lang', 'is_published']);

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/content'));
    }

    public function saveFaq(): void
    {
        $this->requireAdmin();

        $question = trim((string) Request::post('question', ''));

        if ($question === '') {
            Session::flash('error', __('validation.required', ['field' => 'Question']));
            Response::back(url('/admin/content'));
        }

        $db = App::i()->db();
        $faqId = (int) Request::post('id', 0);

        $data = [
            'question'     => mb_substr($question, 0, 300),
            'answer'       => (string) Request::post('answer', ''),
            'lang'         => in_array((string) Request::post('lang', 'gu'), ['gu', 'hi', 'en'], true) ? (string) Request::post('lang') : 'gu',
            'sort_order'   => (int) Request::post('sort_order', 0),
            'is_published' => Request::bool('is_published') ? 1 : 0,
        ];

        if ($faqId > 0) {
            $db->update('faqs', $data, 'id = :id', ['id' => $faqId]);
        } else {
            $db->insert('faqs', array_merge($data, ['created_at' => now_utc()]));
        }

        Session::flash('success', __('common.saved'));
        Response::redirect(url('/admin/content'));
    }
}
