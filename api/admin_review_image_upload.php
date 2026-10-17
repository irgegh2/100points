<?php
// POST multipart: image — изображение для Markdown-разбора проверяющего.
require __DIR__ . '/bootstrap.php';
$adminId = require_admin();
if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Изображение не получено', 400);
$f = $_FILES['image'];
if ((int)$f['size'] <= 0) fail('Пустой файл', 400);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!isset($allowed[$mime])) fail('Можно загрузить JPG, PNG, WEBP или GIF', 400);

$root = uploads_root();
$dir = $root . '/review-attachments';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог загрузок', 500);
$fname = 'a' . $adminId . '_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
$storageKey = 'review-attachments/' . $fname;
storage_save_uploaded_file($f['tmp_name'], $storageKey, (string)$mime);
ok(['url' => storage_upload_url($storageKey)]);
