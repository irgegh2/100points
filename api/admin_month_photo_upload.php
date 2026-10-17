<?php
// POST /admin_month_photo_upload.php  (multipart: file=<картинка>, month_id=<id>)
// Загрузка фото в галерею-расписание месяца (админ). До 10 фото на месяц.
// Кладёт файл в /uploads/months/, создаёт строку в month_photos и возвращает её.
// По образцу admin_material_upload.php.
require __DIR__ . '/bootstrap.php';
require_admin();

$monthId = (int)($_POST['month_id'] ?? 0);
if ($monthId <= 0) fail('Не указан период курса', 400);

$ms = db()->prepare("SELECT id FROM months WHERE id = ? LIMIT 1");
$ms->execute([$monthId]);
if (!$ms->fetch()) fail('Период курса не найден', 404);

// Лимит галереи — 10 фото на месяц.
$cnt = db()->prepare("SELECT COUNT(*) c FROM month_photos WHERE month_id = ?");
$cnt->execute([$monthId]);
if ((int)$cnt->fetch()['c'] >= 10) fail('В галерее уже 10 фото — удалите лишние', 400);

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  fail('Файл не получен', 400);
}
$f = $_FILES['file'];
// Тип по содержимому: только изображения.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) fail('Только изображения: JPG, PNG или WebP', 400);
$ext = $allowed[$mime];

$uploadsRoot = uploads_root();
$dir = $uploadsRoot . '/months';
if (!is_dir($dir)) {
  if (!mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог загрузок', 500);
}

// Защита каталога uploads (как в других загрузчиках).
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
  if ($written === false) error_log('admin_month_photo_upload: не удалось записать uploads/.htaccess в ' . $uploadsRoot);
}

$fname = 'mp' . $monthId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$storageKey = 'months/' . $fname;
storage_save_uploaded_file($f['tmp_name'], $storageKey, (string)$mime);
$url = storage_upload_url($storageKey);

$pos = (int)(db()->query("SELECT COALESCE(MAX(position),-1)+1 p FROM month_photos WHERE month_id = " . $monthId)->fetch()['p'] ?? 0);

db()->prepare("INSERT INTO month_photos (month_id, url, position) VALUES (?,?,?)")
    ->execute([$monthId, $url, $pos]);
$id = (int)db()->lastInsertId();

ok(['item' => [
  'id'       => $id,
  'month_id' => $monthId,
  'url'      => $url,
  'position' => $pos,
]], 201);
