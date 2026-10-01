<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'message' => 'PHP is reachable',
    'php' => PHP_VERSION,
    'time' => date('c'),
    'script' => __FILE__,
    'docroot' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
]);
