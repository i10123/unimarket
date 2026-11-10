-- Все связи с ON DELETE CASCADE: удаление пользователя в консоли администратора
-- убирает его кошелёк, материалы, покупки и историю баллов.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Логин: 3–32 символа, латиница в нижнем регистре, цифры, _ . - (проверяет PHP).
    login VARCHAR(32) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    -- Необязательны. Другим показывается то, что заполнено, иначе «User22». Письма не отправляются.
    first_name VARCHAR(60) NULL,
    last_name VARCHAR(60) NULL,
    email VARCHAR(255) NULL,
    course TINYINT UNSIGNED NULL CHECK (course BETWEEN 1 AND 6),
    -- Свободный текст (почта, телефон, Telegram, ссылка), от 5 символов. Виден всем: по нему пишут продавцу.
    contact VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallets (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    available BIGINT NOT NULL DEFAULT 0 CHECK (available >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS materials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    -- Тип: конспект, решённые задачи, шпаргалка.
    type ENUM('notes', 'solutions', 'cheatsheet') NOT NULL,
    course TINYINT UNSIGNED NULL CHECK (course BETWEEN 1 AND 6),
    price BIGINT NOT NULL CHECK (price >= 0),
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX catalog (status, created_at),
    INDEX filters (status, type, subject, course, price)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    price BIGINT NOT NULL CHECK (price >= 0),
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rating TINYINT UNSIGNED NULL CHECK (rating BETWEEN 1 AND 5),
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    UNIQUE KEY one_purchase (buyer_id, material_id),
    INDEX sales (material_id, purchased_at)
) ENGINE=InnoDB;

-- Файлы материала: их скачивают автор и покупатели.
CREATE TABLE IF NOT EXISTS files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    material_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name VARCHAR(100) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
    user_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, material_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Баланс и запись его изменения сохраняются в одной транзакции.
CREATE TABLE IF NOT EXISTS point_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NULL,
    material_id BIGINT UNSIGNED NULL,
    -- welcome: бонус при регистрации, reward: награда за бесплатный материал,
    -- purchase и sale: списание у покупателя и начисление автору.
    kind ENUM('welcome', 'reward', 'purchase', 'sale') NOT NULL,
    delta BIGINT NOT NULL CHECK (delta <> 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((kind = 'welcome' AND material_id IS NULL AND purchase_id IS NULL)
        OR (kind = 'reward' AND material_id IS NOT NULL AND purchase_id IS NULL)
        OR (kind IN ('purchase', 'sale') AND purchase_id IS NOT NULL AND material_id IS NULL)),
    FOREIGN KEY (user_id) REFERENCES wallets(user_id) ON DELETE CASCADE,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    UNIQUE KEY one_purchase_entry (user_id, purchase_id, kind),
    UNIQUE KEY one_material_reward (material_id, kind),
    INDEX history (user_id, created_at)
) ENGINE=InnoDB;
