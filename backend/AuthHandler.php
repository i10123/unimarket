<?php
declare(strict_types=1);

// Регистрация и вход. Возвращают id пользователя; проверку полей делает auth.php.
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

        $this->db->beginTransaction();
        try {
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

            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
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
}
