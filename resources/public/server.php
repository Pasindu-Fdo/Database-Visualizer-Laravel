<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/schema') {
    header('Content-Type: application/json');
    $file = getenv('DB_VIEWER_SCHEMA_FILE');
    if (!$file || !is_file($file)) { http_response_code(404); echo json_encode(['message' => 'Schema snapshot not found']); exit; }
    readfile($file); exit;
}
$target = __DIR__.($path === '/' ? '/index.html' : $path);
if (!str_starts_with(realpath($target) ?: '', realpath(__DIR__)) || !is_file($target)) { http_response_code(404); echo 'Not found'; exit; }
return false;
