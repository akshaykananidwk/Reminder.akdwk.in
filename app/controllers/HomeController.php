<?php

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\WhatsAppService;

/**
 * Public marketing site, legal pages, blog and SEO endpoints.
 */
class HomeController extends Controller
{
    /** City landing pages — cheap, high-intent local SEO. */
    private const CITIES = [
        'dwarka'    => ['Dwarka', 'દ્વારકા'],
        'jamnagar'  => ['Jamnagar', 'જામનગર'],
        'rajkot'    => ['Rajkot', 'રાજકોટ'],
        'ahmedabad' => ['Ahmedabad', 'અમદાવાદ'],
        'surat'     => ['Surat', 'સુરત'],
        'vadodara'  => ['Vadodara', 'વડોદરા'],
        'bhavnagar' => ['Bhavnagar', 'ભાવનગર'],
        'porbandar' => ['Porbandar', 'પોરબંદર'],
        'junagadh'  => ['Junagadh', 'જૂનાગઢ'],
        'gandhinagar' => ['Gandhinagar', 'ગાંધીનગર'],
    ];

    public function index(): void
    {
        $db = App::i()->db();
        $locale = Lang::locale();

        $this->view('public/home', [
            'title'           => (string) App::i()->settings()->get('site_name', 'Krishna Reminder') . ' — ' . __('app.tagline'),
            'metaDescription' => (string) App::i()->settings()->get('meta_description', __('landing.hero_subtitle')),
            'plans'           => $db->all('SELECT * FROM plans WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC'),
            'faqs'            => $this->faqsFor($locale),
            'testimonials'    => $this->testimonialsFor($locale),
            'cities'          => self::CITIES,
        ]);
    }

    public function features(): void
    {
        $this->view('public/features', [
            'title'           => __('landing.features_title') . ' · ' . __('app.name'),
            'metaDescription' => __('landing.hero_subtitle'),
        ]);
    }

    public function pricing(): void
    {
        $this->view('public/pricing', [
            'title'           => __('landing.pricing_title') . ' · ' . __('app.name'),
            'metaDescription' => __('landing.pricing_title'),
            'plans'           => App::i()->db()->all('SELECT * FROM plans WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC'),
            'faqs'            => $this->faqsFor(Lang::locale()),
        ]);
    }

    public function about(): void
    {
        $this->view('public/about', [
            'title'           => __('nav.about') . ' · ' . __('app.name'),
            'metaDescription' => __('app.company'),
        ]);
    }

    public function contact(): void
    {
        $this->view('public/contact', [
            'title'           => __('landing.contact_title') . ' · ' . __('app.name'),
            'metaDescription' => __('landing.contact_title'),
        ]);
    }

    public function submitContact(): void
    {
        if (!RateLimiter::attempt('contact_' . Request::ip(), 5, 3600)) {
            Session::flash('error', __('api.rate_limited'));
            Response::back(url('/contact'));
        }

        $data = Validator::make($_POST, [
            'name'    => 'required|string|max:120',
            'phone'   => 'nullable|string|max:20',
            'email'   => 'nullable|email|max:190',
            'message' => 'required|string|min:5|max:3000',
        ]);

        if ($data->fails()) {
            Session::flashInput($_POST);
            Session::flash('error', $data->firstError());
            Response::back(url('/contact'));
        }

        $clean = $data->validated();

        App::i()->db()->insert('contact_messages', [
            'name'       => (string) $clean['name'],
            'phone'      => normalize_phone((string) ($clean['phone'] ?? '')) ?: null,
            'email'      => $clean['email'] ?? null,
            'subject'    => mb_substr((string) Request::post('subject', 'Website enquiry'), 0, 190),
            'message'    => (string) $clean['message'],
            'ip'         => Request::ip(),
            'created_at' => now_utc(),
        ]);

        // Notify the owner immediately on WhatsApp.
        $adminNumber = (string) App::i()->settings()->get('alert_admin_number', '');

        if ($adminNumber !== '') {
            WhatsAppService::queue(
                $adminNumber,
                "📨 *New website enquiry*\n👤 " . $clean['name']
                . "\n📞 " . ($clean['phone'] ?? '-')
                . "\n✉️ " . ($clean['email'] ?? '-')
                . "\n\n" . mb_substr((string) $clean['message'], 0, 500),
                null,
                null,
                6
            );
        }

        Session::clearOldInput();
        Session::flash('success', __('common.saved'));
        Response::redirect(url('/contact'));
    }

