<?php

/**
 * Bootstrap shared by the web front controller, the API and every CLI cron job.
 * No composer required — a small PSR-4 style autoloader covers App\*.
 */

if (!defined('KR_ROOT')) {
    define('KR_ROOT', dirname(__DIR__));
    define('KR_START', microtime(true));
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, 4));

    // App\Core\App          -> app/core/App.php
    // App\Services\FooBar   -> app/services/FooBar.php
    $parts = explode('/', $relative);
    $file = array_pop($parts);
    $dir = strtolower(implode('/', $parts));

    $path = KR_ROOT . '/app/' . ($dir === '' ? '' : $dir . '/') . $file . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

require_once KR_ROOT . '/app/helpers/functions.php';

$app = \App\Core\App::boot(KR_ROOT);

return $app;
