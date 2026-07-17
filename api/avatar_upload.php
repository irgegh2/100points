<?php
// POST /avatar_upload.php — загрузка аватара ученика (multipart, поле "avatar").
// Принимает JPG/PNG/WEBP до 5 МБ и кладёт в общее хранилище основного домена.
require __DIR__ . '/bootstrap.php';

$uid = require_user(true); // держим сессию открытой — ниже пишем таймстамп рейт-лимита

// Лёгкий троттлинг: не чаще одной загрузки в 2 секунды на сессию (анти-спам/мягкий DoS).
$now = time();
if ($now - (int)($_SESSION['avatar_upload_at'] ?? 0) < 2) {
  fail('Слишком часто. Подождите пару секунд.', 429);
}
$_SESSION['avatar_upload_at'] = $now;

if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  fail('Файл не получен', 400);
}
$f = $_FILES['avatar'];

if ($f['size'] > 5 * 1024 * 1024) fail('Слишком большой файл (максимум 5 МБ)', 400);

// Тип определяем по содержимому, а не по расширению из браузера.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) fail('Только изображения JPG, PNG или WEBP', 400);
$ext = $allowed[$mime];

$uploadsRoot = uploads_root();
$dir = $uploadsRoot . '/avatars';
if (!is_dir($dir)) {
  if (!mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог загрузок', 500);
}

// Защита каталога uploads: запрет исполнения любых скриптов и листинга.
// Без php_flag — на FastCGI он валит сервер 500; используем запрет доступа + снятие хендлеров.
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
  // Не молчим при сбое — иначе каталог останется без защиты незаметно.
  if ($written === false) error_log('avatar_upload: не удалось записать uploads/.htaccess в ' . $uploadsRoot);
}

$fname = 'u' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $fname;
if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Не удалось сохранить файл', 500);
@chmod($dest, 0644);
$url = uploads_url('avatars/' . $fname);

// Подчистим прежний аватар этого ученика (чтобы не копить мусор).
$old = db()->prepare("SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1");
$old->execute([$uid]);
$oldrow = $old->fetch();
$oldpath = $oldrow['avatar'] ?? '';
$oldUrlPath = parse_url($oldpath, PHP_URL_PATH) ?: $oldpath;
$ownerPrefix = 'u' . $uid . '_'; // защита: удаляем ТОЛЬКО собственный файл этого ученика
if ($oldUrlPath
    && strpos($oldUrlPath, '/uploads/avatars/') === 0
    && strpos(basename($oldUrlPath), $ownerPrefix) === 0) {
  $oldfile = realpath($dir . '/' . basename($oldUrlPath));
  // И физически файл должен быть внутри каталога аватаров (анти-traversal).
  if ($oldfile && strpos($oldfile, realpath($dir)) === 0 && is_file($oldfile)) @unlink($oldfile);
}

// upsert avatar
$exists = db()->prepare("SELECT user_id FROM profiles WHERE user_id = ? LIMIT 1");
$exists->execute([$uid]);
if ($exists->fetch()) {
  db()->prepare("UPDATE profiles SET avatar = ? WHERE user_id = ?")->execute([$url, $uid]);
} else {
  db()->prepare("INSERT INTO profiles (user_id, avatar) VALUES (?,?)")->execute([$uid, $url]);
}

ok(['avatar' => $url]);
