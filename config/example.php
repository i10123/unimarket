<?php
// Рабочий config/local.php создаётся скриптом scripts/init-db.php и не попадает в Git.
return [
    // mysql-8.4.local — адрес модуля MySQL в Open Server Panel (прописан в hosts).
    'dsn' => 'mysql:host=mysql-8.4.local;port=3306;dbname=unimarket;charset=utf8mb4',
    'user' => 'unimarket',
    'password' => 'ЗАМЕНИТЬ_ЛОКАЛЬНЫМ_ПАРОЛЕМ',
];
