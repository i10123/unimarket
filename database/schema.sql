CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Логин: 3–32 символа, латиница в нижнем регистре, цифры, _ . - (проверяет PHP).
    -- Он же показывается как имя продавца.
    login VARCHAR(32) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    course TINYINT UNSIGNED NULL CHECK (course BETWEEN 1 AND 6),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallets (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    available BIGINT NOT NULL DEFAULT 0 CHECK (available >= 0),
    reserved BIGINT NOT NULL DEFAULT 0 CHECK (reserved >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
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
    FOREIGN KEY (author_id) REFERENCES users(id),
    INDEX catalog (status, created_at),
    INDEX filters (status, type, subject, course, price)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    price BIGINT NOT NULL CHECK (price >= 0),
    status ENUM('pending', 'problem', 'completed', 'refunded') NOT NULL DEFAULT 'pending',
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at DATETIME NOT NULL,
    problem TEXT NULL,
    problem_at DATETIME NULL,
    replaced_at DATETIME NULL,
    completed_at DATETIME NULL,
    rating TINYINT UNSIGNED NULL CHECK (rating BETWEEN 1 AND 5),
    CHECK (rating IS NULL OR status = 'completed'),
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (material_id) REFERENCES materials(id),
    UNIQUE KEY one_purchase (buyer_id, material_id),
    INDEX deadlines (status, deadline_at),
    INDEX sales (material_id, status, purchased_at)
) ENGINE=InnoDB;

-- Исходные файлы относятся к материалу, замена — только к конкретной покупке.
CREATE TABLE IF NOT EXISTS files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    material_id BIGINT UNSIGNED NULL,
    purchase_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name VARCHAR(100) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((material_id IS NOT NULL) + (purchase_id IS NOT NULL) = 1),
    FOREIGN KEY (material_id) REFERENCES materials(id),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
    user_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, material_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB;

-- Баланс и запись его изменения сохраняются в одной транзакции.
CREATE TABLE IF NOT EXISTS point_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NULL,
    material_id BIGINT UNSIGNED NULL,
    kind ENUM('reward', 'reserve', 'release', 'refund') NOT NULL,
    available_delta BIGINT NOT NULL,
    reserved_delta BIGINT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (available_delta <> 0 OR reserved_delta <> 0),
    CHECK ((kind = 'reward' AND material_id IS NOT NULL AND purchase_id IS NULL)
        OR (kind <> 'reward' AND purchase_id IS NOT NULL AND material_id IS NULL)),
    FOREIGN KEY (user_id) REFERENCES wallets(user_id),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    FOREIGN KEY (material_id) REFERENCES materials(id),
    UNIQUE KEY one_purchase_entry (user_id, purchase_id, kind),
    UNIQUE KEY one_material_reward (material_id, kind),
    INDEX history (user_id, created_at)
) ENGINE=InnoDB;
