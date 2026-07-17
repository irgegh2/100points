<?php
// GET /subscription_get.php — тарифы + текущая подписка ученика (для раздела «Подписка»).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session(); // read-only — снимаем лок сессии сразу

// Все тарифы.
$plans = [];
foreach (db()->query("SELECT id, name, tagline, price, features, badge FROM plans ORDER BY position, id") as $p) {
  $plans[] = [
    'id'       => (int)$p['id'],
    'name'     => $p['name'],
    'tagline'  => $p['tagline'],
    'price'    => $p['price'] !== null ? (int)$p['price'] : null,
    'features' => json_decode($p['features'] ?? '[]', true) ?: [],
    'badge'    => $p['badge'],
  ];
}

// Текущая активная подписка ученика (если есть).
$cstmt = db()->prepare("SELECT plan_id, status, started_at, ends_at FROM subscriptions
                        WHERE user_id = ? AND status = 'active'
                        ORDER BY id DESC LIMIT 1");
$cstmt->execute([$uid]);
$cur = $cstmt->fetch();

ok([
  'plans'   => $plans,
  'current' => $cur ? [
    'plan_id'  => $cur['plan_id'] !== null ? (int)$cur['plan_id'] : null,
    'status'   => $cur['status'],
    'ends_at'  => $cur['ends_at'],
  ] : null,
]);
