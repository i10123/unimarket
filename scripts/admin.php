<?php
declare(strict_types=1);

// Консоль администратора: пользователи, баланс, сброс пароля, удаление.
// Запуск (панель и модуль MySQL-8.4 включены): php scripts/admin.php
if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

const KINDS = [
    'welcome' => 'Бонус при регистрации',
    'reward' => 'Награда за материал',
    'reserve' => 'Резерв при покупке',
    'release' => 'Выплата продавцу',
    'refund' => 'Возврат',
];

function ask(string $question): string
{
    echo $question;
    $line = fgets(STDIN);
    if ($line === false) {
        exit;
    }
    return trim($line);
}

function printTable(array $headers, array $rows): void
{
    $rows = array_map(
        fn(array $row): array => array_map(fn($value): string => (string) ($value ?? '—'), array_values($row)),
        $rows
    );

    $widths = array_map('mb_strlen', $headers);
    foreach ($rows as $row) {
        foreach ($row as $column => $value) {
            $widths[$column] = max($widths[$column], mb_strlen($value));
        }
    }

    foreach ([$headers, array_map(fn(int $width): string => str_repeat('-', $width), $widths), ...$rows] as $row) {
        foreach ($row as $column => $value) {
            echo mb_str_pad($value, $widths[$column]), '  ';
        }
        echo PHP_EOL;
    }
}

// Спрашивает номер или логин. Строка только из цифр считается номером.
function askUser(): ?array
{
    $value = ask('Номер (id) или логин: ');
    $field = ctype_digit($value) ? 'u.id' : 'u.login';
    $query = db()->prepare(
        "SELECT u.id, u.login, u.first_name, u.last_name, u.email, u.course, u.created_at, w.available, w.reserved
        FROM users u
        LEFT JOIN wallets w ON w.user_id = u.id
        WHERE $field = ?"
    );
    $query->execute([$value]);

    $user = $query->fetch() ?: null;
    if ($user === null) {
        echo 'Пользователь не найден.', PHP_EOL;
    }
    return $user;
}

function listUsers(): void
{
    $rows = db()->query(
        "SELECT u.id, u.login, NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.course,
            w.available, w.reserved, u.created_at
        FROM users u
        LEFT JOIN wallets w ON w.user_id = u.id
        ORDER BY u.id"
    )->fetchAll();

    printTable(['ID', 'Логин', 'Имя', 'Курс', 'Баллы', 'Резерв', 'Создан'], $rows);
    echo 'Всего: ', count($rows), PHP_EOL;
}

function countRows(string $sql, int $userId): int
{
    $query = db()->prepare($sql);
    $query->execute([$userId]);
    return (int) $query->fetchColumn();
}

function showUser(): void
{
    $user = askUser();
    if ($user === null) {
        return;
    }

    printTable(['Поле', 'Значение'], [
        ['ID', $user['id']],
        ['Логин', $user['login']],
        ['Имя', $user['first_name']],
        ['Фамилия', $user['last_name']],
        ['Email', $user['email']],
        ['Курс', $user['course']],
        ['Баллы', $user['available']],
        ['Резерв', $user['reserved']],
        ['Создан', $user['created_at']],
        ['Материалов', countRows('SELECT COUNT(*) FROM materials WHERE author_id = ?', $user['id'])],
        ['Покупок', countRows('SELECT COUNT(*) FROM purchases WHERE buyer_id = ?', $user['id'])],
    ]);

    $query = db()->prepare(
        'SELECT kind, available_delta, reserved_delta, created_at
        FROM point_entries
        WHERE user_id = ?
        ORDER BY id DESC'
    );
    $query->execute([$user['id']]);
    $entries = array_map(
        fn(array $entry): array => ['kind' => KINDS[$entry['kind']]] + $entry,
        $query->fetchAll()
    );

    echo PHP_EOL, 'История баллов:', PHP_EOL;
    printTable(['Событие', 'Баллы', 'Резерв', 'Когда'], $entries);
}

// Пароль создаётся случайный и показывается один раз: хеш обратно не прочитать.
function resetPassword(): void
{
    $user = askUser();
    if ($user === null) {
        return;
    }

    $password = bin2hex(random_bytes(6));
    db()
        ->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);

    echo 'Логин: ', $user['login'], PHP_EOL;
    echo 'Новый пароль: ', $password, PHP_EOL;
}

// Порядок важен: сначала строки, которые ссылаются на пользователя, потом он сам.
function deleteUser(): void
{
    $user = askUser();
    if ($user === null) {
        return;
    }

    if (ask("Удаление навсегда. Для подтверждения введи логин {$user['login']}: ") !== $user['login']) {
        echo 'Отменено.', PHP_EOL;
        return;
    }

    db()->beginTransaction();
    try {
        foreach (['point_entries', 'favorites', 'wallets'] as $table) {
            db()->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$user['id']]);
        }
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
        db()->commit();
        echo 'Пользователь удалён.', PHP_EOL;
    } catch (PDOException $error) {
        db()->rollBack();
        // 1451 — на строку есть ссылки: у пользователя есть материалы или покупки.
        if (($error->errorInfo[1] ?? null) !== 1451) {
            throw $error;
        }
        echo 'Нельзя удалить: у пользователя есть материалы или покупки.', PHP_EOL;
    }
}

function clearLimits(): void
{
    $files = glob(storageDir('limits') . '/*.json') ?: [];
    array_map('unlink', $files);
    echo 'Сброшено счётчиков: ', count($files), PHP_EOL;
}

if (function_exists('sapi_windows_cp_set')) {
    sapi_windows_cp_set(65001);
}

try {
    // db() работает в UTC, а здесь показываем время сервера, как в phpMyAdmin.
    db()->exec('SET time_zone = DEFAULT');

    while (true) {
        echo PHP_EOL, 'УниМаркет — консоль администратора', PHP_EOL;
        echo '1. Список пользователей', PHP_EOL;
        echo '2. Пользователь подробно и история баллов', PHP_EOL;
        echo '3. Сбросить пароль', PHP_EOL;
        echo '4. Удалить пользователя', PHP_EOL;
        echo '5. Снять блокировку попыток входа', PHP_EOL;
        echo '0. Выход', PHP_EOL;

        match (ask('> ')) {
            '1' => listUsers(),
            '2' => showUser(),
            '3' => resetPassword(),
            '4' => deleteUser(),
            '5' => clearLimits(),
            '0' => exit,
            default => print('Нет такого пункта.' . PHP_EOL),
        };
    }
} catch (PDOException $error) {
    echo 'Ошибка базы данных. Включён ли MySQL-8.4 в панели? ', $error->getMessage(), PHP_EOL;
    exit(1);
}
