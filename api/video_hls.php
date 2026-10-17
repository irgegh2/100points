<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
$uid = require_user();
$id = (int)($_GET['id'] ?? 0);
$row = video_require_user_row($uid,$id);
release_session();
$file = rawurldecode((string)($_GET['file'] ?? 'master.m3u8'));
$file = storage_normalize_key($file);
if (!preg_match('~^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+$~',$file)) fail('Недопустимый сегмент',400);
$master = storage_normalize_key((string)$row['master_key']);
$prefix = rtrim(dirname($master),'/');
$key = storage_normalize_key($prefix . '/' . $file);
if (!str_starts_with($key,$prefix . '/')) fail('Недопустимый сегмент',400);

if (str_ends_with(strtolower($file),'.m3u8')) {
  $result = storage_request('GET',$key);
  $base = dirname($file); if ($base === '.') $base = '';
  $lines = preg_split('/\r?\n/',(string)$result['body']);
  foreach ($lines as &$line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim,'#')) continue;
    if (preg_match('~^https?://~i',$trim)) continue;
    $relative = storage_normalize_key(($base !== '' ? $base . '/' : '') . $trim);
    $line = '/api/video_hls.php?id=' . $id . '&file=' . rawurlencode($relative);
  }
  unset($line);
  header('Content-Type: application/vnd.apple.mpegurl');
  header('Cache-Control: private, no-cache');
  echo implode("\n",$lines);
  exit;
}
header('Cache-Control: private, max-age=300');
header('Location: ' . storage_presigned_get($key,900));
exit;
