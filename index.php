<?php

/**
 * Krishna Reminder — front controller.
 *
 * All web traffic (except /install, /api and static assets) enters here.
 */

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

$app = require __DIR__ . '/app/bootstrap.php';

/* ------------------------------------------------------------------ Install */

if (!$app->isInstalled()) {
    if (is_dir(__DIR__ . '/install')) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/install/');
        exit;
    }

    http_response_code(503);
    echo 'Krishna Reminder is not installed and the /install directory is missing.';
    exit;
}

/* -------------------------------------------------------------- Maintenance */

$path = Request::path();

if ($app->settings()->bool('maintenance_mode') && !str_starts_with($path, '/admin') && !str_starts_with($path, '/api/health')) {
    http_response_code(503);
    header('Retry-After: 300');
    require __DIR__ . '/app/views/errors/maintenance.php';
    exit;
}

/* ------------------------------------------------------------------- Locale */

Session::start();

// An admin can pin the whole site to one language; that beats everything else.
Lang::forceLocale((string) $app->settings()->get('force_language', ''));

$locale = (string) (Request::get('lang') ?: Session::get('locale') ?: '');

if ($locale === '' && Auth::check()) {
    $locale = (string) (Auth::user()['language'] ?? '');
}

if ($locale === '') {
    $locale = (string) $app->settings()->get('default_language', $app->config('app.locale', 'en'));
}

if (in_array((string) Request::get('lang'), Lang::SUPPORTED, true)) {
    Session::set('locale', (string) Request::get('lang'));
}

Lang::setLocale($locale);

/* ------------------------------------------------------------------ Routing */

$router = new Router();
require __DIR__ . '/app/routes.php';

$match = $router->match(Request::method(), $path);

if ($match === null) {
    // Distinguish 404 from 405 for correctness of API clients.
    $allowed = $router->methodsFor($path);

    if ($allowed !== []) {
        header('Allow: ' . implode(', ', $allowed));
        Response::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    Response::notFound();
}

/* --------------------------------------------------------------- Middleware */

foreach ($match['middleware'] as $middleware) {
    switch ($middleware) {
        case 'auth':
            Auth::requireUser();
            break;

        case 'admin':
            Auth::requireAdmin();
            break;

        case 'guest':
            if (Auth::check()) {
                Response::redirect($app->url('/client'));
            }
            break;

        case 'guest_admin':
            if (Auth::admin() !== null) {
                Response::redirect($app->url('/admin'));
            }
            break;

        case 'csrf':
            if (Request::isPost() || in_array(Request::method(), ['PUT', 'PATCH', 'DELETE'], true)) {
                Csrf::verifyOrFail();
            }
            break;
    }
}

/* ---------------------------------------------------------------- Dispatch */

[$controllerName, $action] = $match['handler'];
$controllerClass = 'App\\Controllers\\' . $controllerName;

if (!class_exists($controllerClass)) {
    throw new RuntimeException('Controller not found: ' . $controllerClass);
}

$controller = new $controllerClass();

if (!method_exists($controller, $action)) {
    throw new RuntimeException('Action not found: ' . $controllerClass . '::' . $action);
}

Response::securityHeaders();

echo (string) ($controller->{$action}(...array_values($match['params'])) ?? '');
