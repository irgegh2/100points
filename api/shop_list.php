<?php
// GET /shop_list.php — активные товары магазина + баланс ученика + какие once-only уже куплены.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$items = db()->query("SELECT id, name, description, category, price, once_only, badge FROM shop_items WHERE active = 1 ORDER BY position, id")->fetchAll();

// Уже оформленные once-only товары (заявка в работе или выдано) — повторно нельзя.
$ow = db()->prepare("SELECT DISTINCT item_id FROM purchases WHERE user_id = ? AND status IN ('pending','done')");
$ow->execute([$uid]);
$owned = array_map('intval', array_column($ow->fetchAll(), 'item_id'));

$w = g_wallet($uid);
$out = [];
foreach ($items as $it) {
  $instantItems = ['Тёмная тема', 'Неоновая тема', 'Тема «Космос»', 'Рамка для аватара', 'Цвет ника', '+1 жизнь', 'Бустер ×2 кристалла', 'VIP-статус навсегда'];
  $instant = in_array($it['name'], $instantItems, true);
  $out[] = [
    'id'          => (int)$it['id'],
    'name'        => $it['name'],
    'description' => $it['description'],
    'category'    => $it['category'],
    'price'       => (int)$it['price'],
    'once_only'   => (int)$it['once_only'] === 1,
    'badge'       => $it['badge'],
    'instant'     => $instant,
    // «Куплено» блокирует повторную покупку ТОЛЬКО для одноразовых товаров.
    // Многоразовые покупаются сколько угодно — их не помечаем.
    'owned'       => (int)$it['once_only'] === 1 && in_array((int)$it['id'], $owned, true),
  ];
}
ok([
  'items' => $out,
  'balance' => (int)$w['crystals'],
  'lives' => (int)$w['lives'],
  'max_lives' => g_max_lives($uid),
  'booster_until' => (string)($w['booster_until'] ?? ''),
  'vip' => g_is_vip($uid),
]);
