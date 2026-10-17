<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
$uid = require_user();
release_session();
$id = (int)($_GET['id'] ?? 0);
$row = video_require_user_row($uid, $id);
$progress = db()->prepare('SELECT position_sec,duration_sec,completed FROM video_progress WHERE user_id=? AND video_id=? LIMIT 1');
$progress->execute([$uid,$id]);
$saved = $progress->fetch() ?: ['position_sec'=>0,'duration_sec'=>0,'completed'=>0];
$qualities = json_decode((string)($row['qualities'] ?? ''), true);
$direct = !empty($row['source_key']);
ok([
  'id' => $id,
  'title' => $row['title'],
  'direct_url' => $direct ? '/api/video_asset.php?id=' . $id . '&kind=source' : null,
  'hls_url' => !$direct && !empty($row['master_key']) ? '/api/video_hls.php?id=' . $id . '&file=master.m3u8' : null,
  'poster_url' => !empty($row['poster_key']) ? '/api/video_asset.php?id=' . $id . '&kind=poster' : null,
  'duration_sec' => (int)$row['duration_sec'],
  'width' => (int)$row['width'],
  'height' => (int)$row['height'],
  'qualities' => $direct ? [] : (is_array($qualities) ? $qualities : []),
  'progress' => ['position_sec'=>(int)$saved['position_sec'],'duration_sec'=>(int)$saved['duration_sec'],'completed'=>(int)$saved['completed']===1],
]);
