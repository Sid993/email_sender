<?php

declare(strict_types=1);

use App\Config;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\UploadController;
use App\Http\Request;
use App\Http\Response;

// PHP built-in server: let it serve static assets directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__));

ini_set('display_errors', Config::bool('APP_DEBUG') ? '1' : '0');
error_reporting(E_ALL);

$routes = [
    'GET /' => [AppController::class, 'home'],
    'GET /login' => [AuthController::class, 'showLogin'],
    'POST /login' => [AuthController::class, 'login'],
    'GET /register' => [AuthController::class, 'showRegister'],
    'POST /register' => [AuthController::class, 'register'],
    'POST /logout' => [AuthController::class, 'logout'],

    'GET /api/state' => [AppController::class, 'state'],
    'POST /api/config' => [AppController::class, 'saveConfig'],
    'POST /api/preview' => [AppController::class, 'preview'],
    'POST /api/test-send' => [AppController::class, 'testSend'],

    'POST /api/upload/chunk' => [UploadController::class, 'chunk'],
    'POST /api/upload/remove' => [UploadController::class, 'remove'],
    'GET /api/upload/skipped' => [UploadController::class, 'invalidRows'],

    'POST /api/campaign/start' => [CampaignController::class, 'start'],
    'POST /api/campaign/pause' => [CampaignController::class, 'pause'],
    'POST /api/campaign/resume' => [CampaignController::class, 'resume'],
    'POST /api/campaign/cancel' => [CampaignController::class, 'cancel'],
    'POST /api/campaign/reset' => [CampaignController::class, 'reset'],
    'GET /api/campaign/status' => [CampaignController::class, 'status'],
    'GET /api/campaign/failed' => [CampaignController::class, 'failed'],
    'GET /api/campaign/failed/export' => [CampaignController::class, 'export'],
];

$key = Request::method() . ' ' . Request::path();
$route = $routes[$key] ?? null;

try {
    if ($route === null) {
        if (str_starts_with(Request::path(), '/api/')) {
            Response::error('Not found', 404);
        }
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    [$class, $method] = $route;
    (new $class())->$method();
} catch (\Throwable $e) {
    error_log('[bulk-mailer] ' . $e);
    $msg = Config::bool('APP_DEBUG') ? $e->getMessage() : 'Something went wrong. Please try again.';
    if (str_starts_with(Request::path(), '/api/')) {
        Response::error($msg, 500);
    }
    http_response_code(500);
    echo htmlspecialchars($msg);
}
