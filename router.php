<?php
declare(strict_types=1);

// Маршрутизатор: .htaccess направляет сюда все запросы, кроме файлов в assets.
// Отдаём только известные страницы и API. Всё остальное (config, backend, storage…) — 404.

const LOGIN_PAGE = '/pages/auth/login.html';
const CATALOG_PAGE = '/pages/catalog.html';

// Страницы только для гостя: после входа они ведут в каталог.
const GUEST_PAGES = ['/index.html', LOGIN_PAGE, '/pages/auth/register.html', '/pages/auth/recovery.html'];

// Страницы только после входа: гостя отправляем на вход.
const USER_PAGES = [
    CATALOG_PAGE,
    '/pages/material.html',
    '/pages/publish.html',
    '/pages/favorites.html',
    '/pages/author.html',
    '/pages/account.html',
    '/pages/purchases.html',
    '/pages/sales.html',
    '/pages/settings.html',
];

// Адрес /api/<имя> => backend/api/<имя>.php
const API = ['auth', 'market', 'deals', 'account', 'download'];

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$path = $path === '/' ? '/index.html' : $path;

// Заголовки безопасности для всех ответов.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
    . "img-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; "
    . "base-uri 'none'; form-action 'self'");

if (preg_match('#^/api/([a-z]+)$#D', $path, $match) && in_array($match[1], API, true)) {
    require __DIR__ . "/backend/api/$match[1].php";
    exit;
}

if (in_array($path, [...GUEST_PAGES, ...USER_PAGES], true)) {
    servePage($path);
    exit;
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

    $loggedIn = isset($_SESSION['user_id']);
    $isGuestPage = in_array($path, GUEST_PAGES, true);
    if ($isGuestPage && $loggedIn) {
        redirect(CATALOG_PAGE);
    }
    if (!$isGuestPage && !$loggedIn) {
        redirect(LOGIN_PAGE);
    }

    header('Content-Type: text/html; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile(__DIR__ . $path);
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}