    public function blog(): void
    {
        $posts = App::i()->db()->all(
            'SELECT * FROM posts WHERE is_published = 1 AND (published_at IS NULL OR published_at <= ?) ORDER BY COALESCE(published_at, created_at) DESC LIMIT 30',
            [now_utc()]
        );

        $this->view('public/blog', [
            'title'           => __('nav.blog') . ' · ' . __('app.name'),
            'metaDescription' => __('nav.blog'),
            'posts'           => $posts,
        ]);
    }

    public function post(string $slug): void
    {
        $db = App::i()->db();
        $post = $db->one('SELECT * FROM posts WHERE slug = ? AND is_published = 1', [$slug]);

        if ($post === null) {
            Response::notFound();
        }

        $db->query('UPDATE posts SET views = views + 1 WHERE id = ?', [(int) $post['id']]);

        $this->view('public/post', [
            'title'           => (string) ($post['meta_title'] ?: $post['title']),
            'metaDescription' => (string) ($post['meta_description'] ?: str_limit((string) $post['excerpt'], 160)),
            'post'            => $post,
        ]);
    }

    public function page(string $slug): void
    {
        $page = App::i()->db()->one('SELECT * FROM pages WHERE slug = ? AND is_published = 1', [$slug]);

        if ($page === null) {
            Response::notFound();
        }

        $this->view('public/page', [
            'title'           => (string) ($page['meta_title'] ?: $page['title']),
            'metaDescription' => (string) ($page['meta_description'] ?? ''),
            'page'            => $page,
        ]);
    }

    public function faq(): void
    {
        $this->view('public/faq', [
            'title'           => __('landing.faq_title') . ' · ' . __('app.name'),
            'metaDescription' => __('landing.faq_title'),
            'faqs'            => $this->faqsFor(Lang::locale()),
        ]);
    }

    public function download(): void
    {
        $this->view('public/download', [
            'title'           => __('landing.download_title') . ' · ' . __('app.name'),
            'metaDescription' => __('landing.download_body'),
            'apkUrl'          => (string) App::i()->settings()->get('apk_release_url', ''),
            'appVersion'      => (string) App::i()->settings()->get('app_version', '1.0.0'),
        ]);
    }

    public function city(string $city): void
    {
        $key = strtolower($city);

        if (!isset(self::CITIES[$key])) {
            Response::notFound();
        }

        [$english, $gujarati] = self::CITIES[$key];

        $this->view('public/city', [
            'title'           => 'WhatsApp Reminder App in ' . $english . ' — ' . __('app.name'),
            'metaDescription' => 'Krishna Reminder helps shops, offices and professionals in ' . $english
                . ' remember every task and payment. Send a WhatsApp message in Gujarati; your phone rings at the right time.',
            'cityEn'          => $english,
            'cityGu'          => $gujarati,
            'plans'           => App::i()->db()->all('SELECT * FROM plans WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC LIMIT 3'),
        ]);
    }

    /* ---------------------------------------------------------------- SEO */

