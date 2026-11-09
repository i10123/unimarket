<?php
declare(strict_types=1);

// Проверка консоли администратора. Запуск: php tests/admin.php
// Создаёт временный аккаунт и проходит список, сброс пароля и удаление.

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../backend/AuthHandler.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Запускает консоль и вводит в неё строки, как человек с клавиатуры.
function runAdmin(string ...$lines): string
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../scripts/admin.php'], [['pipe', 'r'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], implode(PHP_EOL, [...$lines, '0']) . PHP_EOL);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    proc_close($process);
    return $output;
}

function countFor(string $table, string $column, int $id): int
{
    $query = db()->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
    $query->execute([$id]);
    return (int) $query->fetchColumn();
}

$login = 'test_' . bin2hex(random_bytes(6));
$profile = ['first_name' => 'Анна', 'last_name' => null, 'email' => null, 'course' => 2];
$userId = (new AuthHandler(db()))->register($login, 'Учебный-пароль-123', $profile);

try {
    check(str_contains(runAdmin('1'), $login), 'Логина нет в списке');
    check(str_contains(runAdmin('2', $login), 'Бонус при регистрации'), 'Нет истории баллов');
    check(str_contains(runAdmin('2', 'нет_такого'), 'не найден'), 'Несуществующий пользователь найден');

    $output = runAdmin('3', (string) $userId);
    check(preg_match('/Новый пароль: (\S+)/u', $output, $match) === 1, 'Пароль не показан');
    $query = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $query->execute([$userId]);
    check(password_verify($match[1], $query->fetchColumn()), 'Пароль не сменился');

    runAdmin('4', $login, 'другой-логин');
    check(countFor('users', 'id', $userId) === 1, 'Удаление без подтверждения');

    check(str_contains(runAdmin('4', $login, $login), 'удалён'), 'Нет сообщения об удалении');
    check(countFor('users', 'id', $userId) === 0, 'Пользователь не удалён');
    check(countFor('wallets', 'user_id', $userId) === 0, 'Остался кошелёк');
    check(countFor('point_entries', 'user_id', $userId) === 0, 'Осталась история баллов');

    echo "OK: список, подробности, сброс пароля, подтверждение и удаление.\n";
} finally {
    db()->prepare('DELETE FROM point_entries WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
}
