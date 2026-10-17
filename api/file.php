<?php
// Выдаёт приватный объект S3 по короткой подписанной ссылке.
// URL в базе остаётся относительным, поэтому работает на основном домене, lk и admin.
require __DIR__ . '/bootstrap.php';

$uid = (int)($_SESSION['uid'] ?? 0);
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($uid <= 0 && $adminId <= 0) fail('Нужен вход', 401);
release_session();

try {
  $key = storage_normalize_key((string)($_GET['path'] ?? ''));
  $downloadName = null;
  // Личный диск строже старых учебных вложений: ученик может получить только
  // собственный объект. Администратор сохраняет доступ для поддержки.
  if (str_starts_with($key, 'drive/') && $adminId <= 0) {
    $access = db()->prepare("SELECT name FROM drive_items WHERE user_id = ? AND storage_key = ? AND (upload_status IS NULL OR upload_status = 'ready') LIMIT 1");
    $access->execute([$uid, $key]);
    $downloadName = $access->fetchColumn();
    if (!$downloadName) fail('Файл не найден', 404);
  } elseif (str_starts_with($key, 'drive/') && $adminId > 0) {
    $access = db()->prepare("SELECT name FROM drive_items WHERE storage_key = ? AND (upload_status IS NULL OR upload_status = 'ready') LIMIT 1");
    $access->execute([$key]);
    $downloadName = $access->fetchColumn();
    if (!$downloadName) fail('Файл не найден', 404);
  }
  if (!storage_enabled()) fail('Облачное хранилище не настроено', 404);
  header('Cache-Control: private, max-age=240');
  $asAttachment = isset($_GET['download']) && (string)$_GET['download'] !== '0';
  header('Location: ' . storage_presigned_get($key, 300, $asAttachment ? ($downloadName ?: basename($key)) : null), true, 302);
  exit;
} catch (InvalidArgumentException $e) {
  fail('Файл не найден', 404);
}
