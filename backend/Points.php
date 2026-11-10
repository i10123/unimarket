<?php
declare(strict_types=1);

// Движение баллов: баланс кошелька и запись в истории меняются вместе.
// Вызывать только внутри transaction().
final class Points
{
    // Блокирует кошельки до конца транзакции. Порядок по id исключает взаимную блокировку
    // двух операций между одними и теми же пользователями.
    public static function lock(PDO $db, int ...$userIds): void
    {
        $userIds = array_values(array_unique($userIds));
        sort($userIds);
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $db->prepare("SELECT user_id FROM wallets WHERE user_id IN ($marks) ORDER BY user_id FOR UPDATE")
            ->execute($userIds);
    }

    // kind: reward, purchase или sale. Отрицательный остаток запрещает CHECK в БД.
    public static function add(PDO $db, int $userId, string $kind, int $delta, ?int $purchaseId = null, ?int $materialId = null): void
    {
        $db->prepare('UPDATE wallets SET available = available + ? WHERE user_id = ?')->execute([$delta, $userId]);
        $db->prepare('INSERT INTO point_entries (user_id, purchase_id, material_id, kind, delta) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $purchaseId, $materialId, $kind, $delta]);
    }
}
