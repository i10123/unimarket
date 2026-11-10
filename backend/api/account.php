<?php
declare(strict_types=1);

// API кабинета: /api/account
//   GET  — профиль, счётчики и история баллов;
//   POST — action=update_profile или change_password.

require __DIR__ . '/../bootstrap.php';

runApi(function (): void {
    $user = requireUser();
    $db = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(accountData($db, $user));
    }

    $input = postInput();
    switch ($input['action'] ?? '') {
        case 'update_profile':
            $profile = readProfile($input);
            $db->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, course = ? WHERE id = ?')
                ->execute([...array_values($profile), $user['id']]);
            break;
        case 'change_password':
            checkAuthLimit();
            $query = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $query->execute([$user['id']]);
            if (!password_verify((string) ($input['old_password'] ?? ''), (string) $query->fetchColumn())) {
                throw new ApiError('Старый пароль указан неверно.', 403);
            }
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash(readPassword($input['new_password'] ?? null), PASSWORD_ARGON2ID), $user['id']]);
            // Новая сессия: токен CSRF меняется, а прежние сессии пользователя на других устройствах
            // остаются; полное закрытие всех сессий в учебной версии не предусмотрено.
            renewSession();
            break;
        default:
            throw new ApiError('Неизвестное действие.', 400);
    }
    respond(['ok' => true, 'csrf' => $_SESSION['csrf']]);
});

function accountData(PDO $db, array $user): array
{
    $query = $db->prepare(
        "SELECT u.login, u.first_name, u.last_name, u.email, u.course, u.created_at,
            (SELECT COUNT(*) FROM purchases WHERE buyer_id = u.id) AS purchases,
            (SELECT COUNT(*) FROM purchases WHERE buyer_id = u.id AND status IN ('pending', 'problem')) AS waiting,
            (SELECT COUNT(*) FROM materials WHERE author_id = u.id) AS materials,
            r.average AS rating, COALESCE(r.votes, 0) AS votes
        FROM users u
        LEFT JOIN " . Market::RATINGS . " ON r.author_id = u.id
        WHERE u.id = ?"
    );
    $query->execute([$user['id']]);

    // Название берём у материала: напрямую (награда) или через покупку.
    $history = $db->prepare(
        'SELECT e.kind, e.available_delta, e.reserved_delta, e.created_at, m.id AS material_id, m.title
        FROM point_entries e
        LEFT JOIN purchases p ON p.id = e.purchase_id
        LEFT JOIN materials m ON m.id = COALESCE(e.material_id, p.material_id)
        WHERE e.user_id = ?
        ORDER BY e.id DESC
        LIMIT 100'
    );
    $history->execute([$user['id']]);

    return ['user' => $user, 'profile' => $query->fetch(), 'history' => $history->fetchAll()];
}
