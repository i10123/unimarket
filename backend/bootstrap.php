<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

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

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) return null;
    $query = db()->prepare('SELECT u.id, u.email, w.available, w.reserved
        FROM users u JOIN wallets w ON w.user_id = u.id WHERE u.id = ?');
    $query->execute([$_SESSION['user_id']]);
    $user = $query->fetch();
    if (!$user) unset($_SESSION['user_id']);
    return $user ?: null;
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// Ограничение по IP сохраняется на сервере и не сбрасывается удалением cookie.
function checkAuthLimit(): void
{
    $path = ROOT . '/storage/limits/' . hash('sha256', $_SERVER['REMOTE_ADDR']) . '.json';
    $file = fopen($path, 'c+');
    if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Не удалось проверить лимит');
    $limit = json_decode(stream_get_contents($file), true) ?: ['until' => time() + 900, 'count' => 0];
    if ($limit['until'] <= time()) $limit = ['until' => time() + 900, 'count' => 0];
    if ($limit['count'] >= 20) {
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
