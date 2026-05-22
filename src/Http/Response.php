<?php

namespace Http;

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('APP_DEBUG') && APP_DEBUG && isset($_GET['pretty'])) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode($data, $flags);
        if ($encoded === false) {
            http_response_code(500);
            $encoded = json_encode(['error' => 'Failed to encode JSON response.'], $flags) ?: '{"error":"Failed to encode JSON response."}';
        }

        echo $encoded;
    }

    public static function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit();
    }
}
