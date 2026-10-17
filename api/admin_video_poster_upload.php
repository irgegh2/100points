<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
$id = (int)($_POST['video_id'] ?? 0);
$row = video_require_admin_row($id);
$file = $_FILES['poster'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Не удалось загрузить обложку', 400);
if ((int)$file['size'] > 15 * 1024 * 1024) fail('Обложка не должна быть тяжелее 15 МБ', 413);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
$ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
if (!$ext) fail('Обложка должна быть JPG, PNG или WebP', 400);
$key = 'videos/v' . $id . '/poster-custom.' . $ext;
release_session();
storage_save_uploaded_file($file['tmp_name'], $key, $mime);
db()->prepare('UPDATE videos SET poster_key=?,updated_at=? WHERE id=?')->execute([$key,gmdate('Y-m-d H:i:s'),$id]);
ok(['item' => video_public_admin(video_require_admin_row($id))]);
