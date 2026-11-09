<?php
declare(strict_types=1);

// API доступа: /api/auth
//   GET  — текущий пользователь (или null) и CSRF-токен;
//   POST — действие login, check_login, register или logout в JSON-теле.

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AuthHandler.php';

try {
    startSession();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        respond(['user' => currentUser(), 'csrf' => $_SESSION['csrf']]);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(['error' => 'Метод не поддерживается.'], 405);
    }

    // Токен из заголовка должен совпасть с токеном в сессии: так чужой сайт
    // не отправит запрос от имени пользователя.
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        respond(['error' => 'Обнови страницу и повтори действие.'], 403);
    }

    $input = readJsonBody();
    $action = $input['action'] ?? '';

    if ($action === 'logout') {
        $_SESSION = [];
        renewSession();
        respond(['ok' => true]);
    }
    if (!in_array($action, ['login', 'check_login', 'register'], true)) {
        respond(['error' => 'Неизвестное действие.'], 400);
    }

    checkAuthLimit();

    // Логин без учёта регистра: Denis и denis — один аккаунт.
    $login = is_string($input['login'] ?? null) ? strtolower(trim($input['login'])) : '';
    $password = $input['password'] ?? null;
    if (!isValidLogin($login)) {
        respond(['error' => 'Логин не подходит: нужно от 3 до 32 символов, только латинские буквы, цифры, точка, дефис и подчёркивание.'], 422);
    }

    $auth = new AuthHandler(db());

    // Первый шаг регистрации: аккаунт ещё не создаётся, только проверяем логин.
    if ($action === 'check_login') {
        if ($auth->loginExists($login)) {
            respond(['error' => 'Этот логин уже занят.'], 409);
        }
        respond(['ok' => true]);
    }

    if (!isValidPassword($password)) {
        respond(['error' => 'Пароль: от 8 символов, не более 256 байт.'], 422);
    }
    if ($action === 'register' && ($input['rules'] ?? false) !== true) {
        respond(['error' => 'Нужно принять правила обмена материалами.'], 422);
    }

    if ($action === 'register') {
        $userId = $auth->register($login, $password, readProfile($input));
    } else {
        $userId = $auth->login($login, $password);
    }
    if ($userId === null) {
        respond(['error' => 'Неверный логин или пароль.'], 401);
    }

    // Новый идентификатор сессии после входа защищает от подмены сессии.
    renewSession();
    $_SESSION['user_id'] = $userId;
    respond(
        ['user' => currentUser(), 'csrf' => $_SESSION['csrf']],
        $action === 'register' ? 201 : 200
    );
} catch (JsonException) {
    respond(['error' => 'Некорректный JSON.'], 400);
} catch (PDOException $error) {
    // 1062 — нарушение UNIQUE: такой логин уже есть.
    if (($error->errorInfo[1] ?? null) === 1062) {
        respond(['error' => 'Этот логин уже занят.'], 409);
    }
    error_log((string) $error);
    respond(['error' => 'База данных недоступна. Попробуй позже.'], 503);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(['error' => 'Не удалось выполнить действие. Попробуй позже.'], 500);
}

// Второй шаг регистрации: все поля необязательны, пустое значение сохраняется как NULL.
function readProfile(array $input): array
{
    $firstName = cleanText($input['first_name'] ?? '');
    $lastName = cleanText($input['last_name'] ?? '');
    $email = cleanText($input['email'] ?? '');
    $course = cleanText($input['course'] ?? '');

    if (!isValidName($firstName) || !isValidName($lastName)) {
        respond(['error' => 'Имя и фамилия: до 60 букв.'], 422);
    }
    if ($email !== null && (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        respond(['error' => 'Проверь адрес почты.'], 422);
    }
    if ($course !== null && preg_match('/^[1-6]$/D', $course) !== 1) {
        respond(['error' => 'Курс: от 1 до 6.'], 422);
    }

    return [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'course' => $course === null ? null : (int) $course,
    ];
}

function cleanText(mixed $value): ?string
{
    $text = is_string($value) ? trim($value) : '';
    return $text === '' ? null : $text;
}

function isValidName(?string $name): bool
{
    return $name === null || preg_match("/^[\p{L}][\p{L} '-]{0,59}$/uD", $name) === 1;
}

function readJsonBody(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        respond(['error' => 'Слишком большой запрос.'], 413);
    }
    $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        respond(['error' => 'Некорректные данные.'], 422);
    }
    return $input;
}

function renewSession(): void
{
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function isValidLogin(string $login): bool
{
    return preg_match('/^[a-z0-9_.-]{3,32}$/D', $login) === 1;
}

function isValidPassword(mixed $password): bool
{
    return is_string($password)
        && !str_contains($password, "\0")
        && strlen($password) <= 256
        && mb_strlen($password) >= 8;
}
