<?php
declare(strict_types=1);

// Маршрутизатор встроенного PHP-сервера: отдаёт только известные страницы,
// API и публичные ресурсы. Всё остальное (config, backend, storage…) — 404.

const LOGIN_PAGE = '/pages/auth/login.html';
const CATALOG_PAGE = '/pages/catalog.html';

// Адрес в браузере => файл в проекте.
const PAGES = [
    '/' => '/index.html',
    '/index.html' => '/index.html',
    LOGIN_PAGE => LOGIN_PAGE,
    CATALOG_PAGE => CATALOG_PAGE,
];

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// Заголовки безопасности для всех ответов.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
    . "img-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; "
    . "base-uri 'none'; form-action 'self'");

if ($path === '/api/auth') {
    require __DIR__ . '/backend/auth.php';
    return true;
}

if (isset(PAGES[$path])) {
    servePage($path);
    return true;
}

// CSS, JS, иконки и шрифты отдаёт сам встроенный сервер (return false).
$isAsset = preg_match('~^/assets/(css|js|icons|fonts)/[a-zA-Z0-9_.-]+\.(css|js|svg|ttf|woff2?)$~D', $path);
if ($isAsset && is_file(__DIR__ . $path)) {
    return false;
}

http_response_code(404);
echo 'Страница не найдена.';

function servePage(string $path): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }

    require __DIR__ . '/backend/bootstrap.php';
    startSession();
    header('Cache-Control: no-store');

    // Каталог — только после входа; лендинг и формы — только для гостя.
    $loggedIn = isset($_SESSION['user_id']);
    $isCatalog = $path === CATALOG_PAGE;
    if ($isCatalog && !$loggedIn) {
        redirect(LOGIN_PAGE);
    }
    if (!$isCatalog && $loggedIn) {
        redirect(CATALOG_PAGE);
    }

    header('Content-Type: text/html; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile(__DIR__ . PAGES[$path]);
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}
