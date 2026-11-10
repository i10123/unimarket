<?php
declare(strict_types=1);

// Подготовка среды в Open Server Panel: база unimarket, 7 таблиц,
// отдельный пользователь приложения и config/local.php.
// Запуск (панель и модуль MySQL-8.4 включены): php scripts/init-db.php
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
$host = 'mysql-8.4.local';
$dsn = "mysql:host=$host;port=3306;charset=utf8mb4";

// shortcut: root без пароля — настройка Open Server Panel по умолчанию; на хостинге задать свой.
$pdo = new PDO($dsn, 'root', getenv('MYSQL_ROOT_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS unimarket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE unimarket');

// Старая схема (резерв баллов, статусы покупок, замена файла): пересоздаём покупки, файлы и историю
// баллов, остальные данные сохраняются. Бесплатные завершённые покупки переносятся; платные и
// незавершённые нельзя пересчитать, поэтому при них скрипт останавливается, ничего не меняя.
$old = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'unimarket' AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'reserved'")->fetchColumn() > 0;
if ($old) {
    if ($pdo->query("SELECT COUNT(*) FROM purchases WHERE price > 0 OR status <> 'completed'")->fetchColumn() > 0) {
        exit("В базе есть платные или незавершённые покупки старого формата, перенос не предусмотрен. Удали базу unimarket в phpMyAdmin и запусти init-db и seed заново.\n");
    }
    $pdo->exec('CREATE TABLE purchases_copy AS SELECT id, buyer_id, material_id, price, purchased_at, rating FROM purchases');
    $pdo->exec('CREATE TABLE entries_copy AS SELECT user_id, material_id, kind, available_delta AS delta, created_at FROM point_entries');
    $pdo->exec('CREATE TABLE files_copy AS SELECT material_id, original_name, storage_name, mime_type, size_bytes, created_at FROM files');
    foreach (['point_entries', 'files', 'purchases'] as $table) {
        $pdo->exec("DROP TABLE $table");
    }
    $pdo->exec('ALTER TABLE wallets DROP COLUMN reserved');
    // Контакт у прежних пользователей пуст: они заполнят его в настройках.
    $pdo->exec("ALTER TABLE users ADD COLUMN contact VARCHAR(255) NOT NULL DEFAULT '' AFTER course");
    $pdo->exec('ALTER TABLE users ALTER COLUMN contact DROP DEFAULT');
}

// По одному запросу: exec() с несколькими сообщает об ошибке только первого.
foreach (preg_split('/;\s*$/m', file_get_contents($root . '/database/schema.sql')) as $sql) {
    if (trim($sql) !== '') {
        $pdo->exec($sql);
    }
}

if ($old) {
    $pdo->exec('INSERT INTO purchases (id, buyer_id, material_id, price, purchased_at, rating) SELECT * FROM purchases_copy');
    $pdo->exec('INSERT INTO point_entries (user_id, material_id, kind, delta, created_at) SELECT * FROM entries_copy');
    $pdo->exec('INSERT INTO files (material_id, original_name, storage_name, mime_type, size_bytes, created_at) SELECT * FROM files_copy');
    foreach (['entries_copy', 'files_copy', 'purchases_copy'] as $table) {
        $pdo->exec("DROP TABLE $table");
    }
    echo "Схема обновлена: покупки без резерва, у пользователей появился контакт.\n";
}

// Схема уже могла быть создана старой версией: ключи без ON DELETE CASCADE пересоздаём.
// Данные при этом не затрагиваются, повторный запуск ничего не меняет.
$keys = $pdo->query(
    "SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc
    FROM information_schema.KEY_COLUMN_USAGE k
    JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
    WHERE k.TABLE_SCHEMA = 'unimarket' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND r.DELETE_RULE <> 'CASCADE'"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($keys as $key) {
    $pdo->exec("ALTER TABLE {$key['t']} DROP FOREIGN KEY {$key['n']}");
    $pdo->exec("ALTER TABLE {$key['t']} ADD FOREIGN KEY ({$key['c']}) REFERENCES {$key['rt']}({$key['rc']}) ON DELETE CASCADE");
}
if ($keys) {
    echo 'Обновлено связей (ON DELETE CASCADE): ', count($keys), "\n";
}

$path = $root . '/config/local.php';
$config = is_file($path) ? require $path : ['user' => 'unimarket', 'password' => bin2hex(random_bytes(24))];
$config['dsn'] = "mysql:host=$host;port=3306;dbname=unimarket;charset=utf8mb4";

// Приложение подключается пользователем только с правами на данные, не root.
$password = $pdo->quote($config['password']);
$pdo->exec("CREATE USER IF NOT EXISTS 'unimarket'@'%' IDENTIFIED BY $password");
$pdo->exec("ALTER USER 'unimarket'@'%' IDENTIFIED BY $password");
$pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON unimarket.* TO 'unimarket'@'%'");
file_put_contents($path, "<?php\n// Локальные реквизиты; не публиковать.\nreturn " . var_export($config, true) . ";\n");
$tables = count($pdo->query('SHOW TABLES')->fetchAll());
echo "База unimarket: таблиц — $tables, отдельный пользователь приложения.\n";
