<?php
// POST {video_id}: полностью удаляет ролик из видеотеки и его файлы.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();

$id = (int)(body()['video_id'] ?? 0);
$row = video_require_admin_row($id);
if (($row['status'] ?? '') === 'processing') {
  fail('Видео сейчас обрабатывается. Дождитесь завершения и повторите удаление.', 409);
}

// Снимаем ролик с очереди до очистки S3. Условное обновление не даст удалить
// его в тот момент, когда воркер уже успел забрать запись в обработку.
$claim = db()->prepare("UPDATE videos SET status='deleting',process_stage='Удаляем видео',updated_at=? WHERE id=? AND status<>'processing'");
$claim->execute([gmdate('Y-m-d H:i:s'), $id]);
if ($claim->rowCount() < 1 && ($row['status'] ?? '') !== 'deleting') {
  fail('Видео уже начало обрабатываться. Дождитесь завершения и повторите удаление.', 409);
}

// Для каждого видео используется изолированный префикс, поэтому удаляем и
// исходник, и обложки, и все HLS-сегменты одним безопасным деревом.
$prefix = 'videos/v' . $id . '/';
release_session();
try {
  storage_delete_prefix($prefix);
} catch (Throwable $e) {
  error_log('[video delete storage] #' . $id . ': ' . $e->getMessage());
  db()->prepare('UPDATE videos SET status=?,process_stage=?,updated_at=? WHERE id=? AND status=\'deleting\'')
      ->execute([(string)$row['status'], (string)($row['process_stage'] ?? ''), gmdate('Y-m-d H:i:s'), $id]);
  fail('Не удалось удалить файлы видео из хранилища. Попробуйте ещё раз.', 502);
}

$local = uploads_root() . '/' . rtrim($prefix, '/');
if (is_dir($local)) {
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($local, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
  }
  @rmdir($local);
}

$pdo = db();
$pdo->beginTransaction();
try {
  // Урок не должен продолжать ссылаться на уже удалённую карточку.
  $pdo->prepare("UPDATE lessons
                 SET video_id=NULL,
                     video_source=CASE WHEN kinescope_id IS NOT NULL AND kinescope_id<>'' THEN 'kinescope' ELSE 'none' END
                 WHERE video_id=?")
      ->execute([$id]);
  $pdo->prepare('DELETE FROM video_events WHERE video_id=?')->execute([$id]);
  $pdo->prepare('DELETE FROM video_progress WHERE video_id=?')->execute([$id]);
  $pdo->prepare('DELETE FROM videos WHERE id=?')->execute([$id]);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[video delete database] #' . $id . ': ' . $e->getMessage());
  fail('Файлы удалены, но карточку не удалось очистить. Повторите удаление.', 409);
}

live_content_touch();
ok(['deleted' => $id]);
