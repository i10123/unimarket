<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('UNIMARKET_SHUTDOWN') !== '1') exit;
$admin = json_decode(file_get_contents(__DIR__ . '/../storage/mysql-admin.json'), true, 8, JSON_THROW_ON_ERROR);
$db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', $admin['password']);
$db->exec('SHUTDOWN');
