<?php
// POST JSON {id}: убеждаемся, что объект полностью дошёл до S3, и публикуем его.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$id = (int)(body()['id'] ?? 0);
if ($id <= 0) fail('Не указана загрузка', 400);
$item = drive_item($uid, $id, 'file');
if (!$item || ($item['upload_status'] ?? 'ready') !== 'pending') fail('Загрузка не найдена или уже завершена', 404);

$expected = max(0, (int)$item['size_bytes']);
$actual = storage_head_key((string)$item['storage_key']);
if ($actual !== $expected) {
  try { storage_delete_key((string)$item['storage_key']); } catch (Throwable $e) { /* очистим запись ниже */ }
  db()->prepare("DELETE FROM drive_items WHERE id = ? AND user_id = ? AND upload_status = 'pending'")->execute([$id, $uid]);
  fail('Файл загрузился не полностью. Попробуй ещё раз', 409);
}
db()->prepare("UPDATE drive_items SET upload_status = 'ready', updated_at = ? WHERE id = ? AND user_id = ?")
    ->execute([gmdate('Y-m-d H:i:s'), $id, $uid]);
$ready = drive_item($uid, $id, 'file');
ok(['item' => drive_public_item($ready), 'quota' => drive_quota($uid)]);
