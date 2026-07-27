<?php

namespace App\Core;

/**
 * Minimal pattern router: `/client/reminders/{id}/edit` style placeholders,
 * with optional per-route middleware names resolved by the kernel.
 */
class Router
{
    /** @var array<string, array<int, array{pattern: string, regex: string, params: array, handler: mixed, middleware: array}>> */
    private array $routes = [];

    private array $groupStack = [];

    public function get(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    public function patch(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    public function any(string $pattern, mixed $handler, array $middleware = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->add($method, $pattern, $handler, $middleware);
        }
    }

    /**
     * Group routes under a URI prefix and a shared middleware stack.
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $pattern, mixed $handler, array $middleware): void
    {
        $prefix = '';
        $stackMiddleware = [];

        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $stackMiddleware = array_merge($stackMiddleware, $group['middleware']);
        }

        $full = '/' . trim($prefix . $pattern, '/');
        if ($full === '/') {
            $full = '/';
        }

        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(:([^}]+))?\}/', function ($m) use (&$params) {
            $params[] = $m[1];

            return '(' . ($m[3] ?? '[^/]+') . ')';
        }, $full);

        $this->routes[$method][] = [
            'pattern'    => $full,
            'regex'      => '#^' . $regex . '$#u',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($stackMiddleware, $middleware),
        ];
    }

    /**
     * @return array{handler: mixed, params: array, middleware: array}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);

                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? null;
                }

                return [
                    'handler'    => $route['handler'],
                    'params'     => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

        return null;
    }

    public function methodsFor(string $path): array
    {
        $methods = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path)) {
                    $methods[] = $method;
                    break;
                }
            }
        }

        return $methods;
    }
}
