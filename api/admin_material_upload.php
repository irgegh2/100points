<?php
// POST /admin_material_upload.php  (multipart: file=<файл>, lesson_id=<id>, name=<подпись>)
// Загрузка файла-материала урока админом. Кладёт файл в /uploads/materials/,
// создаёт строку в materials и возвращает её. По образцу avatar_upload.php.
require __DIR__ . '/bootstrap.php';
require_admin();

$lessonId = (int)($_POST['lesson_id'] ?? 0);
if ($lessonId <= 0) fail('Не указан урок', 400);

// Урок должен существовать (иначе осиротевший материал).
$ls = db()->prepare("SELECT id FROM lessons WHERE id = ? LIMIT 1");
$ls->execute([$lessonId]);
if (!$ls->fetch()) fail('Урок не найден', 404);

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  fail('Файл не получен', 400);
}
$f = $_FILES['file'];

// Тип по содержимому (не по расширению из браузера). Разрешаем документы/архивы/картинки.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$allowed = [
  'application/pdf' => ['pdf', 'pdf'],
  'application/msword' => ['doc', 'doc'],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'doc'],
  'application/vnd.ms-powerpoint' => ['ppt', 'ppt'],
  'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'ppt'],
  'application/vnd.ms-excel' => ['xls', 'xls'],
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'xls'],
  'application/zip' => ['zip', 'zip'],
  'text/plain' => ['txt', 'txt'],
  'image/jpeg' => ['jpg', 'img'],
  'image/png' => ['png', 'img'],
  'image/webp' => ['webp', 'img'],
];
if (!isset($allowed[$mime])) fail('Недопустимый тип файла. Разрешены PDF, Word, PowerPoint, Excel, ZIP, TXT, изображения.', 400);
[$ext, $kind] = $allowed[$mime];

// Общее хранилище основного домена: материалы из admin. видны в lk.
$uploadsRoot = uploads_root();
$dir = $uploadsRoot . '/materials';
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
  if ($written === false) error_log('admin_material_upload: не удалось записать uploads/.htaccess в ' . $uploadsRoot);
}

$fname = 'm' . $lessonId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$storageKey = 'materials/' . $fname;
storage_save_uploaded_file($f['tmp_name'], $storageKey, (string)$mime);
$url = storage_upload_url($storageKey);

// Человекочитаемый размер.
$bytes = (int)$f['size'];
$sizeStr = $bytes >= 1024 * 1024
  ? round($bytes / (1024 * 1024), 1) . ' МБ'
  : max(1, round($bytes / 1024)) . ' КБ';

// Имя-подпись: из формы либо из оригинального имени файла.
$name = trim((string)($_POST['name'] ?? ''));
if ($name === '') $name = pathinfo($f['name'], PATHINFO_FILENAME) ?: 'Материал';

// Позиция — в конец списка материалов урока.
$pos = (int)(db()->query("SELECT COALESCE(MAX(position),-1)+1 p FROM materials WHERE lesson_id = " . $lessonId)->fetch()['p'] ?? 0);

db()->prepare("INSERT INTO materials (lesson_id, name, size, url, kind, position) VALUES (?,?,?,?,?,?)")
    ->execute([$lessonId, $name, $sizeStr, $url, $kind, $pos]);
$id = (int)db()->lastInsertId();

ok(['item' => [
  'id'        => $id,
  'lesson_id' => $lessonId,
  'name'      => $name,
  'size'      => $sizeStr,
  'url'       => $url,
  'kind'      => $kind,
  'position'  => $pos,
]], 201);
