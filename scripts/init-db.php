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
// По одному запросу: exec() с несколькими сообщает об ошибке только первого.
foreach (preg_split('/;\s*$/m', file_get_contents($root . '/database/schema.sql')) as $sql) {
    if (trim($sql) !== '') {
        $pdo->exec($sql);
    }
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
