<?php
declare(strict_types=1);

// Общие функции backend: подключение к БД, сессия, текущий пользователь, JSON-ответ.

const ROOT = __DIR__ . '/..';

// Одно подключение PDO на запрос (static сохраняет его между вызовами).
function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $config = require ROOT . '/config/local.php';
        $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function startSession(): void
{
    session_save_path(ROOT . '/storage/sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('unimarket_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

// Вошедший пользователь с остатками кошелька или null для гостя.
function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $query = db()->prepare(
        'SELECT u.id, u.login, w.available, w.reserved
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        WHERE u.id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $user = $query->fetch();

    // Аккаунт удалён из БД — сессия больше не действительна.
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

const AUTH_ATTEMPTS = 20;
const AUTH_WINDOW_SECONDS = 15 * 60;

// Не больше 20 попыток входа/регистрации за 15 минут с одного IP.
// Счётчик хранится в файле на сервере, поэтому удаление cookie его не сбрасывает.
function checkAuthLimit(): void
{
    $path = ROOT . '/storage/limits/' . hash('sha256', $_SERVER['REMOTE_ADDR']) . '.json';
    $file = fopen($path, 'c+');
    // Блокировка файла: два одновременных запроса не потеряют попытку.
    if (!$file || !flock($file, LOCK_EX)) {
        throw new RuntimeException('Не удалось проверить лимит');
    }

    $limit = json_decode(stream_get_contents($file), true);
    if (!$limit || $limit['until'] <= time()) {
        $limit = ['until' => time() + AUTH_WINDOW_SECONDS, 'count' => 0];
    }

    if ($limit['count'] >= AUTH_ATTEMPTS) {
        fclose($file);
        header('Retry-After: ' . ($limit['until'] - time()));
        respond(['error' => 'Слишком много попыток. Попробуй через 15 минут.'], 429);
    }

    $limit['count']++;
    rewind($file);
    ftruncate($file, 0);
    fwrite($file, json_encode($limit, JSON_THROW_ON_ERROR));
    fclose($file);
}
