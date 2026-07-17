<?php
// POST /shop_buy.php  { item_id }
// Покупка: списывает кристаллы и создаёт заявку. Цифровое оформление
// активируется сразу, остальные товары сохраняют ручную выдачу через админку.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();

$in = body();
$itemId = (int)($in['item_id'] ?? 0);
if ($itemId <= 0) fail('Не указан товар', 400);

$it = db()->prepare("SELECT id, name, category, price, once_only FROM shop_items WHERE id = ? AND active = 1 LIMIT 1");
$it->execute([$itemId]);
$item = $it->fetch();
if (!$item) fail('Товар недоступен', 404);
$price = (int)$item['price'];
$themeCodes = [
  'Тёмная тема' => 'dark',
  'Неоновая тема' => 'neon',
  'Тема «Космос»' => 'space',
];
$themeCode = $item['category'] === 'theme' ? ($themeCodes[$item['name']] ?? null) : null;
$cosmeticCodes = [
  'Рамка для аватара' => 'avatar_frame',
  'Цвет ника' => 'nickname_color',
];
$cosmeticCode = $item['category'] === 'theme' ? ($cosmeticCodes[$item['name']] ?? null) : null;
$instant = $themeCode !== null || $cosmeticCode !== null;
$status = $instant ? 'done' : 'pending';

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

  // Блокируем кошелёк: параллельные клики не спишут деньги и не создадут дубль.
  $lockSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? 'SELECT crystals FROM wallets WHERE user_id = ? FOR UPDATE'
    : 'SELECT crystals FROM wallets WHERE user_id = ?';
  $lock = $pdo->prepare($lockSql);
  $lock->execute([$uid]);
  $lockedBalance = (int)$lock->fetchColumn();
  if ($lockedBalance < $price) throw new RuntimeException('insufficient');

  if ((int)$item['once_only'] === 1) {
    $dup = $pdo->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND item_id = ? AND status IN ('pending','done') LIMIT 1");
    $dup->execute([$uid, $itemId]);
    if ($dup->fetchColumn()) throw new RuntimeException('duplicate');
  }

  $pdo->prepare("UPDATE wallets SET crystals = crystals - ? WHERE user_id = ?")->execute([$price, $uid]);
  $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'crystals', ?, ?, 'purchase', ?)")
      ->execute([$uid, -$price, 'Покупка: ' . $item['name'], $itemId]);
  $pdo->prepare("INSERT INTO purchases (user_id, item_id, item_name, price, status, note, updated_at) VALUES (?,?,?,?, ?, ?, ?)")
      ->execute([$uid, $itemId, $item['name'], $price, $status, $instant ? 'Доступ активирован автоматически' : null, date('Y-m-d H:i:s')]);
  $pid = (int)$pdo->lastInsertId();
  $pdo->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  if ($e->getMessage() === 'duplicate') fail('Этот товар покупается один раз — он уже у тебя', 409);
  if ($e->getMessage() === 'insufficient') fail('Недостаточно кристаллов', 400);
  fail('Не удалось оформить покупку', 500);
}

$message = $instant
  ? "Покупка «{$item['name']}» активирована — оформление уже включено."
  : "Покупка «{$item['name']}» оформлена. Заявка принята — скоро выдадим.";
notify($uid, 'shop', $message, 'shop');

$w = g_wallet($uid);
ok([
  'purchase_id' => $pid,
  'balance' => (int)$w['crystals'],
  'status' => $status,
  'instant' => $instant,
  'theme_unlocked' => $themeCode !== null,
  'theme' => $themeCode,
  'cosmetic_unlocked' => $cosmeticCode !== null,
  'cosmetic' => $cosmeticCode,
]);
