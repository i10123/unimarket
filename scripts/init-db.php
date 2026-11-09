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
