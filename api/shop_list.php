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
$eligibleDeadlineCount = count(g_eligible_homeworks_for_extension($uid));
$streakFreezes = g_available_streak_freezes($uid);
$out = [];
foreach ($items as $it) {
  $instantItems = ['Тёмная тема', 'Неоновая тема', 'Тема «Космос»', 'Рамка для аватара', 'Цвет ника', 'Заморозка стрика', 'Продление дедлайна +24ч', '+1 жизнь', 'Бустер ×2 кристалла', 'VIP-статус навсегда', 'Стобалльный диск +1 ГБ'];
  $instant = in_array($it['name'], $instantItems, true);
  $comingSoon = in_array($it['name'], ['Секретный конспект', 'Запись закрытого вебинара'], true);
  $out[] = [
    'id'          => (int)$it['id'],
    'name'        => $it['name'],
    'description' => $it['description'],
    'category'    => $it['category'],
    'price'       => (int)$it['price'],
    'once_only'   => (int)$it['once_only'] === 1,
    'badge'       => $it['badge'],
    'instant'     => $instant,
    'coming_soon' => $comingSoon,
    'eligible_count' => $it['name'] === 'Продление дедлайна +24ч' ? $eligibleDeadlineCount : null,
    // «Куплено» блокирует повторную покупку ТОЛЬКО для одноразовых товаров.
    // Многоразовые покупаются сколько угодно — их не помечаем.
    'owned'       => (int)$it['once_only'] === 1 && in_array((int)$it['id'], $owned, true),
  ];
}
$driveBonus = db()->prepare("SELECT bonus_bytes FROM drive_quotas WHERE user_id = ? LIMIT 1");
$driveBonus->execute([$uid]);
ok([
  'items' => $out,
  'balance' => (int)$w['crystals'],
  'lives' => (int)$w['lives'],
  'max_lives' => g_max_lives($uid),
  'booster_until' => (string)($w['booster_until'] ?? ''),
  'vip' => g_is_vip($uid),
  'streak_freezes' => $streakFreezes,
  'drive_bonus_bytes' => (int)($driveBonus->fetchColumn() ?: 0),
]);
