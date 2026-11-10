<?php
declare(strict_types=1);

// Покупки: резерв баллов, подтверждение, обращение, замена файла, возврат, оценка.
//
// Жизнь покупки:
//   pending   — баллы в резерве покупателя, файлы открыты; 24 часа на обращение, потом автоподтверждение;
//   problem   — покупатель сообщил о проблеме; у продавца 24 часа на замену файла или возврат;
//   completed — баллы у продавца (подтверждение, автоподтверждение или замена);
//   refunded  — баллы вернулись покупателю.
final class Deals
{
    public function __construct(private PDO $db)
    {
    }

    // Покупка материала. Платная уходит в резерв, бесплатная сразу завершена без движения баллов.
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

                // Блокировка кошелька: двойной щелчок не спишет баллы дважды.
                $query = $this->db->prepare('SELECT available FROM wallets WHERE user_id = ? FOR UPDATE');
                $query->execute([$buyerId]);
                $price = $material['price'];
                if ($query->fetchColumn() < $price) {
                    throw new ApiError('Не хватает баллов для покупки.', 409);
                }

                $this->db
                    ->prepare('INSERT INTO purchases (buyer_id, material_id, price, status, deadline_at, completed_at)
                        VALUES (?, ?, ?, ?, NOW() + INTERVAL 24 HOUR, IF(? = 0, NOW(), NULL))')
                    ->execute([$buyerId, $materialId, $price, $price === 0 ? 'completed' : 'pending', $price]);
                $id = (int) $this->db->lastInsertId();

                if ($price > 0) {
                    Points::add($this->db, $buyerId, 'reserve', -$price, $price, $id);
                }
                return $id;
            });
        } catch (PDOException $error) {
            // 1062 — повторная покупка: баллы уже не списываем.
            throw ($error->errorInfo[1] ?? null) === 1062 ? new ApiError('Материал уже куплен.', 409) : $error;
        }
    }

    // Покупатель подтверждает покупку: резерв переходит продавцу.
    public function confirm(int $buyerId, int $purchaseId): void
    {
        transaction(function () use ($buyerId, $purchaseId): void {
            $purchase = $this->find($purchaseId);
            // Чужая покупка выглядит как отсутствующая.
            if (!$purchase || $purchase['buyer_id'] !== $buyerId) {
                throw new ApiError('Покупка не найдена.', 404);
            }
            if ($purchase['status'] !== 'pending') {
                throw new ApiError('Эта покупка уже не ждёт подтверждения.', 409);
            }
            $this->complete($purchase);
        });
    }

    // Обращение «Проблема с файлом»: только в первые 24 часа, останавливает автоподтверждение.
    public function reportProblem(int $buyerId, int $purchaseId, string $text): void
    {
        $text = trim($text);
        if (mb_strlen($text) < 10 || mb_strlen($text) > 1000) {
            throw new ApiError('Опиши проблему: от 10 до 1000 символов.');
        }
        $query = $this->db->prepare(
            "UPDATE purchases
            SET status = 'problem', problem = ?, problem_at = NOW(), deadline_at = NOW() + INTERVAL 24 HOUR
            WHERE id = ? AND buyer_id = ? AND status = 'pending' AND deadline_at > NOW()"
        );
        $query->execute([$text, $purchaseId, $buyerId]);
        if ($query->rowCount() === 0) {
            throw new ApiError('Срок обращения прошёл или оно уже отправлено.', 409);
        }
    }

    // Одна замена: файл загружен в срок — покупка завершена, продавец получает баллы.
    public function replaceFile(int $sellerId, int $purchaseId, array $file): void
    {
        $stored = null;
        try {
            transaction(function () use ($sellerId, $purchaseId, $file, &$stored): void {
                $purchase = $this->lockForSeller($purchaseId, $sellerId);
                $row = FileStore::store($file);
                $stored = $row[1];
                $this->db
                    ->prepare('INSERT INTO files (purchase_id, original_name, storage_name, mime_type, size_bytes)
                        VALUES (?, ?, ?, ?, ?)')
                    ->execute([$purchaseId, ...$row]);
                $this->db->prepare('UPDATE purchases SET replaced_at = NOW() WHERE id = ?')->execute([$purchaseId]);
                $this->complete($purchase);
            });
        } catch (Throwable $error) {
            FileStore::delete($stored === null ? [] : [$stored]);
            throw $error;
        }
    }

    // Продавец сам возвращает баллы покупателю.
    public function refundBySeller(int $sellerId, int $purchaseId): void
    {
        transaction(fn() => $this->refund($this->lockForSeller($purchaseId, $sellerId)));
    }

    // Оценка продавца 1–5 после завершения покупки; ставится один раз.
    public function rate(int $buyerId, int $purchaseId, int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new ApiError('Оценка: от 1 до 5.');
        }
        $query = $this->db->prepare(
            "UPDATE purchases SET rating = ? WHERE id = ? AND buyer_id = ? AND status = 'completed' AND rating IS NULL"
        );
        $query->execute([$rating, $purchaseId, $buyerId]);
        if ($query->rowCount() === 0) {
            throw new ApiError('Оценить можно завершённую покупку один раз.', 409);
        }
    }

    // Планировщика нет, поэтому просроченные покупки закрываются при первом обращении любого
    // пользователя: без обращения — подтверждение, с обращением без ответа — возврат.
    public function settleOverdue(): void
    {
        $this->closeOpen("SELECT id FROM purchases WHERE status IN ('pending', 'problem') AND deadline_at <= NOW()", [], true);
    }

    // Перед удалением продавца: открытые покупки его материалов возвращаются покупателям.
    public function refundOpenSales(int $sellerId): void
    {
        $this->closeOpen(
            "SELECT p.id FROM purchases p JOIN materials m ON m.id = p.material_id
            WHERE m.author_id = ? AND p.status IN ('pending', 'problem')",
            [$sellerId],
            false
        );
    }

    // Каждую найденную покупку закрывает в своей транзакции. $settle = true: срок вышел,
    // ожидающая покупка подтверждается; иначе баллы возвращаются в любом открытом статусе.
    private function closeOpen(string $sql, array $args, bool $settle): void
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                transaction(function () use ($id, $settle): void {
                    $purchase = $this->find($id);
                    // Покупку уже закрыл другой запрос или срок ещё не вышел — пропускаем.
                    if (!$purchase || !in_array($purchase['status'], ['pending', 'problem'], true) || ($settle && !$purchase['overdue'])) {
                        return;
                    }
                    if ($settle && $purchase['status'] === 'pending') {
                        $this->complete($purchase);
                    } else {
                        $this->refund($purchase);
                    }
                });
            } catch (Throwable $error) {
                // Одна испорченная покупка не должна ломать сайт для всех.
                if (!$settle) {
                    throw $error;
                }
                error_log((string) $error);
            }
        }
    }

    // Мои покупки с файлами (исходными и присланной заменой).
    public function purchases(int $buyerId): array
    {
        $query = $this->db->prepare(
            'SELECT p.id, p.material_id, m.title, m.type, m.course, p.price, p.status, p.purchased_at,
                p.deadline_at, p.problem, p.rating, m.author_id, ' . DISPLAY_NAME . ' AS seller_name
            FROM purchases p
            JOIN materials m ON m.id = p.material_id
            JOIN users u ON u.id = m.author_id
            WHERE p.buyer_id = ?
            ORDER BY p.id DESC'
        );
        $query->execute([$buyerId]);

        // Файлы всех покупок одним запросом: файлы материала и замена именно этой покупки.
        // Возвращённая покупка файлов не открывает.
        $files = $this->db->prepare(
            "SELECT p.id AS purchase_id, f.id, f.original_name AS name, f.size_bytes AS size
            FROM purchases p
            JOIN files f ON f.purchase_id = p.id OR f.material_id = p.material_id
            WHERE p.buyer_id = ? AND p.status <> 'refunded'
            ORDER BY f.id"
        );
        $files->execute([$buyerId]);
        $byPurchase = [];
        foreach ($files->fetchAll() as $file) {
            $byPurchase[$file['purchase_id']][] = $file;
        }

        return array_map(fn(array $row): array => $row + ['files' => $byPurchase[$row['id']] ?? []], $query->fetchAll());
    }

    // Мои продажи: публикации со счётчиком покупок и последние покупки моих материалов.
    public function sales(int $sellerId): array
    {
        $materials = $this->db->prepare(
            "SELECT m.id, m.title, m.type, m.course, m.price, m.created_at, COUNT(p.id) AS sales
            FROM materials m
            LEFT JOIN purchases p ON p.material_id = m.id AND p.status <> 'refunded'
            WHERE m.author_id = ?
            GROUP BY m.id
            ORDER BY m.id DESC"
        );
        $materials->execute([$sellerId]);

        $orders = $this->db->prepare(
            'SELECT p.id, m.title, p.price, p.status, p.purchased_at, p.deadline_at, p.problem,
                ' . DISPLAY_NAME . ' AS buyer_name
            FROM purchases p
            JOIN materials m ON m.id = p.material_id
            JOIN users u ON u.id = p.buyer_id
            WHERE m.author_id = ?
            ORDER BY p.status = \'problem\' DESC, p.id DESC
            LIMIT 50'
        );
        $orders->execute([$sellerId]);

        return ['materials' => $materials->fetchAll(), 'orders' => $orders->fetchAll()];
    }

    // Файл может скачать автор материала и покупатель, кроме возвращённой покупки. Замену видит
    // только покупатель, которому она адресована. Возвращает строку файла или null.
    public function downloadable(int $fileId, int $userId): ?array
    {
        $query = $this->db->prepare(
            "SELECT f.storage_name, f.original_name, f.size_bytes
            FROM files f
            LEFT JOIN purchases p ON p.id = f.purchase_id
            JOIN materials m ON m.id = COALESCE(f.material_id, p.material_id)
            WHERE f.id = ? AND (
                m.author_id = ?
                OR (p.id IS NOT NULL AND p.buyer_id = ? AND p.status <> 'refunded')
                OR (f.material_id IS NOT NULL
                    AND EXISTS (SELECT 1 FROM purchases b WHERE b.material_id = m.id AND b.buyer_id = ? AND b.status <> 'refunded'))
            )"
        );
        $query->execute([$fileId, $userId, $userId, $userId]);
        return $query->fetch() ?: null;
    }

    // Покупка вместе с автором материала под блокировкой до конца транзакции;
    // overdue = 1, если срок уже вышел (время считает БД, как и везде).
    private function find(int $purchaseId): ?array
    {
        $query = $this->db->prepare(
            'SELECT p.*, m.author_id, p.deadline_at <= NOW() AS overdue
            FROM purchases p JOIN materials m ON m.id = p.material_id
            WHERE p.id = ? FOR UPDATE'
        );
        $query->execute([$purchaseId]);
        return $query->fetch() ?: null;
    }

    // Продавец отвечает на обращение один раз и в срок.
    private function lockForSeller(int $purchaseId, int $sellerId): array
    {
        $purchase = $this->find($purchaseId);
        if (!$purchase || $purchase['author_id'] !== $sellerId) {
            throw new ApiError('Покупка не найдена.', 404);
        }
        if ($purchase['status'] !== 'problem' || $purchase['overdue']) {
            throw new ApiError('Ответить на обращение уже нельзя.', 409);
        }
        return $purchase;
    }

    // Резерв покупателя переходит продавцу. Вызывать в транзакции, строка покупки заблокирована.
    private function complete(array $purchase): void
    {
        $this->db->prepare("UPDATE purchases SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$purchase['id']]);
        if ($purchase['price'] > 0) {
            Points::lock($this->db, $purchase['buyer_id'], $purchase['author_id']);
            Points::add($this->db, $purchase['buyer_id'], 'release', 0, -$purchase['price'], $purchase['id']);
            Points::add($this->db, $purchase['author_id'], 'release', $purchase['price'], 0, $purchase['id']);
        }
    }

    // Резерв возвращается покупателю. Вызывать в транзакции, строка покупки заблокирована.
    private function refund(array $purchase): void
    {
        $this->db->prepare("UPDATE purchases SET status = 'refunded' WHERE id = ?")->execute([$purchase['id']]);
        Points::add($this->db, $purchase['buyer_id'], 'refund', $purchase['price'], -$purchase['price'], $purchase['id']);
    }
}
