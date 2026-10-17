<?php
// POST JSON {name,size,mime,parent_id}: резервирует квоту и выдаёт подписанный
// адрес для прямого PUT браузер → S3. Содержимое файла хостинг не принимает.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$in = body();
$parentId = max(0, (int)($in['parent_id'] ?? 0));
drive_require_parent($uid, $parentId);

$name = drive_clean_name((string)($in['name'] ?? 'Файл'));
$size = max(0, (int)($in['size'] ?? 0));
$mime = trim((string)($in['mime'] ?? 'application/octet-stream')) ?: 'application/octet-stream';
$mime = mb_substr($mime, 0, 150);
if ($size <= 0) fail('Нельзя загрузить пустой файл', 400);
if ($size > 5368709120) fail('Один файл пока может весить не больше 5 ГБ', 413);
if (!drive_allowed_extension($name)) fail('Этот тип файла нельзя хранить на Стобалльном диске', 400);
if (!storage_enabled()) ok(['mode' => 'proxy']);

// Незавершённые сутки назад резервы больше не должны занимать квоту.
$stale = db()->prepare("SELECT id, storage_key FROM drive_items
                        WHERE user_id = ? AND upload_status = 'pending' AND created_at < ?");
$stale->execute([$uid, gmdate('Y-m-d H:i:s', time() - 86400)]);
foreach ($stale->fetchAll() as $row) {
  try { storage_delete_key((string)$row['storage_key']); } catch (Throwable $e) { /* объекта могло не быть */ }
  db()->prepare("DELETE FROM drive_items WHERE id = ? AND user_id = ? AND upload_status = 'pending'")
      ->execute([(int)$row['id'], $uid]);
}

$ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
$suffix = $ext !== '' && preg_match('/^[a-z0-9]{1,12}$/', $ext) ? '.' . $ext : '';
$storageKey = 'drive/u' . $uid . '/' . bin2hex(random_bytes(18)) . $suffix;
$pdo = db();
$pdo->beginTransaction();
try {
  $lockSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? 'SELECT id FROM users WHERE id = ? FOR UPDATE'
    : 'SELECT id FROM users WHERE id = ?';
  $lock = $pdo->prepare($lockSql);
  $lock->execute([$uid]);
  $quota = drive_quota($uid);
  if ($size > (int)$quota['free_bytes']) throw new RuntimeException('quota');
  $pdo->prepare("INSERT INTO drive_items
      (user_id, parent_id, kind, name, storage_key, mime, size_bytes, updated_at, upload_status)
      VALUES (?,?, 'file', ?, ?, ?, ?, ?, 'pending')")
      ->execute([$uid, $parentId > 0 ? $parentId : null, $name, $storageKey, $mime, $size, gmdate('Y-m-d H:i:s')]);
  $id = (int)$pdo->lastInsertId();
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if ($e->getMessage() === 'quota') fail('На Стобалльном диске не хватает места для этого файла', 409);
  throw $e;
}

try {
  $url = storage_presigned_put($storageKey, 21600);
} catch (Throwable $e) {
  $pdo->prepare("DELETE FROM drive_items WHERE id = ? AND user_id = ? AND upload_status = 'pending'")->execute([$id, $uid]);
  throw $e;
}
ok(['mode' => 'direct', 'upload_id' => $id, 'upload_url' => $url]);
