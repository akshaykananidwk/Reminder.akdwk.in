<?php

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\GeminiService;
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
            'queue'     => $db->all('SELECT * FROM wa_outbound_queue ORDER BY id DESC LIMIT 25'),
            'log'       => $db->all('SELECT * FROM wa_outbound_log ORDER BY id DESC LIMIT 25'),
            'inbound'   => $db->all('SELECT * FROM wa_inbound_raw ORDER BY id DESC LIMIT 25'),
            'unknown'   => $db->all('SELECT * FROM unknown_inbound ORDER BY last_seen_at DESC LIMIT 25'),
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

        foreach (['wa_api_key', 'wa_session_id'] as $secret) {
            $value = (string) Request::post($secret, '');

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

        Session::flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Sent. Gateway said: ' . str_limit($result['response'], 200)
            : 'Failed: ' . str_limit($result['response'], 300));

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
            'language' => 'gu',
            'timezone' => (string) $settings->get('default_timezone', 'Asia/Kolkata'),
        ];

        $apiKey = (string) $settings->get('gemini_api_key', '');

        if ($apiKey === '') {
            Session::flash('error', 'No Gemini API key configured.');
            Response::back(url('/admin/ai'));
        }

        $connection = GeminiService::testConnection($apiKey, (string) $settings->get('gemini_model', 'gemini-2.0-flash'));

        if (!$connection['ok']) {
            Session::flash('error', $connection['message']);
            Response::back(url('/admin/ai'));
        }

        // Parse a real sentence so the admin sees the actual structured output.
        $parsed = \App\Services\FallbackParser::parse($text, $fakeUser);

        Session::flash('success', 'Gemini reachable. Fallback parser preview: '
            . json_encode($parsed['items'][0] ?? [], JSON_UNESCAPED_UNICODE));

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
