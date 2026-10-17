<?php
// POST /drive_delete.php {id}. Папка удаляется вместе со всем содержимым.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$id = (int)(body()['id'] ?? 0);
if ($id <= 0) fail('Не указан объект', 400);
$items = drive_collect_tree($uid, $id);

// Сначала удаляем физические объекты, затем записи. Если S3 временно не отвечает,
// пользователь увидит ошибку, а ссылка на файл не потеряется из базы.
foreach ($items as $item) {
  $key = trim((string)($item['storage_key'] ?? ''));
  if ($key === '') continue;
  if (storage_enabled()) storage_delete_key($key);
  $local = uploads_root() . '/' . storage_normalize_key($key);
  if (is_file($local)) @unlink($local);
}

$ids = array_reverse(array_map(fn($item) => (int)$item['id'], $items));
$pdo = db();
$pdo->beginTransaction();
try {
  $del = $pdo->prepare("DELETE FROM drive_items WHERE id = ? AND user_id = ?");
  foreach ($ids as $itemId) $del->execute([$itemId, $uid]);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}
ok(['deleted' => count($ids), 'quota' => drive_quota($uid)]);
