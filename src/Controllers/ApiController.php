<?php

namespace Controllers;

use Exception;
use Http\Request;
use Http\Response;
use Services\EssayAnalyzer;
use Support\OpenSourceAccess;

class ApiController
{
    private static function guardAccess(array $config): bool
    {
        if (! OpenSourceAccess::isGranted($config)) {
            Response::json(['error' => 'Authentication required.'], 401);
            return false;
        }
        return true;
    }

    public static function analyze(array $config): void
    {
        if (! self::guardAccess($config)) {
            return;
        }

        $payload = Request::jsonBody();
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            Response::json(['error' => 'Essay text is required.'], 422);
            return;
        }

        $settings = $config['open_source']['ai_settings'] ?? [];
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? ''));
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            Response::json(['error' => 'AI settings are missing. Please set open_source.ai_settings in config/app.php.'], 500);
            return;
        }

        try {
            $analyzer = new EssayAnalyzer($config);
            $result = $analyzer->analyze($text, []);
        } catch (Exception $e) {
            Response::json(['error' => $e->getMessage()], 500);
            return;
        }

        Response::json([
            'data' => $result,
        ]);
    }

    public static function wordDetail(array $config): void
    {
        if (! self::guardAccess($config)) {
            return;
        }

        $payload = Request::jsonBody();
        $word = trim((string) ($payload['word'] ?? ''));
        if ($word === '') {
            Response::json(['error' => 'Word is required.'], 422);
            return;
        }

        $settings = $config['open_source']['ai_settings'] ?? [];
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? ''));
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            Response::json(['error' => 'AI settings are missing. Please set open_source.ai_settings in config/app.php.'], 500);
            return;
        }

        $analyzer = new EssayAnalyzer($config);

        try {
            $details = $analyzer->wordDetail($word);
        } catch (Exception $e) {
            Response::json(['error' => $e->getMessage()], 500);
            return;
        }

        Response::json(['data' => $details]);
    }
}
