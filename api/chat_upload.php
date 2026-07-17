<?php
// POST /chat_upload.php (multipart, поле "file") — вложение для чата с наставником.
// Принимает изображение (JPG/PNG/WEBP/GIF) или PDF до 10 МБ, кладёт в /uploads/chat/,
// возвращает { url }. Само сообщение отправляется отдельно (message_send с полем attachment).
require __DIR__ . '/bootstrap.php';
$uid = require_user(true); // держим сессию — пишем таймстамп рейт-лимита

$now = time();
if ($now - (int)($_SESSION['chat_upload_at'] ?? 0) < 2) fail('Слишком часто. Подождите пару секунд.', 429);
$_SESSION['chat_upload_at'] = $now;

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  fail('Файл не получен', 400);
}
$f = $_FILES['file'];
if ($f['size'] > 10 * 1024 * 1024) fail('Слишком большой файл (максимум 10 МБ)', 400);

// Тип определяем по содержимому, а не по расширению из браузера.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$allowed = [
  'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
  'application/pdf' => 'pdf',
];
if (!isset($allowed[$mime])) fail('Можно прикрепить изображение (JPG/PNG/WEBP/GIF) или PDF', 400);
$ext = $allowed[$mime];

$uploadsRoot = uploads_root();
$dir = $uploadsRoot . '/chat';
if (!is_dir($dir)) {
  if (!mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог загрузок', 500);
}

// Защита каталога uploads (как в avatar_upload): запрет исполнения скриптов и листинга.
$hta = $uploadsRoot . '/.htaccess';
if (!file_exists($hta)) {
  $written = file_put_contents($hta,
    "<FilesMatch \"(?i)\\.(php|phtml|phar|phps|cgi|pl|py|asp|aspx|sh|htaccess)$\">\n" .
    "  Require all denied\n" .
    "</FilesMatch>\n" .
    "RemoveHandler .php .phtml .phar\n" .
    "RemoveType .php .phtml .phar\n" .
    "Options -Indexes\n"
  );
  if ($written === false) error_log('chat_upload: не удалось записать uploads/.htaccess в ' . $uploadsRoot);
}

$fname = 'c' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $fname;
if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Не удалось сохранить файл', 500);
@chmod($dest, 0644);

ok(['url' => uploads_url('chat/' . $fname)]);
