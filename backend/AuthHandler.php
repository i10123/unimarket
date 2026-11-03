<?php
declare(strict_types=1);

final class AuthHandler
{
    public function __construct(private PDO $db) {}

    public function register(string $email, string $password): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
                ->execute([$email, $hash]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare('INSERT INTO wallets (user_id) VALUES (?)')->execute([$id]);
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function login(string $email, string $password): ?int
    {
        $query = $this->db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $query->execute([$email]);
        $user = $query->fetch();
        // Отсутствующий аккаунт тоже проходит проверку хеша.
        $hash = $user['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password, $hash) || !$user) return null;
        return (int) $user['id'];
    }
}
