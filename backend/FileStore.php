<?php
declare(strict_types=1);

// Файлы материалов. Принимаем любой формат (документы, архивы, картинки, код) до 15 МБ.
// Это безопасно, потому что файл никогда не выполняется и не открывается браузером: он лежит
// в storage/files под случайным именем без расширения, вне доступа из браузера, а отдаёт его
// только api/download.php с типом octet-stream и заголовком attachment после проверки прав.
final class FileStore
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    // Загруженные файлы поля формы: от $min до $max штук, каждый до 15 МБ.
    // Ничего не сохраняет: сохранение — store().
    public static function uploaded(string $field, int $min, int $max): array
    {
        $raw = $_FILES[$field] ?? ['name' => []];
        $files = [];
        foreach ((array) $raw['name'] as $i => $name) {
            $error = ((array) $raw['error'])[$i];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = self::check($name, ((array) $raw['tmp_name'])[$i], ((array) $raw['size'])[$i], $error);
        }

        if (count($files) < $min || count($files) > $max) {
            throw new ApiError($min === $max ? 'Нужно приложить один файл.' : "Приложи от $min до $max файлов.");
        }
        return $files;
    }

    private static function check(string $name, string $tmp, int $size, int $error): array
    {
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f\/\\\\]/u', '', basename($name)) ?? ''), 0, 255) ?: 'файл';
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $size > self::MAX_BYTES) {
            throw new ApiError("Файл «{$name}» больше " . self::MAX_BYTES / 1024 / 1024 . ' МБ.');
        }
        if ($error !== UPLOAD_ERR_OK || $size === 0) {
            throw new ApiError("Файл «{$name}» не удалось загрузить.");
        }
        // Тип по содержимому только записывается в БД, на доступ он не влияет.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        return ['name' => $name, 'tmp' => $tmp, 'size' => $size, 'mime' => $mime];
    }

    // Переносит файл в хранилище и возвращает строку для таблицы files.
    public static function store(array $file): array
    {
        $storageName = bin2hex(random_bytes(16));
        $target = self::path($storageName);
        $moved = is_uploaded_file($file['tmp']) ? move_uploaded_file($file['tmp'], $target) : rename($file['tmp'], $target);
        if (!$moved) {
            throw new RuntimeException('Не удалось сохранить файл');
        }
        return [$file['name'], $storageName, $file['mime'], $file['size']];
    }

    public static function path(string $storageName): string
    {
        return storageDir('files') . '/' . $storageName;
    }

    public static function delete(array $storageNames): void
    {
        foreach ($storageNames as $storageName) {
            @unlink(self::path($storageName));
        }
    }
}
