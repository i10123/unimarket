<?php
declare(strict_types=1);

// Консоль администратора: список пользователей, подробная статистика каждого, сброс пароля и удаление.
// Запуск (панель и модуль MySQL-8.4 включены): php scripts/admin.php
if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

const KINDS = [
    'welcome' => 'Бонус при регистрации',
    'reward' => 'Награда за бесплатный материал',
    'purchase' => 'Покупка',
    'sale' => 'Продажа',
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

// Пользователи со статистикой. $where — условие с одним параметром (или пусто для всех).
function users(string $where = '', ?string $value = null): array
{
    $query = db()->prepare(
        "SELECT u.id, u.login, u.first_name, u.last_name, u.email, u.course, u.contact, u.created_at, w.available,
            (SELECT COUNT(*) FROM materials WHERE author_id = u.id) AS materials,
            (SELECT COUNT(*) FROM purchases p JOIN materials m ON m.id = p.material_id
                WHERE m.author_id = u.id) AS sales,
            (SELECT COALESCE(SUM(p.price), 0) FROM purchases p JOIN materials m ON m.id = p.material_id
                WHERE m.author_id = u.id) AS earned,
            (SELECT COUNT(*) FROM purchases WHERE buyer_id = u.id) AS purchases,
            (SELECT COALESCE(SUM(price), 0) FROM purchases WHERE buyer_id = u.id) AS spent,
            (SELECT COUNT(*) FROM favorites WHERE user_id = u.id) AS favorites,
            r.average AS rating, COALESCE(r.votes, 0) AS votes
        FROM users u
        LEFT JOIN wallets w ON w.user_id = u.id
        LEFT JOIN " . Market::RATINGS . " ON r.author_id = u.id
        $where
        ORDER BY u.id"
    );
    $query->execute($value === null ? [] : [$value]);
    return $query->fetchAll();
}

// Спрашивает номер или логин. Строка только из цифр считается номером.
function askUser(): ?array
{
    $value = ask('Номер (id) или логин: ');
    $user = users(ctype_digit($value) ? 'WHERE u.id = ?' : 'WHERE u.login = ?', $value)[0] ?? null;
    if ($user === null) {
        echo 'Пользователь не найден.', PHP_EOL;
    }
    return $user;
}

function listUsers(): void
{
    $rows = array_map(
        fn(array $u): array => [
            $u['id'], $u['login'], trim("{$u['first_name']} {$u['last_name']}") ?: null, $u['available'],
            $u['materials'], $u['sales'], $u['purchases'], $u['created_at'],
        ],
        users()
    );
    printTable(['ID', 'Логин', 'Имя', 'Баллы', 'Материалов', 'Продаж', 'Покупок', 'Создан'], $rows);
    echo 'Всего: ', count($rows), PHP_EOL;
}

function showUser(): void
{
    $u = askUser();
    if ($u === null) {
        return;
    }

    printTable(['Поле', 'Значение'], [
        ['ID', $u['id']], ['Логин', $u['login']], ['Имя', $u['first_name']], ['Фамилия', $u['last_name']],
        ['Email', $u['email']], ['Курс', $u['course']], ['Контакты для связи', $u['contact'] ?: null], ['Создан', $u['created_at']],
        ['Баллы', $u['available']],
        ['Опубликовано материалов', $u['materials']],
        ['Продаж', $u['sales']], ['Заработано с продаж', $u['earned']],
        ['Покупок', $u['purchases']], ['Потрачено на покупки', $u['spent']],
        ['В избранном', $u['favorites']],
        ['Рейтинг продавца', $u['votes'] > 0 ? "{$u['rating']} (оценок: {$u['votes']})" : 'нет оценок'],
    ]);

    $query = db()->prepare('SELECT kind, delta, created_at FROM point_entries WHERE user_id = ? ORDER BY id DESC');
    $query->execute([$u['id']]);
    $entries = array_map(fn(array $entry): array => ['kind' => KINDS[$entry['kind']]] + $entry, $query->fetchAll());

    echo PHP_EOL, 'История баллов:', PHP_EOL;
    printTable(['Событие', 'Баллы', 'Когда'], $entries);
}

// Задаёт новый пароль: почты нет, поэтому забытый пароль восстанавливает только администратор.
function resetPassword(): void
{
    $u = askUser();
    if ($u === null) {
        return;
    }

    try {
        $password = readPassword(ask("Новый пароль для {$u['login']} (виден при вводе, от 8 символов): "));
    } catch (ApiError $error) {
        echo $error->getMessage(), PHP_EOL;
        return;
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_ARGON2ID), $u['id']]);
    echo 'Пароль изменён. Передай его пользователю, пусть сменит в настройках.', PHP_EOL;
}

// Удаляет пользователя с материалами, покупками, файлами и историей (каскадом).
function deleteUser(): void
{
    $u = askUser();
    if ($u === null) {
        return;
    }

    echo "Будут удалены: аккаунт, материалов — {$u['materials']}, покупок — {$u['purchases']}, история баллов и файлы.", PHP_EOL;
    echo 'У других участников пропадут записи о сделках с ним, поэтому их история баллов перестанет сходиться с балансом.', PHP_EOL;
    if (ask("Удаление навсегда. Для подтверждения введи логин {$u['login']}: ") !== $u['login']) {
        echo 'Отменено.', PHP_EOL;
        return;
    }
    (new AuthHandler(db()))->delete((int) $u['id']);
    echo 'Пользователь удалён.', PHP_EOL;
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
        echo '2. Пользователь: статистика и история баллов', PHP_EOL;
        echo '3. Сбросить пароль', PHP_EOL;
        echo '4. Удалить пользователя', PHP_EOL;
        echo '0. Выход', PHP_EOL;

        match (ask('> ')) {
            '1' => listUsers(),
            '2' => showUser(),
            '3' => resetPassword(),
            '4' => deleteUser(),
            '0' => exit,
            default => print('Нет такого пункта.' . PHP_EOL),
        };
    }
} catch (PDOException $error) {
    echo 'Ошибка базы данных. Включён ли MySQL-8.4 в панели? ', $error->getMessage(), PHP_EOL;
    exit(1);
}
