<?php
declare(strict_types=1);

// Локальный PHP-сервер отдаёт только известные страницы и публичные ресурсы.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if ($path === '/api/auth') {
    require __DIR__ . '/backend/auth.php';
    return true;
}
$pages = ['/' => '/index.html', '/index.html' => '/index.html',
    '/pages/auth/login.html' => '/pages/auth/login.html', '/pages/catalog.html' => '/pages/catalog.html'];
if (isset($pages[$path])) {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }
    require __DIR__ . '/backend/bootstrap.php';
    startSession();
    header('Cache-Control: no-store');
    $loggedIn = isset($_SESSION['user_id']);
    if (($path === '/pages/catalog.html' && !$loggedIn) || ($path !== '/pages/catalog.html' && $loggedIn)) {
        header('Location: ' . ($loggedIn ? '/pages/catalog.html' : '/pages/auth/login.html'));
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile(__DIR__ . $pages[$path]);
    return true;
}
if (preg_match('~^/assets/(css|js|icons|fonts)/[a-zA-Z0-9_.-]+\.(css|js|svg|ttf|woff2?)$~D', $path)
    && is_file(__DIR__ . $path)) return false;
http_response_code(404);
echo 'Страница не найдена.';
