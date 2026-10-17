<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
release_session();
$in = body();
$id = (int)($in['video_id'] ?? 0);
$row = video_require_admin_row($id);
$title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$row['title'];
$lessonId = array_key_exists('lesson_id', $in) ? max(0, (int)$in['lesson_id']) : (int)($row['lesson_id'] ?? 0);
if ($title === '') fail('Название не может быть пустым', 400);
if ($lessonId > 0) {
  $check = db()->prepare('SELECT 1 FROM lessons WHERE id=?'); $check->execute([$lessonId]);
  if (!$check->fetchColumn()) fail('Урок не найден', 404);
}
$pdo = db(); $pdo->beginTransaction();
try {
  // Если переносим видео, старый урок больше не должен ссылаться на него.
  $pdo->prepare("UPDATE lessons SET video_id=NULL, video_source=CASE WHEN kinescope_id IS NOT NULL AND kinescope_id<>'' THEN 'kinescope' ELSE 'none' END WHERE video_id=? AND id<>?")
      ->execute([$id, $lessonId ?: 0]);
  $pdo->prepare('UPDATE videos SET title=?,lesson_id=?,updated_at=? WHERE id=?')
      ->execute([mb_substr($title,0,255), $lessonId ?: null, gmdate('Y-m-d H:i:s'), $id]);
  if (!empty($in['activate']) && $lessonId > 0) {
    $pdo->prepare("UPDATE lessons SET video_source='native',video_id=? WHERE id=?")->execute([$id,$lessonId]);
  }
  $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
live_content_touch();
ok(['item' => video_public_admin(video_require_admin_row($id))]);

