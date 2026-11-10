<?php
declare(strict_types=1);

// Аккаунт: регистрация, вход, удаление. Поля проверяет вызывающий код.
final class AuthHandler
{
    // Хеш для несуществующего логина: проверка занимает столько же времени,
    // и по скорости ответа нельзя узнать, существует ли логин.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Yml0dDBjc0FqdEFLeWYxZQ$DhuZYtjIlJTuj96nRXxMcsHhHbuldYQAdcfzwFAJ2B0';

    private const WELCOME_BONUS = 100;

    public function __construct(private PDO $db)
    {
    }

    // Аккаунт, кошелёк с бонусом и запись о бонусе создаются вместе или не создаются вовсе.
    // $profile: first_name, last_name, email, course (любое может быть null) и contact (обязателен).
    public function register(string $login, string $password, array $profile): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        try {
            return transaction(function () use ($login, $hash, $profile): int {
                $this->db
                    ->prepare('INSERT INTO users (login, password_hash, first_name, last_name, email, course, contact)
                        VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$login, $hash, ...array_values($profile)]);
                $id = (int) $this->db->lastInsertId();

                $this->db
                    ->prepare('INSERT INTO wallets (user_id, available) VALUES (?, ?)')
                    ->execute([$id, self::WELCOME_BONUS]);
                $this->db
                    ->prepare("INSERT INTO point_entries (user_id, kind, delta) VALUES (?, 'welcome', ?)")
                    ->execute([$id, self::WELCOME_BONUS]);
                return $id;
            });
        } catch (PDOException $error) {
            // 1062 — нарушение UNIQUE: такой логин уже есть.
            throw ($error->errorInfo[1] ?? null) === 1062 ? new ApiError('Этот логин уже занят.', 409) : $error;
        }
    }

    public function loginExists(string $login): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM users WHERE login = ?');
        $query->execute([$login]);
        return $query->fetchColumn() !== false;
    }

    public function login(string $login, string $password): ?int
    {
        $query = $this->db->prepare('SELECT id, password_hash FROM users WHERE login = ?');
        $query->execute([$login]);
        $user = $query->fetch();

        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        if (!password_verify($password, $hash) || !$user) {
            return null;
        }
        return (int) $user['id'];
    }

    // Полное удаление (консоль администратора): данные убирают каскады БД, файлы материалов — с диска.
    public function delete(int $id): void
    {
        $query = $this->db->prepare(
            'SELECT f.storage_name FROM files f JOIN materials m ON m.id = f.material_id WHERE m.author_id = ?'
        );
        $query->execute([$id]);
        $storageNames = $query->fetchAll(PDO::FETCH_COLUMN);

        transaction(fn() => $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]));
        FileStore::delete($storageNames);
    }
}
