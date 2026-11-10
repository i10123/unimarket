<?php
declare(strict_types=1);

// Покупки: баллы сразу переходят автору, затем оценка продавца. Если с файлом что-то не так,
// покупатель пишет автору сам: контакт автора виден на странице материала и в профиле.
final class Deals
{
    public function __construct(private PDO $db)
    {
    }

    // Покупка материала. Бесплатный материал баллов не двигает и в историю не попадает.
    public function buy(int $buyerId, int $materialId): int
    {
        try {
            return transaction(function () use ($buyerId, $materialId): int {
                $query = $this->db->prepare("SELECT author_id, price FROM materials WHERE id = ? AND status = 'published'");
                $query->execute([$materialId]);
                $material = $query->fetch() ?: throw new ApiError('Материал не найден.', 404);
                if ($material['author_id'] === $buyerId) {
                    throw new ApiError('Свой материал покупать не нужно.', 409);
                }

                // Блокировка кошельков: двойной щелчок не спишет баллы дважды.
                Points::lock($this->db, $buyerId, $material['author_id']);
                $this->db
                    ->prepare('INSERT INTO purchases (buyer_id, material_id, price) VALUES (?, ?, ?)')
                    ->execute([$buyerId, $materialId, $material['price']]);
                $id = (int) $this->db->lastInsertId();

                if ($material['price'] > 0) {
                    Points::add($this->db, $buyerId, 'purchase', -$material['price'], $id);
                    Points::add($this->db, $material['author_id'], 'sale', $material['price'], $id);
                }
                return $id;
            });
        } catch (PDOException $error) {
            // 1062 — повторная покупка, 3819 — CHECK кошелька: баллов не хватает.
            throw match ($error->errorInfo[1] ?? null) {
                1062 => new ApiError('Материал уже куплен.', 409),
                3819 => new ApiError('Не хватает баллов для покупки.', 409),
                default => $error,
            };
        }
    }

    // Оценка продавца 1–5; ставится один раз.
    public function rate(int $buyerId, int $purchaseId, int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new ApiError('Оценка: от 1 до 5.');
        }
        $query = $this->db->prepare('UPDATE purchases SET rating = ? WHERE id = ? AND buyer_id = ? AND rating IS NULL');
        $query->execute([$rating, $purchaseId, $buyerId]);
        if ($query->rowCount() === 0) {
            throw new ApiError('Оценить покупку можно один раз.', 409);
        }
    }

    // Мои покупки с файлами и контактом продавца.
    public function purchases(int $buyerId): array
    {
        $query = $this->db->prepare(
            'SELECT p.id, p.material_id, m.title, m.type, m.course, p.price, p.purchased_at, p.rating,
                m.author_id, ' . DISPLAY_NAME . ' AS seller_name, u.contact AS seller_contact
            FROM purchases p
            JOIN materials m ON m.id = p.material_id
            JOIN users u ON u.id = m.author_id
            WHERE p.buyer_id = ?
            ORDER BY p.id DESC'
        );
        $query->execute([$buyerId]);

        // Файлы всех покупок одним запросом.
        $files = $this->db->prepare(
            'SELECT p.id AS purchase_id, f.id, f.original_name AS name, f.size_bytes AS size
            FROM purchases p JOIN files f ON f.material_id = p.material_id
            WHERE p.buyer_id = ?
            ORDER BY f.id'
        );
        $files->execute([$buyerId]);
        $byPurchase = [];
        foreach ($files->fetchAll() as $file) {
            $byPurchase[$file['purchase_id']][] = $file;
        }

        return array_map(fn(array $row): array => $row + ['files' => $byPurchase[$row['id']] ?? []], $query->fetchAll());
    }

    // Мои материалы: публикации со счётчиком покупок и последние покупки моих материалов.
    public function sales(int $sellerId): array
    {
        $materials = $this->db->prepare(
            'SELECT m.id, m.title, m.type, m.course, m.price, m.status, m.created_at, COUNT(p.id) AS sales
            FROM materials m
            LEFT JOIN purchases p ON p.material_id = m.id
            WHERE m.author_id = ?
            GROUP BY m.id
            ORDER BY m.id DESC'
        );
        $materials->execute([$sellerId]);

        $orders = $this->db->prepare(
            'SELECT p.id, m.title, p.price, p.purchased_at, ' . DISPLAY_NAME . ' AS buyer_name
            FROM purchases p
            JOIN materials m ON m.id = p.material_id
            JOIN users u ON u.id = p.buyer_id
            WHERE m.author_id = ?
            ORDER BY p.id DESC
            LIMIT 50'
        );
        $orders->execute([$sellerId]);

        return ['materials' => $materials->fetchAll(), 'orders' => $orders->fetchAll()];
    }

    // Файл может скачать автор материала и его покупатель. Возвращает строку файла или null.
    public function downloadable(int $fileId, int $userId): ?array
    {
        $query = $this->db->prepare(
            'SELECT f.storage_name, f.original_name, f.size_bytes
            FROM files f
            JOIN materials m ON m.id = f.material_id
            WHERE f.id = ? AND (
                m.author_id = ?
                OR EXISTS (SELECT 1 FROM purchases p WHERE p.material_id = m.id AND p.buyer_id = ?)
            )'
        );
        $query->execute([$fileId, $userId, $userId]);
        return $query->fetch() ?: null;
    }
}
