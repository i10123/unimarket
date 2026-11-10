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
    // $profile: first_name, last_name, email, course; любое из них может быть null.
    public function register(string $login, string $password, array $profile): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        try {
            return transaction(function () use ($login, $hash, $profile): int {
                $this->db
                    ->prepare('INSERT INTO users (login, password_hash, first_name, last_name, email, course)
                        VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$login, $hash, $profile['first_name'], $profile['last_name'], $profile['email'], $profile['course']]);
                $id = (int) $this->db->lastInsertId();

                $this->db
                    ->prepare('INSERT INTO wallets (user_id, available) VALUES (?, ?)')
                    ->execute([$id, self::WELCOME_BONUS]);
                $this->db
                    ->prepare("INSERT INTO point_entries (user_id, kind, available_delta, reserved_delta)
                        VALUES (?, 'welcome', ?, 0)")
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

    // Полное удаление (консоль администратора). Открытые покупки чужих людей у этого продавца
    // сначала возвращаются покупателям; остальное убирают каскады БД, файлы — с диска.
    public function delete(int $id): void
    {
        $query = $this->db->prepare(
            'SELECT f.storage_name
            FROM files f
            LEFT JOIN materials m ON m.id = f.material_id
            LEFT JOIN purchases p ON p.id = f.purchase_id
            LEFT JOIN materials pm ON pm.id = p.material_id
            WHERE m.author_id = ? OR p.buyer_id = ? OR pm.author_id = ?'
        );
        $query->execute([$id, $id, $id]);
        $storageNames = $query->fetchAll(PDO::FETCH_COLUMN);

        (new Deals($this->db))->refundOpenSales($id);
        transaction(fn() => $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]));
        FileStore::delete($storageNames);
    }
}
