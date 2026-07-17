<?php
// GET /theme_access.php — список купленных тем оформления ученика.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$st = db()->prepare("SELECT DISTINCT i.name
  FROM purchases p
  JOIN shop_items i ON i.id = p.item_id
  WHERE p.user_id = ? AND p.status = 'done'
    AND i.category = 'theme'
    AND i.name IN ('Тёмная тема', 'Неоновая тема', 'Тема «Космос»')");
$st->execute([$uid]);

$codes = [
  'Тёмная тема' => 'dark',
  'Неоновая тема' => 'neon',
  'Тема «Космос»' => 'space',
];
$themes = ['light'];
foreach ($st->fetchAll() as $row) {
  $code = $codes[$row['name']] ?? null;
  if ($code && !in_array($code, $themes, true)) $themes[] = $code;
}

ok(['themes' => $themes]);
