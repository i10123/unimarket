<?php
declare(strict_types=1);

// Скачивание файла: /api/download?id=N. Закрытые файлы лежат вне доступа браузера,
// отдаём их только автору материала и покупателю.

require __DIR__ . '/../bootstrap.php';

runApi(function (): void {
    $user = requireUser();
    $file = (new Deals(db()))->downloadable((int) ($_GET['id'] ?? 0), $user['id']);
    $path = $file ? FileStore::path($file['storage_name']) : null;
    if (!$path || !is_file($path)) {
        throw new ApiError('Файл не найден или недоступен.', 404);
    }

    // Сессия больше не нужна: освобождаем её, чтобы долгая отдача не блокировала другие запросы.
    session_write_close();
    // Тип octet-stream и attachment: браузер сохраняет файл, а не выполняет его.
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['original_name']));
    header('Content-Length: ' . $file['size_bytes']);
    header('Cache-Control: private, no-store');
    readfile($path);
});
