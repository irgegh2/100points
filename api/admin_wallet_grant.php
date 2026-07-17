<?php
// POST /admin_wallet_grant.php  { user_id, amount, reason? }
// Админ начисляет (или списывает при отрицательном amount) кристаллы ученику.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
require_admin();

$in = body();
$uid = (int)($in['user_id'] ?? 0);
$amount = (int)($in['amount'] ?? 0);
$reason = trim((string)($in['reason'] ?? '')) ?: 'Начислено администратором';
if ($uid <= 0) fail('Не указан ученик', 400);
if ($amount === 0) fail('Сумма не может быть 0', 400);

g_wallet($uid); // создаём кошелёк при необходимости
g_add_crystals($uid, $amount, $reason, 'admin', null);
$w = g_wallet($uid);
ok(['crystals' => (int)$w['crystals']]);
