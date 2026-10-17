<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
$uid = require_user();
$id = (int)($_GET['id'] ?? 0);
$row = video_require_user_row($uid,$id);
release_session();
$kind = (string)($_GET['kind'] ?? 'poster');
$key = $kind === 'source' ? (string)$row['source_key'] : (string)$row['poster_key'];
if ($key === '') fail('Файл ещё не готов',404);
header('Cache-Control: private, max-age=300');
header('Location: ' . storage_presigned_get($key, $kind === 'source' ? 21600 : 900));
exit;
