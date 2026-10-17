<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
release_session();
$id = (int)(body()['video_id'] ?? 0);
$row = video_require_admin_row($id);
if (($row['status'] ?? '') !== 'uploading') fail('Загрузка уже завершена', 409);
$actual = storage_head_key((string)$row['source_key']);
$expected = (int)$row['size_bytes'];
if ($actual <= 0 || ($expected > 0 && $actual !== $expected)) {
  fail('Файл дошёл в S3 не полностью. Повтори загрузку', 409);
}
db()->prepare("UPDATE videos SET status='ready', process_percent=100, process_stage='Обложка в очереди', error=NULL, updated_at=? WHERE id=?")
    ->execute([gmdate('Y-m-d H:i:s'), $id]);
video_worker_kick();
ok(['item' => video_public_admin(video_require_admin_row($id))]);
