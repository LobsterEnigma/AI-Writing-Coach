<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Controllers\ApiController;
use Controllers\FrontendController;
use Http\Response;
use Support\OpenSourceAccess;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rootApi = isset($_GET['api']) ? trim((string) $_GET['api']) : '';

if (str_starts_with($path, '/api/') || ($path === '/' && $rootApi !== '')) {
    // Keep API responses machine-readable even when PHP notices/warnings exist in production.
    ini_set('display_errors', '0');
    header('X-AIWC-API: 1');
}

try {
    switch ($path) {
        case '/':
            if ($rootApi === 'analyze') {
                if ($method !== 'POST') {
                    Response::json(['error' => 'Method Not Allowed'], 405);
                    break;
                }
                ApiController::analyze($config);
                break;
            }

            if ($rootApi === 'word-detail') {
                if ($method !== 'POST') {
                    Response::json(['error' => 'Method Not Allowed'], 405);
                    break;
                }
                ApiController::wordDetail($config);
                break;
            }

            if ($method === 'POST') {
                FrontendController::unlock($config);
                break;
            }
            FrontendController::home($config);
            break;

        case '/unlock':
            if ($method === 'POST') {
                FrontendController::unlock($config);
            } else {
                http_response_code(405);
                echo 'Method Not Allowed';
            }
            break;

        case '/api/analyze':
            if ($method !== 'POST') {
                Response::json(['error' => 'Method Not Allowed'], 405);
                break;
            }
            ApiController::analyze($config);
            break;

        case '/api/word-detail':
            if ($method !== 'POST') {
                Response::json(['error' => 'Method Not Allowed'], 405);
                break;
            }
            ApiController::wordDetail($config);
            break;

        default:
            if (str_starts_with($path, '/api/')) {
                Response::json(['error' => 'Not Found'], 404);
            } else {
                if (OpenSourceAccess::isEnabled($config) && ! OpenSourceAccess::isGranted($config)) {
                    Response::redirect('/');
                    return;
                }
                http_response_code(404);
                echo 'Not Found';
            }
    }
} catch (Throwable $e) {
    if (str_starts_with($path, '/api/') || ($path === '/' && $rootApi !== '')) {
        $status = 500;
        $code = (int) $e->getCode();
        if ($code >= 400 && $code < 600) {
            $status = $code;
        }
        Response::json([
            'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
        ], $status);
    } else {
        throw $e;
    }
}
