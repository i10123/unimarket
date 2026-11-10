<?php
declare(strict_types=1);

// Правила полей, общие для регистрации, профиля и смены пароля. При ошибке бросают ApiError.

// Строка без пробелов по краям; пустая становится null.
function cleanText(mixed $value): ?string
{
    $text = is_string($value) ? trim($value) : '';
    return $text === '' ? null : $text;
}

// Логин без учёта регистра: Denis и denis — один аккаунт.
function readLogin(mixed $value): string
{
    $login = is_string($value) ? strtolower(trim($value)) : '';
    if (preg_match('/^[a-z0-9_.-]{3,32}$/D', $login) !== 1) {
        throw new ApiError('Логин не подходит: нужно от 3 до 32 символов, только латинские буквы, цифры, точка, дефис и подчёркивание.');
    }
    return $login;
}

function readPassword(mixed $value): string
{
    if (!is_string($value) || str_contains($value, "\0") || strlen($value) > 256 || mb_strlen($value) < 8) {
        throw new ApiError('Пароль: от 8 символов, не более 256 байт.');
    }
    return $value;
}

// Профиль: имя, фамилия, курс, email. Все поля необязательны, пустое значение — null.
function readProfile(array $input): array
{
    $names = [];
    foreach (['first_name', 'last_name'] as $field) {
        $names[$field] = cleanText($input[$field] ?? '');
        if ($names[$field] !== null && preg_match("/^[\p{L}][\p{L} '-]{0,59}$/uD", $names[$field]) !== 1) {
            throw new ApiError('Имя и фамилия: до 60 букв.');
        }
    }

    $email = cleanText($input['email'] ?? '');
    if ($email !== null && (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new ApiError('Проверь адрес почты.');
    }

    $course = cleanText($input['course'] ?? '');
    if ($course !== null && preg_match('/^[1-6]$/D', $course) !== 1) {
        throw new ApiError('Курс: от 1 до 6.');
    }

    return $names + ['email' => $email, 'course' => $course === null ? null : (int) $course];
}
