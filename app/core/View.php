<?php

namespace App\Core;

/**
 * Plain PHP templating with layout support.
 */
final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $file = App::i()->root() . '/app/views/' . ltrim($template, '/') . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        $data = array_merge(self::$shared, $data);

        $content = self::capture($file, $data);

        if ($layout === null) {
            return $content;
        }

        $layoutFile = App::i()->root() . '/app/views/' . ltrim($layout, '/') . '.php';

        if (!is_file($layoutFile)) {
            return $content;
        }

        return self::capture($layoutFile, array_merge($data, ['content' => $content]));
    }

    public static function display(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        echo self::render($template, $data, $layout);
    }

    private static function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    public static function partial(string $template, array $data = []): void
    {
        echo self::render($template, $data, null);
    }
}
