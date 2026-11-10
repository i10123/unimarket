<?php
declare(strict_types=1);

// API материалов: /api/market
//   GET  ?subjects     — список предметов;
//   GET  ?id=N         — страница материала;
//   GET  (фильтры)     — каталог, избранное (favorites=1) или материалы автора (author=N);
//   POST action=publish или update (формы с файлами), publish_state (on=true/false: вернуть или снять с публикации) или favorite.

require __DIR__ . '/../bootstrap.php';

runApi(function (): void {
    $user = requireUser();
    $market = new Market(db());

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['subjects'])) {
            respond(['subjects' => Market::SUBJECTS]);
        }
        respond(isset($_GET['id']) ? $market->show((int) $_GET['id'], $user['id']) : $market->search($_GET, $user['id']));
    }

    $input = postInput();
    match ($input['action'] ?? '') {
        'publish' => respond(['id' => $market->publish($user['id'], $input)], 201),
        'update' => $market->update($user['id'], (int) ($input['id'] ?? 0), $input),
        'publish_state' => $market->setPublished($user['id'], (int) ($input['id'] ?? 0), ($input['on'] ?? false) === true),
        'favorite' => $market->favorite($user['id'], (int) ($input['material_id'] ?? 0), ($input['on'] ?? false) === true),
        default => throw new ApiError('Неизвестное действие.', 400),
    };
    respond(['ok' => true]);
});
