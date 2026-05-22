<?php

namespace Support;

class View
{
    public static function render(string $template, array $data = []): void
    {
        $basePath = dirname(__DIR__, 1);
        $path = $basePath . '/../templates/' . $template . '.php';
        if (! file_exists($path)) {
            http_response_code(404);
            echo 'Template not found';
            return;
        }

        extract($data);
        include $path;
    }
}
