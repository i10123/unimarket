<?php
declare(strict_types=1);

// Сквозная проверка доступа. Запуск при работающем сайте: php tests/auth.php
// Создаёт временный аккаунт и в конце удаляет его.

require __DIR__ . '/../backend/bootstrap.php';

const SITE = 'http://unimarket.local';

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
$limitPath = storageDir('limits') . '/' . hash('sha256', '127.0.0.1') . '.json';
$previousLimit = is_file($limitPath) ? file_get_contents($limitPath) : null;
$userId = null;

try {
    setLimit($limitPath, 0);

    // Имя для показа: все случаи заполнения имени и фамилии.
    check(displayName(['id' => 22, 'first_name' => 'Анна', 'last_name' => 'Кузнецова']) === 'Анна Кузнецова', 'Имя и фамилия');
    check(displayName(['id' => 22, 'first_name' => 'Анна', 'last_name' => null]) === 'Анна', 'Только имя');
    check(displayName(['id' => 22, 'first_name' => null, 'last_name' => 'Кузнецова']) === 'Кузнецова', 'Только фамилия');
    check(displayName(['id' => 22, 'first_name' => null, 'last_name' => null]) === 'User22', 'Без имени и фамилии');

    // Гость и закрытые файлы.
    check(status('/pages/catalog.html') === 302, 'Гость получил закрытую страницу');
    $closedPaths = [
        '/config/local.php',
        '/storage/sessions',
        '/database/schema.sql',
        '/backend/auth.php',
        '/.git/config',
        '/assets/css/../../config/local.php',
    ];
    foreach ($closedPaths as $path) {
        // .git Apache закрывает сам ответом 403, остальное — router.php ответом 404.
        check(in_array(status($path), [403, 404], true), 'Закрытый файл доступен: ' . $path);
    }

    [$code, $session] = request('/api/auth');
    check($code === 200 && $session['user'] === null, 'Ошибка гостевой сессии');
    $csrf = $session['csrf'];

    // Проверки полей и CSRF.
    $body = ['action' => 'register', 'login' => $login, 'password' => $password, 'rules' => true];
    check(status('/api/auth', $body) === 403, 'Нет защиты CSRF');
    check(status('/api/auth', ['login' => 'a b'] + $body, $csrf) === 422, 'Нет проверки логина');
    check(status('/api/auth', ['login' => 'ab'] + $body, $csrf) === 422, 'Разрешён слишком короткий логин');
    check(status('/api/auth', ['password' => '123'] + $body, $csrf) === 422, 'Нет проверки пароля');
    check(status('/api/auth', ['rules' => false] + $body, $csrf) === 422, 'Правила не обязательны');
    check(status('/api/auth', ['email' => 'нет-почты'] + $body, $csrf) === 422, 'Нет проверки почты');
    check(status('/api/auth', ['course' => '7'] + $body, $csrf) === 422, 'Нет проверки курса');
    check(status('/api/auth', ['first_name' => '<b>'] + $body, $csrf) === 422, 'Нет проверки имени');

    // Первый шаг только проверяет логин и не создаёт аккаунт.
    $checkLogin = ['action' => 'check_login', 'login' => $login];
    check(status('/api/auth', $checkLogin, $csrf) === 200, 'Свободный логин отклонён');
    check(status('/api/auth', ['login' => 'a b'] + $checkLogin, $csrf) === 422, 'Проверка логина пропустила формат');
    $query = db()->prepare('SELECT COUNT(*) FROM users WHERE login = ?');
    $query->execute([$login]);
    check((int) $query->fetchColumn() === 0, 'Первый шаг создал аккаунт');

    // Регистрация.
    $profile = ['first_name' => 'Анна', 'last_name' => 'Кузнецова', 'email' => '', 'course' => '2'];
    [$code, $registered] = request('/api/auth', $body + $profile, $csrf);
    check($code === 201, 'Регистрация не выполнена');
    $userId = $registered['user']['id'];
    $user = $registered['user'];
    check((int) $user['available'] === 100 && (int) $user['reserved'] === 0, 'Приветственный бонус не начислен');
    check($user['display_name'] === 'Анна Кузнецова', 'API не вернул имя для показа');
    check($registered['csrf'] !== $csrf, 'Токен после входа не сменился');
    $csrf = $registered['csrf'];

    check(status('/pages/catalog.html') === 200, 'Вход не открыл каталог');
    check(status('/index.html') === 302, 'Авторизованный пользователь попал на гостевой лендинг');

    $query = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $query->execute([$userId]);
    $hash = $query->fetchColumn();
    check($hash !== $password && password_verify($password, $hash), 'Пароль не защищён хешем');

    $query = db()->prepare("SELECT available_delta FROM point_entries WHERE user_id = ? AND kind = 'welcome'");
    $query->execute([$userId]);
    check($query->fetchAll(PDO::FETCH_COLUMN) === [100], 'Бонус не записан в историю баллов');

    check(status('/api/auth', $checkLogin, $csrf) === 409, 'Занятый логин не обнаружен');

    $query = db()->prepare('SELECT first_name, last_name, email, course FROM users WHERE id = ?');
    $query->execute([$userId]);
    $saved = $query->fetch();
    check(
        $saved === ['first_name' => 'Анна', 'last_name' => 'Кузнецова', 'email' => null, 'course' => 2],
        'Профиль сохранён неверно'
    );

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

    echo "OK: регистрация, профиль, приветственный бонус, хеш, повторный логин, вход/выход, CSRF, "
        . "права доступа, SQL-параметры, лимит, 7 таблиц и CHECK баланса.\n";
} finally {
    if ($userId !== null) {
        db()->prepare('DELETE FROM point_entries WHERE user_id = ?')->execute([$userId]);
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
