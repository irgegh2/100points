<?php
// GET /purchases_list.php — покупки/заявки ученика со статусами.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$st = db()->prepare("SELECT id, item_name, price, status, note, created_at FROM purchases WHERE user_id = ? ORDER BY id DESC");
$st->execute([$uid]);
$out = [];
foreach ($st as $r) $out[] = [
  'id' => (int)$r['id'], 'item_name' => $r['item_name'], 'price' => (int)$r['price'],
  'status' => $r['status'], 'note' => $r['note'], 'created_at' => $r['created_at'],
];
ok(['purchases' => $out]);