    public function sitemap(): void
    {
        $db = App::i()->db();
        $base = App::i()->url();

        $urls = [
            ['loc' => $base . '/', 'priority' => '1.0', 'freq' => 'weekly'],
            ['loc' => $base . '/features', 'priority' => '0.8', 'freq' => 'monthly'],
            ['loc' => $base . '/pricing', 'priority' => '0.9', 'freq' => 'monthly'],
            ['loc' => $base . '/about', 'priority' => '0.5', 'freq' => 'yearly'],
            ['loc' => $base . '/contact', 'priority' => '0.6', 'freq' => 'yearly'],
            ['loc' => $base . '/faq', 'priority' => '0.7', 'freq' => 'monthly'],
            ['loc' => $base . '/download', 'priority' => '0.8', 'freq' => 'monthly'],
            ['loc' => $base . '/blog', 'priority' => '0.7', 'freq' => 'weekly'],
        ];

        foreach (array_keys(self::CITIES) as $slug) {
            $urls[] = ['loc' => $base . '/reminder-app/' . $slug, 'priority' => '0.7', 'freq' => 'monthly'];
        }

        foreach ($db->all('SELECT slug, updated_at FROM pages WHERE is_published = 1') as $page) {
            $urls[] = ['loc' => $base . '/p/' . $page['slug'], 'priority' => '0.4', 'freq' => 'yearly', 'lastmod' => $page['updated_at']];
        }

        foreach ($db->all('SELECT slug, updated_at FROM posts WHERE is_published = 1') as $post) {
            $urls[] = ['loc' => $base . '/blog/' . $post['slug'], 'priority' => '0.6', 'freq' => 'monthly', 'lastmod' => $post['updated_at']];
        }

        header('Content-Type: application/xml; charset=utf-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            echo "  <url>\n";
            echo '    <loc>' . e($url['loc']) . "</loc>\n";

            if (!empty($url['lastmod'])) {
                echo '    <lastmod>' . date('Y-m-d', strtotime((string) $url['lastmod'])) . "</lastmod>\n";
            }

            echo '    <changefreq>' . e($url['freq']) . "</changefreq>\n";
            echo '    <priority>' . e($url['priority']) . "</priority>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
        exit;
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /client/\nDisallow: /admin/\nDisallow: /install/\nDisallow: /api/\nDisallow: /uploads/\nDisallow: /cron.php\n\n";
        echo 'Sitemap: ' . App::i()->url('/sitemap.xml') . "\n";
        exit;
    }

    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');

        echo json_encode([
            'name'             => (string) App::i()->settings()->get('site_name', 'Krishna Reminder'),
            'short_name'       => 'Krishna',
            'description'      => __('app.tagline'),
            'start_url'        => App::i()->url('/client'),
            'scope'            => App::i()->url('/'),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#F7F8FB',
            'theme_color'      => '#1B3A6B',
            'lang'             => Lang::locale(),
            'icons'            => [
                ['src' => App::i()->url('/assets/img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => App::i()->url('/assets/img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
            'shortcuts' => [
                ['name' => __('common.add'), 'url' => App::i()->url('/client/reminders/create')],
                ['name' => __('nav.payments'), 'url' => App::i()->url('/client/payments')],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        exit;
    }

    public function offline(): void
    {
        $this->view('public/offline', ['title' => 'Offline'], 'layouts/auth');
    }

    /* ------------------------------------------------------------ Helpers */

    private function faqsFor(string $locale): array
    {
        $db = App::i()->db();

        $rows = $db->all('SELECT * FROM faqs WHERE is_published = 1 AND lang = ? ORDER BY sort_order ASC', [$locale]);

        if ($rows === []) {
            $rows = $db->all('SELECT * FROM faqs WHERE is_published = 1 ORDER BY sort_order ASC LIMIT 8');
        }

        return $rows;
    }

    private function testimonialsFor(string $locale): array
    {
        $db = App::i()->db();

        $rows = $db->all('SELECT * FROM testimonials WHERE is_published = 1 AND lang = ? ORDER BY sort_order ASC LIMIT 6', [$locale]);

        if ($rows === []) {
            $rows = $db->all('SELECT * FROM testimonials WHERE is_published = 1 ORDER BY sort_order ASC LIMIT 6');
        }

        return $rows;
    }
}
