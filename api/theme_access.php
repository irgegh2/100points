<?php
// GET /theme_access.php — доступные ученику темы и косметика профиля.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$st = db()->prepare("SELECT DISTINCT i.name
  FROM purchases p
  JOIN shop_items i ON i.id = p.item_id
  WHERE p.user_id = ? AND p.status = 'done'
    AND i.category = 'theme'
    AND i.name IN ('Тёмная тема', 'Неоновая тема', 'Тема «Космос»', 'Мужская тема', 'Женская тема')");
$st->execute([$uid]);

$codes = [
  'Тёмная тема' => 'dark',
  'Неоновая тема' => 'neon',
  'Тема «Космос»' => 'space',
  'Мужская тема' => 'boys',
  'Женская тема' => 'girls',
];
$themes = ['light'];
foreach ($st->fetchAll() as $row) {
  $code = $codes[$row['name']] ?? null;
  if ($code && !in_array($code, $themes, true)) $themes[] = $code;
}

$vip = db()->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND item_name = 'VIP-статус навсегда' AND status = 'done' LIMIT 1");
$vip->execute([$uid]);
if ($vip->fetchColumn()) $themes[] = 'vip';

// Рамка — цифровая косметика: новые покупки выдаются сразу. Старые ожидающие
// заявки тоже считаем активными, потому что кристаллы за них уже списаны.
$frame = db()->prepare("SELECT 1
  FROM purchases p
  JOIN shop_items i ON i.id = p.item_id
  WHERE p.user_id = ? AND p.status IN ('pending','done')
    AND i.name = 'Рамка для аватара'
  LIMIT 1");
$frame->execute([$uid]);
$hasFrame = (bool)$frame->fetchColumn();

$nick = db()->prepare("SELECT 1
  FROM purchases p
  JOIN shop_items i ON i.id = p.item_id
  WHERE p.user_id = ? AND p.status IN ('pending','done')
    AND i.name = 'Цвет ника'
  LIMIT 1");
$nick->execute([$uid]);
$hasNickColor = (bool)$nick->fetchColumn();

$nicknameColor = '';
if ($hasNickColor) {
  $color = db()->prepare("SELECT nickname_color FROM profiles WHERE user_id = ? LIMIT 1");
  $color->execute([$uid]);
  $nicknameColor = (string)($color->fetchColumn() ?: '#7b5cff');
}

ok([
  'themes' => $themes,
  'avatar_frame' => $hasFrame,
  'nickname_color_access' => $hasNickColor,
  'nickname_color' => $nicknameColor,
]);
