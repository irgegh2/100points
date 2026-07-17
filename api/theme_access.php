<?php
// GET /theme_access.php — можно ли ученику включать купленную тёмную тему.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$st = db()->prepare("SELECT 1
  FROM purchases p
  JOIN shop_items i ON i.id = p.item_id
  WHERE p.user_id = ? AND p.status = 'done'
    AND i.category = 'theme' AND i.name = 'Тёмная тема'
  LIMIT 1");
$st->execute([$uid]);

ok(['dark' => (bool)$st->fetchColumn()]);
