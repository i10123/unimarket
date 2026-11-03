<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$admin = json_decode(file_get_contents($root . '/storage/mysql-admin.json'), true, 8, JSON_THROW_ON_ERROR);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $admin['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS unimarket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE unimarket');
$pdo->exec(file_get_contents($root . '/database/schema.sql'));
$path = $root . '/config/local.php';
$config = is_file($path) ? require $path : [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=unimarket;charset=utf8mb4',
    'user' => 'unimarket', 'password' => bin2hex(random_bytes(24)),
];
$password = $pdo->quote($config['password']);
$pdo->exec("CREATE USER IF NOT EXISTS 'unimarket'@'127.0.0.1' IDENTIFIED BY $password");
$pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON unimarket.* TO 'unimarket'@'127.0.0.1'");
$config['dsn'] = 'mysql:host=127.0.0.1;port=3306;dbname=unimarket;charset=utf8mb4';
file_put_contents($path, "<?php\n// Локальные реквизиты; не публиковать.\nreturn " . var_export($config, true) . ";\n");
echo "База unimarket: 7 таблиц, отдельный пользователь приложения.\n";
