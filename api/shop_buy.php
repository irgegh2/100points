<?php
// POST /shop_buy.php  { item_id }
// Покупка: списывает кристаллы (транзакция) и создаёт заявку (status pending) — выдаём вручную.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();

$in = body();
$itemId = (int)($in['item_id'] ?? 0);
if ($itemId <= 0) fail('Не указан товар', 400);

$it = db()->prepare("SELECT id, name, price, once_only FROM shop_items WHERE id = ? AND active = 1 LIMIT 1");
$it->execute([$itemId]);
$item = $it->fetch();
if (!$item) fail('Товар недоступен', 404);
$price = (int)$item['price'];

// once-only — нельзя купить повторно.
if ((int)$item['once_only'] === 1) {
  $c = db()->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND item_id = ? AND status IN ('pending','done') LIMIT 1");
  $c->execute([$uid, $itemId]);
  if ($c->fetch()) fail('Этот товар покупается один раз — он уже у тебя', 409);
}

$w = g_wallet($uid);
if ((int)$w['crystals'] < $price) fail('Недостаточно кристаллов', 400);

try {
  $pdo = db();
  $pdo->beginTransaction();
  $pdo->prepare("UPDATE wallets SET crystals = crystals - ? WHERE user_id = ?")->execute([$price, $uid]);
  $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'crystals', ?, ?, 'purchase', ?)")
      ->execute([$uid, -$price, 'Покупка: ' . $item['name'], $itemId]);
  $pdo->prepare("INSERT INTO purchases (user_id, item_id, item_name, price, status, updated_at) VALUES (?,?,?,?, 'pending', ?)")
      ->execute([$uid, $itemId, $item['name'], $price, date('Y-m-d H:i:s')]);
  $pid = (int)$pdo->lastInsertId();
  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось оформить покупку', 500);
}

notify($uid, 'shop', "Покупка «{$item['name']}» оформлена. Заявка принята — скоро выдадим.", 'shop');

$w = g_wallet($uid);
ok(['purchase_id' => $pid, 'balance' => (int)$w['crystals'], 'status' => 'pending']);
