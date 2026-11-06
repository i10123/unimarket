<?php
declare(strict_types=1);

// Регистрация и вход. Возвращают id пользователя; проверку полей делает auth.php.
final class AuthHandler
{
    // Хеш для несуществующего логина: проверка занимает столько же времени,
    // и по скорости ответа нельзя узнать, существует ли логин.
    private const DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';

    public function __construct(private PDO $db)
    {
    }

    // Аккаунт и пустой кошелёк создаются вместе или не создаются вовсе.
    public function register(string $login, string $password): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $this->db->beginTransaction();
        try {
            $this->db
                ->prepare('INSERT INTO users (login, password_hash) VALUES (?, ?)')
                ->execute([$login, $hash]);
            $id = (int) $this->db->lastInsertId();

            $this->db
                ->prepare('INSERT INTO wallets (user_id) VALUES (?)')
                ->execute([$id]);

            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
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
}
