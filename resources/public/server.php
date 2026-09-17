<?php
/**
 * Router script for the Database Visualizer PHP built-in server.
 *
 * Endpoints
 * ---------
 * GET /api/schema   – returns the pre-built schema JSON snapshot
 * GET /api/env      – returns app_url and tracer config for the frontend
 * GET /api/trace    – proxies a request to the Laravel app and returns the query trace
 * *   /*            – serves static files from this directory
 */

// ── Helpers ──────────────────────────────────────────────────────────────────

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────────────

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ── GET /api/schema ──────────────────────────────────────────────────────────
if ($path === '/api/schema') {
    $file = getenv('DB_VIEWER_SCHEMA_FILE');
    if (!$file || !is_file($file)) {
        json_response(['message' => 'Schema snapshot not found'], 404);
    }
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    readfile($file);
    exit;
}

// ── GET /api/env ─────────────────────────────────────────────────────────────
if ($path === '/api/env') {
    $file = getenv('DB_VIEWER_ENV_FILE');
    if (!$file || !is_file($file)) {
        json_response(['app_url' => 'http://127.0.0.1:8000', 'tracer_enabled' => true]);
    }
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    readfile($file);
    exit;
}

// ── GET /api/trace ────────────────────────────────────────────────────────────
if ($path === '/api/trace') {
    // Require QueryTracer — resolve path relative to this file.
    $tracerClass = __DIR__ . '/../../src/QueryTracer.php';

    if (!is_file($tracerClass)) {
        json_response(['ok' => false, 'error' => 'QueryTracer class not found.'], 500);
    }

    require_once $tracerClass;

    // Read env to get app_url.
    $envFile = getenv('DB_VIEWER_ENV_FILE');
    $env     = ($envFile && is_file($envFile)) ? json_decode(file_get_contents($envFile), true) : [];
    $appUrl  = $_GET['app_url'] ?? $env['app_url'] ?? 'http://127.0.0.1:8000';

    $tracePath = $_GET['url']    ?? '/';
    $method    = strtoupper($_GET['method'] ?? 'GET');

    // Read request body for POST/PUT/PATCH.
    $body = '';
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $body = file_get_contents('php://input') ?: '';
    }

    // Build extra headers to forward (Content-Type, Authorization, etc.).
    $extraHeaders = [];
    if (!empty($_SERVER['HTTP_CONTENT_TYPE'])) {
        $extraHeaders[] = 'Content-Type: ' . $_SERVER['HTTP_CONTENT_TYPE'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $extraHeaders[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
    }

    // Forward cookies so authenticated routes can be traced.
    // The frontend passes the user's browser cookies as the X-Tracer-Cookies header.
    $cookies = $_SERVER['HTTP_X_TRACER_COOKIES'] ?? '';

    $tracer = new \DatabaseVisualizer\Laravel\QueryTracer(
        appUrl: $appUrl,
        timeoutSeconds: (int) ($env['timeout_seconds'] ?? 30),
    );

    $result = $tracer->trace($tracePath, $method, $extraHeaders, $body, $cookies);
    json_response($result, $result['ok'] ? 200 : 502);
}

// ── Static files ──────────────────────────────────────────────────────────────
$target = __DIR__ . ($path === '/' ? '/index.html' : $path);
if (!str_starts_with(realpath($target) ?: '', realpath(__DIR__)) || !is_file($target)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

return false;
