<?php
// Only used to run `php -S` locally for testing. Apache (cPanel/production) uses
// public/.htaccess instead - this file is not needed there and is not deployed.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/public/index.php';
    return true;
}

if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
    return true;
}

$file = __DIR__ . '/public' . $path;
if (file_exists($file) && !is_dir($file)) {
    return false; // let the built-in server serve the static file directly with correct mime type
}

http_response_code(404);
echo 'Not found';
return true;
