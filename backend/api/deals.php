<?php
declare(strict_types=1);

// API покупок и продаж: /api/deals
//   GET  ?list=purchases|sales — мои покупки или мои продажи;
//   POST action=buy, confirm, problem, replace (форма с файлом), refund или rate.

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
        'confirm' => $deals->confirm($user['id'], $purchaseId),
        'problem' => $deals->reportProblem($user['id'], $purchaseId, (string) ($input['text'] ?? '')),
        'replace' => $deals->replaceFile($user['id'], $purchaseId, FileStore::uploaded('file', 1, 1)[0]),
        'refund' => $deals->refundBySeller($user['id'], $purchaseId),
        'rate' => $deals->rate($user['id'], $purchaseId, (int) ($input['rating'] ?? 0)),
        default => throw new ApiError('Неизвестное действие.', 400),
    };
    respond($result);
});
