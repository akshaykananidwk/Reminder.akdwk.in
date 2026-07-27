<?php

namespace App\Core;

/**
 * Application container and bootstrap.
 *
 * Holds the loaded configuration, exposes the shared PDO connection and the
 * key/value settings store, and configures error handling for the request.
 */
final class App
{
    private static ?App $instance = null;

    private array $config = [];
    private ?Database $db = null;
    private ?Settings $settings = null;
    private bool $installed = false;

    private function __construct()
    {
    }

    public static function boot(string $rootPath): App
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $app = new self();
        self::$instance = $app;

        $app->config['root'] = rtrim($rootPath, '/');

        $configFile = $app->config['root'] . '/config/config.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $app->config = array_merge($app->config, $loaded);
                $app->installed = is_file($app->config['root'] . '/config/install.lock');
            }
        }

        date_default_timezone_set($app->config('app.timezone', 'Asia/Kolkata'));
        mb_internal_encoding('UTF-8');
        setlocale(LC_ALL, 'en_IN.UTF-8', 'en_US.UTF-8', 'C');

        $app->configureErrorHandling();

        return $app;
    }

    public static function i(): App
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not booted.');
        }

        return self::$instance;
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function root(): string
    {
        return $this->config['root'];
    }

    /**
     * Dot-notation config reader: config('db.host').
     */
    public function config(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->config;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function db(): Database
    {
        if ($this->db === null) {
            $this->db = new Database(
                (string) $this->config('db.host', 'localhost'),
                (int) $this->config('db.port', 3306),
                (string) $this->config('db.name', ''),
                (string) $this->config('db.user', ''),
                (string) $this->config('db.pass', ''),
                (string) $this->config('db.charset', 'utf8mb4')
            );
        }

        return $this->db;
    }

    public function settings(): Settings
    {
        if ($this->settings === null) {
            $this->settings = new Settings($this->db());
        }

        return $this->settings;
    }

    /**
     * Base URL without trailing slash, falling back to the current host.
     */
    public function url(string $path = ''): string
    {
        $base = rtrim((string) $this->config('app.url', ''), '/');

        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    private function configureErrorHandling(): void
    {
        $debug = (bool) $this->config('app.debug', false);

        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        $logDir = $this->config['root'] . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        ini_set('error_log', $logDir . '/php-' . date('Y-m-d') . '.log');

        set_exception_handler(function (\Throwable $e): void {
            Logger::exception($e);

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, '[FATAL] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
                exit(1);
            }

            if (!headers_sent()) {
                http_response_code(500);
            }

            if ((bool) $this->config('app.debug', false)) {
                echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                $view = $this->root() . '/app/views/errors/500.php';
                if (is_file($view)) {
                    require $view;
                } else {
                    echo 'Internal server error.';
                }
            }
            exit(1);
        });

        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
