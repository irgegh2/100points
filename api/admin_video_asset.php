<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$kind = (string)($_GET['kind'] ?? 'poster');
$row = video_require_admin_row($id);
release_session();
$key = $kind === 'source' ? (string)$row['source_key'] : (string)$row['poster_key'];
if ($key === '') fail('Файл ещё не готов', 404);
header('Cache-Control: private, max-age=300');
header('Location: ' . storage_presigned_get($key, $kind === 'source' ? 21600 : 900));
exit;
