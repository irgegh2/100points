<?php
// POST multipart /drive_upload.php: file, parent_id?. Квота перепроверяется
// под блокировкой пользователя после загрузки в S3 — параллельные загрузки её не обойдут.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$parentId = max(0, (int)($_POST['parent_id'] ?? 0));
drive_require_parent($uid, $parentId);

if (empty($_FILES['file'])) fail('Выбери файл', 400);
$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $code = (int)($f['error'] ?? 0);
  if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) fail('Файл больше разрешённого сервером размера', 413);
  fail('Не удалось получить файл', 400);
}
$size = (int)($f['size'] ?? 0);
if ($size <= 0) fail('Нельзя загрузить пустой файл', 400);
$name = drive_clean_name((string)($f['name'] ?? 'Файл'));
if (!drive_allowed_extension($name)) fail('Этот тип файла нельзя хранить на Стобалльном диске', 400);

$quota = drive_quota($uid);
if ($size > (int)$quota['free_bytes']) fail('На Стобалльном диске не хватает места', 409);

$mime = 'application/octet-stream';
try {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $detected = $finfo->file((string)$f['tmp_name']);
  if (is_string($detected) && $detected !== '') $mime = mb_substr($detected, 0, 150);
} catch (Throwable $e) { /* безопасный универсальный MIME */ }
$ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
$suffix = $ext !== '' && preg_match('/^[a-z0-9]{1,12}$/', $ext) ? '.' . $ext : '';
$storageKey = 'drive/u' . $uid . '/' . bin2hex(random_bytes(18)) . $suffix;
storage_save_uploaded_file((string)$f['tmp_name'], $storageKey, $mime);

$pdo = db();
try {
  $pdo->beginTransaction();
  $lockSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? 'SELECT id FROM users WHERE id = ? FOR UPDATE'
    : 'SELECT id FROM users WHERE id = ?';
  $lock = $pdo->prepare($lockSql);
  $lock->execute([$uid]);
  $fresh = drive_quota($uid);
  if ($size > (int)$fresh['free_bytes']) throw new RuntimeException('quota');
  $ins = $pdo->prepare("INSERT INTO drive_items (user_id, parent_id, kind, name, storage_key, mime, size_bytes, updated_at)
                        VALUES (?,?, 'file', ?, ?, ?, ?, ?)");
  $ins->execute([$uid, $parentId > 0 ? $parentId : null, $name, $storageKey, $mime, $size, date('Y-m-d H:i:s')]);
  $id = (int)$pdo->lastInsertId();
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  try { storage_delete_key($storageKey); } catch (Throwable $ignored) { error_log('[drive rollback storage] ' . $ignored->getMessage()); }
  $local = uploads_root() . '/' . $storageKey;
  if (is_file($local)) @unlink($local);
  if ($e->getMessage() === 'quota') fail('Пока файл загружался, свободное место закончилось', 409);
  throw $e;
}

$item = drive_item($uid, $id);
ok(['item' => drive_public_item($item), 'quota' => drive_quota($uid)], 201);
