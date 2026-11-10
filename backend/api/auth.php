<?php
declare(strict_types=1);

// API доступа: /api/auth
//   GET  — текущий пользователь (или null) и CSRF-токен;
//   POST — действие login, check_login, register или logout.

require __DIR__ . '/../bootstrap.php';

runApi(function (): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(['user' => currentUser(), 'csrf' => $_SESSION['csrf']]);
    }

    $input = postInput();
    $action = $input['action'] ?? '';

    if ($action === 'logout') {
        $_SESSION = [];
        renewSession();
        respond(['ok' => true]);
    }
    if (!in_array($action, ['login', 'check_login', 'register'], true)) {
        throw new ApiError('Неизвестное действие.', 400);
    }

    checkAuthLimit();
    $login = readLogin($input['login'] ?? null);
    $auth = new AuthHandler(db());

    // Первый шаг регистрации: аккаунт ещё не создаётся, только проверяем логин.
    if ($action === 'check_login') {
        if ($auth->loginExists($login)) {
            throw new ApiError('Этот логин уже занят.', 409);
        }
        respond(['ok' => true]);
    }

    $password = readPassword($input['password'] ?? null);
    if ($action === 'register') {
        if (($input['rules'] ?? false) !== true) {
            throw new ApiError('Нужно принять правила обмена материалами.');
        }
        $userId = $auth->register($login, $password, readProfile($input));
    } else {
        $userId = $auth->login($login, $password) ?? throw new ApiError('Неверный логин или пароль.', 401);
    }

    renewSession();
    $_SESSION['user_id'] = $userId;
    respond(['user' => currentUser(), 'csrf' => $_SESSION['csrf']], $action === 'register' ? 201 : 200);
});
