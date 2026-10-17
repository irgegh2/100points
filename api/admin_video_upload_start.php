<?php
// Резервирует запись и выдаёт прямой PUT браузер -> приватный S3.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
release_session();

$in = body();
$name = trim((string)($in['name'] ?? ''));
$title = trim((string)($in['title'] ?? ''));
$mime = mb_substr(trim((string)($in['mime'] ?? 'application/octet-stream')), 0, 150);
$size = max(0, (int)($in['size'] ?? 0));
$lessonId = max(0, (int)($in['lesson_id'] ?? 0));
if ($name === '' || $size <= 0) fail('Выбери непустой видеофайл', 400);
if ($size > 21474836480) fail('Один ролик пока может весить не больше 20 ГБ', 413);
$ext = video_safe_ext($name, $mime);
if ($lessonId > 0) {
  $check = db()->prepare('SELECT 1 FROM lessons WHERE id = ?');
  $check->execute([$lessonId]);
  if (!$check->fetchColumn()) fail('Урок не найден', 404);
}
if ($title === '') $title = preg_replace('/\.[^.]+$/u', '', $name) ?: $name;
$now = gmdate('Y-m-d H:i:s');
db()->prepare("INSERT INTO videos (lesson_id,title,original_name,mime,size_bytes,status,process_percent,process_stage,updated_at)
               VALUES (?,?,?,?,?,'uploading',0,'Ожидает загрузки',?)")
    ->execute([$lessonId ?: null, mb_substr($title, 0, 255), mb_substr($name, 0, 255), $mime, $size, $now]);
$id = (int)db()->lastInsertId();
$key = 'videos/v' . $id . '/source.' . $ext;
db()->prepare('UPDATE videos SET source_key = ? WHERE id = ?')->execute([$key, $id]);
try {
  $url = storage_presigned_put($key, 21600);
} catch (Throwable $e) {
  db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
  throw $e;
}
ok(['video_id' => $id, 'upload_url' => $url, 'item' => video_public_admin(video_require_admin_row($id))]);

