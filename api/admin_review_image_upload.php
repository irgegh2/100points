<?php
// POST multipart: image — изображение для Markdown-разбора проверяющего.
require __DIR__ . '/bootstrap.php';
$adminId = require_admin();
if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Изображение не получено', 400);
$f = $_FILES['image'];
if ((int)$f['size'] <= 0 || (int)$f['size'] > 10 * 1024 * 1024) fail('Изображение должно быть не больше 10 МБ', 400);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!isset($allowed[$mime])) fail('Можно загрузить JPG, PNG, WEBP или GIF', 400);

$root = uploads_root();
$dir = $root . '/reviews';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог загрузок', 500);
$fname = 'a' . $adminId . '_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) fail('Не удалось сохранить изображение', 500);
@chmod($dir . '/' . $fname, 0644);
ok(['url' => uploads_url('reviews/' . $fname)]);
