<?php
declare(strict_types=1);

// Сквозная проверка доступа. Запуск при работающем сайте: php tests/auth.php
// Создаёт временный аккаунт и в конце удаляет его.

require __DIR__ . '/../backend/bootstrap.php';

const SITE = 'http://127.0.0.1:8000';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// HTTP-запрос к сайту с общими cookie. Возвращает [статус, данные JSON].
function request(string $path, ?array $body = null, ?string $csrf = null, string $method = ''): array
{
    global $cookieJar;

    $headers = ['Content-Type: application/json'];
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    $curl = curl_init(SITE . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($method !== '') {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    }

    $text = curl_exec($curl);
    if ($text === false) {
        throw new RuntimeException(curl_error($curl));
    }
    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode($text, true)];
}

function status(string $path, ?array $body = null, ?string $csrf = null, string $method = ''): int
{
    return request($path, $body, $csrf, $method)[0];
}

function freshCsrf(): string
{
    return request('/api/auth')[1]['csrf'];
}

function setLimit(string $path, int $count): void
{
    file_put_contents($path, json_encode(['until' => time() + 900, 'count' => $count]));
}

$cookieJar = tempnam(sys_get_temp_dir(), 'unimarket-');
$login = 'test_' . bin2hex(random_bytes(6));
$password = 'Учебный-пароль-123';
$limitPath = ROOT . '/storage/limits/' . hash('sha256', '127.0.0.1') . '.json';
$previousLimit = is_file($limitPath) ? file_get_contents($limitPath) : null;
$userId = null;

try {
    setLimit($limitPath, 0);

    // Гость и закрытые файлы.
    check(status('/pages/catalog.html') === 302, 'Гость получил закрытую страницу');
    $closedPaths = [
        '/config/local.php',
        '/storage/mysql-admin.json',
        '/database/schema.sql',
        '/backend/auth.php',
        '/.git/config',
        '/assets/css/../../config/local.php',
    ];
    foreach ($closedPaths as $path) {
        check(status($path) === 404, 'Закрытый файл доступен: ' . $path);
    }

    [$code, $session] = request('/api/auth');
    check($code === 200 && $session['user'] === null, 'Ошибка гостевой сессии');
    $csrf = $session['csrf'];

    // Проверки полей и CSRF.
    $body = ['action' => 'register', 'login' => $login, 'password' => $password];
    check(status('/api/auth', $body) === 403, 'Нет защиты CSRF');
    check(status('/api/auth', ['login' => 'a b'] + $body, $csrf) === 422, 'Нет проверки логина');
    check(status('/api/auth', ['login' => 'ab'] + $body, $csrf) === 422, 'Разрешён слишком короткий логин');
    check(status('/api/auth', ['password' => '123'] + $body, $csrf) === 422, 'Нет проверки пароля');

    // Регистрация.
    [$code, $registered] = request('/api/auth', $body, $csrf);
    check($code === 201, 'Регистрация не выполнена');
    $userId = $registered['user']['id'];
    $user = $registered['user'];
    check((int) $user['available'] === 0 && (int) $user['reserved'] === 0, 'Начальный баланс не равен нулю');
    check($registered['csrf'] !== $csrf, 'Токен после входа не сменился');
    $csrf = $registered['csrf'];

    check(status('/pages/catalog.html') === 200, 'Вход не открыл каталог');
    check(status('/index.html') === 302, 'Авторизованный пользователь попал на гостевой лендинг');

    $query = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $query->execute([$userId]);
    $hash = $query->fetchColumn();
    check($hash !== $password && password_verify($password, $hash), 'Пароль не защищён хешем');

    $sameLogin = ['login' => strtoupper($login)] + $body;
    check(status('/api/auth', $sameLogin, $csrf) === 409, 'Повторный логин разрешён');

    // Выход и вход.
    check(status('/api/auth', ['action' => 'logout'], $csrf) === 200, 'Выход не выполнен');
    check(status('/pages/catalog.html') === 302, 'Выход не закрыл каталог');

    $csrf = freshCsrf();
    $loginBody = ['action' => 'login', 'login' => $login, 'password' => $password];
    check(status('/api/auth', ['password' => 'wrong-password'] + $loginBody, $csrf) === 401, 'Неверный пароль принят');
    $sqlInjection = ['login' => "x' OR 1=1 --"] + $loginBody;
    check(status('/api/auth', $sqlInjection, $csrf) !== 200, 'Обход входа через SQL');
    check(status('/api/auth', ['login' => strtoupper($login)] + $loginBody, $csrf) === 200, 'Повторный вход не работает');

    // Ограничения базы.
    check(count(db()->query('SHOW TABLES')->fetchAll()) === 7, 'Ожидалось семь таблиц');
    try {
        db()->prepare('UPDATE wallets SET available = -1 WHERE user_id = ?')->execute([$userId]);
        throw new RuntimeException('База разрешила отрицательный баланс');
    } catch (PDOException $error) {
        // 3819 — код MySQL для нарушения CHECK.
        check(($error->errorInfo[1] ?? 0) === 3819, 'Неожиданная ошибка ограничения MySQL');
    }

    // Лимит попыток и неизвестный метод.
    setLimit($limitPath, 20);
    check(status('/api/auth', $loginBody, freshCsrf()) === 429, 'Лимит попыток не работает');
    check(status('/api/auth', null, null, 'DELETE') === 405, 'Неизвестный метод принят');

    echo "OK: регистрация, нулевой кошелёк, хеш, повторный логин, вход/выход, CSRF, "
        . "права доступа, SQL-параметры, лимит, 7 таблиц и CHECK баланса.\n";
} finally {
    if ($userId !== null) {
        db()->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM users WHERE id = ? AND login = ?')->execute([$userId, $login]);
    }
    if ($previousLimit === null) {
        unlink($limitPath);
    } else {
        file_put_contents($limitPath, $previousLimit);
    }
    unlink($cookieJar);
}
