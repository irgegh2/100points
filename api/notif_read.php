<?php
// POST /notif_read.php  { id } | { all: true } — отметить уведомление(я) прочитанными.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$now = date('Y-m-d H:i:s');
if (!empty($in['all'])) {
  db()->prepare("UPDATE notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0")->execute([$now, $uid]);
  ok(['read' => 'all']);
}
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Не указано уведомление', 400);
db()->prepare("UPDATE notifications SET is_read = 1, read_at = ? WHERE id = ? AND user_id = ?")->execute([$now, $id, $uid]);
ok(['read' => $id]);
