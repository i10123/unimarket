<?php
declare(strict_types=1);

// Общие функции backend: БД, сессия, текущий пользователь, JSON-ответы, защита POST.

const ROOT = __DIR__ . '/..';

// Предупреждения PHP не показываем в ответе: они ломают JSON и раскрывают пути. Они пишутся в лог.
ini_set('display_errors', '0');

// Имя для показа другим: имя и фамилия, что заполнено, иначе «User22». Таблица users — с алиасом u.
const DISPLAY_NAME = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), CONCAT('User', u.id))";

// Классы лежат в файлах с тем же именем: Market.php, Deals.php и т. д.
spl_autoload_register(fn(string $class) => require __DIR__ . "/$class.php");
require __DIR__ . '/validation.php';

// Ошибка для пользователя: runApi() превращает её в JSON с нужным HTTP-статусом.
final class ApiError extends Exception
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

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

// Всё внутри функции сохраняется целиком или не сохраняется вовсе.
function transaction(callable $action): mixed
{
    db()->beginTransaction();
    try {
        $result = $action();
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

// Папки storage не хранятся в Git (после клонирования или очистки их нет), поэтому
// создаём их при первом обращении. Без папки сессий session_start() выдаёт ошибки.
function storageDir(string $name): string
{
    $path = ROOT . '/storage/' . $name;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Не удалось создать папку ' . $path);
    }
    return $path;
}

function startSession(): void
{
    session_save_path(storageDir('sessions'));
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

// Новый идентификатор сессии после входа или выхода защищает от подмены сессии.
function renewSession(): void
{
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Вошедший пользователь с остатками кошелька или null для гостя.
// Заодно закрываем покупки с истёкшим сроком: планировщика нет, остатки считаем при обращении.
function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    (new Deals(db()))->settleOverdue();
    $query = db()->prepare(
        'SELECT u.id, u.login, ' . DISPLAY_NAME . ' AS display_name, w.available, w.reserved
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

function requireUser(): array
{
    return currentUser() ?? throw new ApiError('Нужно войти в аккаунт.', 401);
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// Данные POST-запроса: JSON или форма с файлами. Токен из заголовка должен совпасть
// с токеном в сессии: так чужой сайт не отправит запрос от имени пользователя.
function postInput(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        throw new ApiError('Метод не поддерживается.', 405);
    }
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new ApiError('Обнови страницу и повтори действие.', 403);
    }
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        // Если запрос больше post_max_size, PHP отбрасывает и поля, и файлы: объясняем причину.
        if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new ApiError('Файлы слишком большие: сервер принимает не больше ' . ini_get('post_max_size') . ' за один раз.', 413);
        }
        return $_POST;
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        throw new ApiError('Слишком большой запрос.', 413);
    }
    $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    return is_array($input) ? $input : throw new ApiError('Некорректные данные.');
}

// Обёртка каждого API: сессия и единая обработка ошибок. Подробности сбоя — только в лог.
function runApi(callable $handler): void
{
    try {
        startSession();
        $handler();
    } catch (ApiError $error) {
        respond(['error' => $error->getMessage()], $error->status);
    } catch (JsonException) {
        respond(['error' => 'Некорректный JSON.'], 400);
    } catch (PDOException $error) {
        error_log((string) $error);
        respond(['error' => 'База данных недоступна. Попробуй позже.'], 503);
    } catch (Throwable $error) {
        error_log((string) $error);
        respond(['error' => 'Не удалось выполнить действие. Попробуй позже.'], 500);
    }
}

const AUTH_ATTEMPTS = 60;
const AUTH_WINDOW_SECONDS = 15 * 60;

// Не больше 60 попыток входа, регистрации или смены пароля за 15 минут с одного IP.
// Счётчик хранится в файле на сервере, поэтому удаление cookie его не сбрасывает.
function checkAuthLimit(): void
{
    $path = storageDir('limits') . '/' . hash('sha256', $_SERVER['REMOTE_ADDR']) . '.json';
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
        throw new ApiError('Слишком много попыток. Попробуй через 15 минут.', 429);
    }

    $limit['count']++;
    rewind($file);
    ftruncate($file, 0);
    fwrite($file, json_encode($limit, JSON_THROW_ON_ERROR));
    fclose($file);
}
