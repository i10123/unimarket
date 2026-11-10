<?php
declare(strict_types=1);

// API покупок: /api/deals
//   GET  ?list=purchases|sales — мои покупки или покупки моих материалов;
//   POST action=buy или rate.

require __DIR__ . '/../bootstrap.php';

runApi(function (): void {
    $user = requireUser();
    $deals = new Deals(db());

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        match ($_GET['list'] ?? '') {
            'purchases' => respond(['items' => $deals->purchases($user['id'])]),
            'sales' => respond($deals->sales($user['id'])),
            default => throw new ApiError('Неизвестный список.', 400),
        };
    }

    $input = postInput();
    $purchaseId = (int) ($input['purchase_id'] ?? 0);
    $result = ['ok' => true];

    match ($input['action'] ?? '') {
        'buy' => $result = ['purchase_id' => $deals->buy($user['id'], (int) ($input['material_id'] ?? 0))],
        'rate' => $deals->rate($user['id'], $purchaseId, (int) ($input['rating'] ?? 0)),
        default => throw new ApiError('Неизвестное действие.', 400),
    };
    respond($result);
});
