<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$row = video_require_admin_row($id);
if (($row['status'] ?? '') !== 'ready' || (empty($row['source_key']) && empty($row['master_key']))) fail('Видео ещё не готово', 409);
release_session();
$qualities = json_decode((string)($row['qualities'] ?? ''), true);
$direct = !empty($row['source_key']);
ok([
  'id' => $id,
  'title' => (string)$row['title'],
  'direct_url' => $direct ? '/api/admin_video_asset.php?id=' . $id . '&kind=source' : null,
  'hls_url' => !$direct && !empty($row['master_key']) ? '/api/admin_video_hls.php?id=' . $id . '&file=master.m3u8' : null,
  'poster_url' => !empty($row['poster_key']) ? '/api/admin_video_asset.php?id=' . $id . '&kind=poster' : null,
  'duration_sec' => (int)$row['duration_sec'],
  'width' => (int)$row['width'],
  'height' => (int)$row['height'],
  'qualities' => $direct ? [] : (is_array($qualities) ? $qualities : []),
  'progress' => ['position_sec' => 0, 'duration_sec' => 0, 'completed' => false],
]);
