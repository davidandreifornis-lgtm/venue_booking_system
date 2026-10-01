<?php
/**
 * Dev: php -S 127.0.0.1:8080 router.php
 * Then open http://127.0.0.1:8080/api/health
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/backend/api/index.php';
    return true;
}

// Allow direct backend/api/*.php
if (preg_match('#^/backend/api/[^/]+\.php$#', $uri)) {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        require $file;
        return true;
    }
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

if ($uri === '/' || $uri === '') {
    header('Location: /pages/login.html');
    return true;
}

http_response_code(404);
header('Content-Type: text/plain');
echo "Not found: {$uri}\n";
echo "Try: /api/health  or  /backend/api/ping.php  or  /pages/login.html\n";
return true;
