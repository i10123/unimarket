<?php
declare(strict_types=1);

// Материалы: публикация, каталог, страница материала, профиль автора, избранное.
final class Market
{
    public const TYPES = ['notes', 'solutions', 'cheatsheet'];
    public const SUBJECTS = ['Математика', 'Информатика', 'Физика', 'Экономика', 'Право', 'Английский язык', 'История', 'Химия'];

    private const PER_DAY = 5;
    private const MAX_FILES = 10;
    private const MAX_PRICE = 100000;
    private const FREE_REWARD = 25;
    private const PAGE_SIZE = 12;

    // Средняя оценка и число оценок каждого продавца: подключается к запросам как таблица r.
    public const RATINGS = '(SELECT m2.author_id, ROUND(AVG(p.rating), 1) AS average, COUNT(p.rating) AS votes
        FROM purchases p JOIN materials m2 ON m2.id = p.material_id
        WHERE p.rating IS NOT NULL GROUP BY m2.author_id) r';

    // Карточка материала: поля, имя и оценка автора, моё избранное (первый ? — id пользователя).
    private const CARD_SELECT = 'SELECT m.id, m.title, m.description, m.type, m.subject, m.course, m.price, m.author_id, m.status,
            r.average AS author_rating, r.votes AS author_votes, u.contact AS author_contact, ' . DISPLAY_NAME . ' AS author_name,
            EXISTS(SELECT 1 FROM favorites f WHERE f.user_id = ? AND f.material_id = m.id) AS is_favorite
        FROM materials m
        JOIN users u ON u.id = m.author_id
        LEFT JOIN ' . self::RATINGS . ' ON r.author_id = m.author_id ';

    public function __construct(private PDO $db)
    {
    }

    // Поля формы публикации и редактирования. Возвращает: название, описание, предмет, тип, курс, цена.
    private function readFields(array $input): array
    {
        // Поле-массив (title[]=…) считается пустым, а не превращается в слово «Array».
        $text = fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        [$title, $description, $price, $course] = [$text('title'), $text('description'), $text('price'), $text('course')];

        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) {
            throw new ApiError('Название: от 3 до 180 символов.');
        }
        if (mb_strlen($description) < 10 || mb_strlen($description) > 3000) {
            throw new ApiError('Описание: от 10 до 3000 символов.');
        }
        if (!in_array($input['type'] ?? '', self::TYPES, true)) {
            throw new ApiError('Выбери тип материала.');
        }
        if (!in_array($input['subject'] ?? '', self::SUBJECTS, true)) {
            throw new ApiError('Выбери предмет.');
        }
        if (preg_match('/^[1-6]$/D', $course) !== 1) {
            throw new ApiError('Выбери курс.');
        }
        if (preg_match('/^\d{1,6}$/D', $price) !== 1 || (int) $price > self::MAX_PRICE) {
            throw new ApiError('Цена: целое число от 0 до ' . self::MAX_PRICE . ' баллов.');
        }
        return [$title, $description, $input['subject'], $input['type'], (int) $course, (int) $price];
    }

    // Публикация из формы: поля и файлы проверяются здесь, запись в create().
    public function publish(int $userId, array $input): int
    {
        return $this->create($userId, $this->readFields($input), FileStore::uploaded('files', 1, self::MAX_FILES));
    }

    // Редактирование своего материала: поля, удаление отмеченных файлов (remove[]) и добавление новых.
    // Цена влияет только на будущие покупки, награда за бесплатный материал начисляется один раз при публикации.
    public function update(int $userId, int $id, array $input): void
    {
        $fields = $this->readFields($input);
        $remove = array_map('intval', is_array($input['remove'] ?? null) ? $input['remove'] : []);
        $added = FileStore::uploaded('files', 0, self::MAX_FILES);
        $stored = [];
        $deleted = [];

        try {
            transaction(function () use ($userId, $id, $fields, $remove, $added, &$stored, &$deleted): void {
                $query = $this->db->prepare('SELECT author_id FROM materials WHERE id = ? FOR UPDATE');
                $query->execute([$id]);
                if ($query->fetchColumn() !== $userId) {
                    throw new ApiError('Материал не найден.', 404);
                }

                $query = $this->db->prepare('SELECT id, storage_name FROM files WHERE material_id = ?');
                $query->execute([$id]);
                $current = $query->fetchAll(PDO::FETCH_KEY_PAIR);
                $gone = array_intersect_key($current, array_flip($remove));
                $deleted = array_values($gone);
                $left = count($current) - count($gone) + count($added);
                if ($left < 1 || $left > self::MAX_FILES) {
                    throw new ApiError('У материала должно остаться от 1 до ' . self::MAX_FILES . ' файлов.');
                }

                $this->db
                    ->prepare('UPDATE materials SET title = ?, description = ?, subject = ?, type = ?, course = ?, price = ? WHERE id = ?')
                    ->execute([...$fields, $id]);
                foreach (array_keys($gone) as $fileId) {
                    $this->db->prepare('DELETE FROM files WHERE id = ?')->execute([$fileId]);
                }
                foreach ($added as $file) {
                    $row = FileStore::store($file);
                    $stored[] = $row[1];
                    $this->db
                        ->prepare('INSERT INTO files (material_id, original_name, storage_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$id, ...$row]);
                }
            });
        } catch (Throwable $error) {
            FileStore::delete($stored);
            throw $error;
        }
        FileStore::delete($deleted);
    }

    // Снять свой материал с публикации или вернуть. В каталоге архивный не виден, покупатели его файлы сохраняют.
    public function setPublished(int $userId, int $id, bool $on): void
    {
        $query = $this->db->prepare('SELECT 1 FROM materials WHERE id = ? AND author_id = ?');
        $query->execute([$id, $userId]);
        if (!$query->fetchColumn()) {
            throw new ApiError('Материал не найден.', 404);
        }
        $this->db->prepare('UPDATE materials SET status = ? WHERE id = ?')->execute([$on ? 'published' : 'archived', $id]);
    }

    // $fields: название, описание, предмет, тип, курс, цена. $files — результат FileStore::uploaded().
    // Материал, файлы и награда сохраняются вместе; при сбое файлы удаляются с диска.
    public function create(int $userId, array $fields, array $files): int
    {
        $stored = [];
        try {
            return transaction(function () use ($userId, $fields, $files, &$stored): int {
                // Блокировка кошелька автора: два одновременных запроса не обойдут лимит в день.
                Points::lock($this->db, $userId);
                $query = $this->db->prepare(
                    'SELECT COUNT(*) FROM materials WHERE author_id = ? AND created_at > NOW() - INTERVAL 1 DAY'
                );
                $query->execute([$userId]);
                if ((int) $query->fetchColumn() >= self::PER_DAY) {
                    throw new ApiError('Не больше ' . self::PER_DAY . ' материалов в день. Попробуй завтра.', 429);
                }

                $this->db
                    ->prepare("INSERT INTO materials (author_id, title, description, subject, type, course, price, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'published')")
                    ->execute([$userId, ...$fields]);
                $id = (int) $this->db->lastInsertId();

                foreach ($files as $file) {
                    $row = FileStore::store($file);
                    $stored[] = $row[1];
                    $this->db
                        ->prepare('INSERT INTO files (material_id, original_name, storage_name, mime_type, size_bytes)
                            VALUES (?, ?, ?, ?, ?)')
                        ->execute([$id, ...$row]);
                }

                // Награда только за бесплатный материал; за платный автор получит цену при продаже.
                if ($fields[5] === 0) {
                    Points::add($this->db, $userId, 'reward', self::FREE_REWARD, null, $id);
                }
                return $id;
            });
        } catch (Throwable $error) {
            FileStore::delete($stored);
            throw $error;
        }
    }

    // Каталог с фильтрами q, type, subject, course, min, max, author, favorites и сортировкой sort
    // (old, price_asc, price_desc, votes_desc, votes_asc; по умолчанию сначала новые).
    // Отдаёт порцию PAGE_SIZE карточек с позиции offset и общее число найденных материалов.
    public function search(array $query, int $userId): array
    {
        $where = ["m.status = 'published'"];
        $args = [];

        $text = mb_substr(trim((string) ($query['q'] ?? '')), 0, 100);
        if ($text !== '') {
            $like = '%' . addcslashes($text, '%_\\') . '%';
            $where[] = '(m.title LIKE ? OR m.description LIKE ? OR m.subject LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        foreach (['type' => self::TYPES, 'subject' => self::SUBJECTS] as $field => $allowed) {
            if (in_array($query[$field] ?? '', $allowed, true)) {
                $where[] = "m.$field = ?";
                $args[] = $query[$field];
            }
        }
        foreach (['course' => 'm.course = ?', 'min' => 'm.price >= ?', 'max' => 'm.price <= ?', 'author' => 'm.author_id = ?'] as $field => $condition) {
            if (preg_match('/^\d{1,9}$/D', (string) ($query[$field] ?? '')) === 1) {
                $where[] = $condition;
                $args[] = (int) $query[$field];
            }
        }
        if (($query['favorites'] ?? '') === '1') {
            $where[] = 'm.id IN (SELECT material_id FROM favorites WHERE user_id = ?)';
            $args[] = $userId;
        }
        // В общем каталоге не показываем купленное (оно лежит в «Мои покупки») и свои материалы («Мои материалы»).
        if (!isset($query['author']) && !isset($query['favorites'])) {
            $where[] = 'm.author_id <> ? AND NOT EXISTS (SELECT 1 FROM purchases b WHERE b.material_id = m.id AND b.buyer_id = ?)';
            array_push($args, $userId, $userId);
        }

        $sort = is_string($query['sort'] ?? null) ? $query['sort'] : '';
        $order = [
            'old' => 'm.id ASC',
            'price_asc' => 'm.price ASC, m.id DESC',
            'price_desc' => 'm.price DESC, m.id DESC',
            'votes_desc' => 'COALESCE(r.votes, 0) DESC, m.id DESC',
            'votes_asc' => 'COALESCE(r.votes, 0) ASC, m.id DESC',
        ][$sort] ?? 'm.id DESC';
        $offset = preg_match('/^\d{1,6}$/D', (string) ($query['offset'] ?? '')) === 1 ? (int) $query['offset'] : 0;
        $conditions = implode(' AND ', $where);

        $count = $this->db->prepare("SELECT COUNT(*) FROM materials m WHERE $conditions");
        $count->execute($args);

        $list = $this->db->prepare(
            self::CARD_SELECT . "WHERE $conditions ORDER BY $order LIMIT " . self::PAGE_SIZE . " OFFSET $offset"
        );
        $list->execute([$userId, ...$args]);

        $result = ['items' => $list->fetchAll(), 'total' => (int) $count->fetchColumn()];
        if (isset($query['author'])) {
            $result['author'] = $this->author((int) $query['author']);
        }
        return $result;
    }

    // Профиль автора: имя для показа, контакт, курс, оценка, число материалов.
    private function author(int $id): ?array
    {
        $query = $this->db->prepare(
            'SELECT u.id, ' . DISPLAY_NAME . ' AS name, u.contact, u.course, r.average, r.votes,
                (SELECT COUNT(*) FROM materials WHERE author_id = u.id AND status = \'published\') AS materials
            FROM users u
            LEFT JOIN ' . self::RATINGS . ' ON r.author_id = u.id
            WHERE u.id = ?'
        );
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    // Страница материала: описание, автор, мои права. Список файлов виден всем,
    // но скачать их может только автор или покупатель (проверяет api/download.php).
    // Снятый с публикации материал открывается только его автору и покупателям.
    public function show(int $id, int $userId): array
    {
        $query = $this->db->prepare(self::CARD_SELECT . "WHERE m.id = ? AND (m.status = 'published' OR m.author_id = ?
            OR EXISTS (SELECT 1 FROM purchases b WHERE b.material_id = m.id AND b.buyer_id = ?))");
        $query->execute([$userId, $id, $userId, $userId]);
        $material = $query->fetch() ?: throw new ApiError('Материал не найден.', 404);

        $files = $this->db->prepare('SELECT id, original_name AS name, size_bytes AS size FROM files WHERE material_id = ? ORDER BY id');
        $files->execute([$id]);
        $purchase = $this->db->prepare('SELECT id FROM purchases WHERE buyer_id = ? AND material_id = ?');
        $purchase->execute([$userId, $id]);

        return [
            'material' => $material,
            'files' => $files->fetchAll(),
            'purchase' => $purchase->fetch() ?: null,
            'own' => $material['author_id'] === $userId,
        ];
    }

    // Добавляет или убирает материал из избранного; повтор не создаёт дублей.
    public function favorite(int $userId, int $materialId, bool $on): void
    {
        if ($on) {
            $this->db
                ->prepare("INSERT IGNORE INTO favorites (user_id, material_id)
                    SELECT ?, id FROM materials WHERE id = ? AND status = 'published'")
                ->execute([$userId, $materialId]);
        } else {
            $this->db->prepare('DELETE FROM favorites WHERE user_id = ? AND material_id = ?')->execute([$userId, $materialId]);
        }
    }
}
