<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AuthHandler.php';

try {
    startSession();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(['user' => currentUser(), 'csrf' => $_SESSION['csrf']]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        respond(['error' => 'Метод не поддерживается.'], 405);
    }
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        respond(['error' => 'Обнови страницу и повтори действие.'], 403);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        respond(['error' => 'Слишком большой запрос.'], 413);
    }
    $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) respond(['error' => 'Некорректные данные.'], 422);
    $action = $input['action'] ?? '';
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['ok' => true]);
    }
    if (!in_array($action, ['login', 'register'], true)) {
        respond(['error' => 'Неизвестное действие.'], 400);
    }
    checkAuthLimit();
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $password = $input['password'] ?? null;
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !is_string($password) || str_contains($password, "\0") || strlen($password) > 256
        || mb_strlen($password) < 8) {
        respond(['error' => 'Укажи корректный email и пароль от 8 символов, не более 256 байт.'], 422);
    }
    $auth = new AuthHandler(db());
    $id = $action === 'register' ? $auth->register($email, $password) : $auth->login($email, $password);
    if (!$id) respond(['error' => 'Неверный email или пароль.'], 401);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    respond(['user' => currentUser(), 'csrf' => $_SESSION['csrf']], $action === 'register' ? 201 : 200);
} catch (JsonException) {
    respond(['error' => 'Некорректный JSON.'], 400);
} catch (PDOException $error) {
    if (($error->errorInfo[1] ?? null) === 1062) {
        respond(['error' => 'Этот email уже зарегистрирован.'], 409);
    }
    error_log((string) $error);
    respond(['error' => 'База данных недоступна. Попробуй позже.'], 503);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(['error' => 'Не удалось выполнить действие. Попробуй позже.'], 500);
}
