<?php
declare(strict_types=1);
require __DIR__ . '/../backend/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function request(string $path, ?array $body = null, ?string $csrf = null, string $method = ''): array
{
    global $cookieJar;
    $curl = curl_init('http://127.0.0.1:8000' . $path);
    $headers = ['Content-Type: application/json'];
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    if ($method) curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    $text = curl_exec($curl);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl);
    return [$status, json_decode($text, true), $text];
}

$cookieJar = tempnam(sys_get_temp_dir(), 'unimarket-');
$email = 'test-' . bin2hex(random_bytes(6)) . '@example.test';
$password = 'Учебный-пароль-123';
$limitPath = ROOT . '/storage/limits/' . hash('sha256', '127.0.0.1') . '.json';
$previousLimit = is_file($limitPath) ? file_get_contents($limitPath) : null;
$userId = null;
try {
    file_put_contents($limitPath, json_encode(['until' => time() + 900, 'count' => 0]));
    check(request('/pages/catalog.html')[0] === 302, 'Гость получил закрытую страницу');
    foreach (['/config/local.php', '/storage/database-admin.json', '/database/schema.sql', '/backend/auth.php', '/.git/config', '/assets/css/../../config/local.php'] as $path) {
        check(request($path)[0] === 404, 'Закрытый файл доступен: ' . $path);
    }
    [$status, $session] = request('/api/auth');
    check($status === 200 && $session['user'] === null, 'Ошибка гостевой сессии');
    $csrf = $session['csrf'];
    $body = ['action' => 'register', 'email' => $email, 'password' => $password];
    check(request('/api/auth', $body)[0] === 403, 'Нет защиты CSRF');
    check(request('/api/auth', array_replace($body, ['email' => 'bad']), $csrf)[0] === 422, 'Нет проверки email');
    check(request('/api/auth', array_replace($body, ['password' => '123']), $csrf)[0] === 422, 'Нет проверки пароля');
    [$status, $registered] = request('/api/auth', $body, $csrf);
    check($status === 201, 'Регистрация не выполнена');
    $userId = $registered['user']['id'];
    check((int) $registered['user']['available'] === 0 && (int) $registered['user']['reserved'] === 0, 'Начальный баланс не равен нулю');
    check($registered['csrf'] !== $csrf, 'Токен после входа не сменился');
    $csrf = $registered['csrf'];
    check(request('/pages/catalog.html')[0] === 200, 'Вход не открыл каталог');
    check(request('/index.html')[0] === 302, 'Авторизованный пользователь попал на гостевой лендинг');
    $query = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $query->execute([$userId]);
    $hash = $query->fetchColumn();
    check($hash !== $password && password_verify($password, $hash), 'Пароль не защищён хешем');
    check(request('/api/auth', array_replace($body, ['email' => strtoupper($email)]), $csrf)[0] === 409, 'Повторный email разрешён');
    check(request('/api/auth', ['action' => 'logout'], $csrf)[0] === 200, 'Выход не выполнен');
    check(request('/pages/catalog.html')[0] === 302, 'Выход не закрыл каталог');
    $csrf = request('/api/auth')[1]['csrf'];
    check(request('/api/auth', ['action' => 'login', 'email' => $email, 'password' => 'wrong-password'], $csrf)[0] === 401, 'Неверный пароль принят');
    check(request('/api/auth', ['action' => 'login', 'email' => "x' OR 1=1 --@example.test", 'password' => $password], $csrf)[0] !== 200, 'Обход входа через SQL');
    check(request('/api/auth', ['action' => 'login', 'email' => strtoupper($email), 'password' => $password], $csrf)[0] === 200, 'Повторный вход не работает');
    check(count(db()->query('SHOW TABLES')->fetchAll()) === 7, 'Ожидалось семь таблиц');
    try {
        db()->prepare('UPDATE wallets SET available = -1 WHERE user_id = ?')->execute([$userId]);
        throw new RuntimeException('База разрешила отрицательный баланс');
    } catch (PDOException $error) {
        check(($error->errorInfo[1] ?? 0) === 3819, 'Неожиданная ошибка ограничения MySQL');
    }
    file_put_contents($limitPath, json_encode(['until' => time() + 900, 'count' => 20]));
    $csrf = request('/api/auth')[1]['csrf'];
    check(request('/api/auth', ['action' => 'login', 'email' => $email, 'password' => $password], $csrf)[0] === 429, 'Лимит попыток не работает');
    check(request('/api/auth', null, null, 'DELETE')[0] === 405, 'Неизвестный метод принят');
    echo "OK: регистрация, нулевой кошелёк, хеш, повторный email, вход/выход, CSRF, права доступа, SQL-параметры, лимит, 7 таблиц и CHECK баланса.\n";
} finally {
    if ($userId !== null) {
        db()->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM users WHERE id = ? AND email = ?')->execute([$userId, $email]);
    }
    if ($previousLimit === null) unlink($limitPath);
    else file_put_contents($limitPath, $previousLimit);
    unlink($cookieJar);
}
