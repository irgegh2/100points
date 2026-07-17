<?php
// POST /admin_library_upload.php (multipart: file, subject_id?, category, title?)
// Загрузка файла-материала в библиотеку. Кладёт в /uploads/library/, создаёт library_items.
require __DIR__ . '/bootstrap.php';
require_admin();

$subjectId = (int)($_POST['subject_id'] ?? 0) ?: null;
$category = trim((string)($_POST['category'] ?? 'Материалы')) ?: 'Материалы';
$title = trim((string)($_POST['title'] ?? ''));

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Файл не получен', 400);
$f = $_FILES['file'];
if ($f['size'] > 50 * 1024 * 1024) fail('Слишком большой файл (максимум 50 МБ)', 400);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$allowed = [
  'application/pdf' => ['pdf', 'doc'],
  'application/msword' => ['doc', 'doc'],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'doc'],
  'application/vnd.ms-powerpoint' => ['ppt', 'doc'],
  'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'doc'],
  'application/vnd.ms-excel' => ['xls', 'doc'],
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'doc'],
  'application/zip' => ['zip', 'zip'],
  'text/plain' => ['txt', 'doc'],
  'image/jpeg' => ['jpg', 'img'],
  'image/png' => ['png', 'img'],
  'image/webp' => ['webp', 'img'],
];
if (!isset($allowed[$mime])) fail('Недопустимый тип. Разрешены PDF, Word, PowerPoint, Excel, ZIP, TXT, изображения.', 400);
[$ext, $kind] = $allowed[$mime];

$uploadsRoot = uploads_root();
$dir = $uploadsRoot . '/library';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог', 500);
$hta = $uploadsRoot . '/.htaccess';
if (!file_exists($hta)) {
  file_put_contents($hta, "<FilesMatch \"(?i)\\.(php|phtml|phar|phps|cgi|pl|py|asp|aspx|sh|htaccess)$\">\n  Require all denied\n</FilesMatch>\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\nOptions -Indexes\n");
}

$fname = 'lib_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) fail('Не удалось сохранить файл', 500);
@chmod($dir . '/' . $fname, 0644);
$url = uploads_url('library/' . $fname);

$bytes = (int)$f['size'];
$sizeStr = $bytes >= 1024 * 1024 ? round($bytes / (1024 * 1024), 1) . ' МБ' : max(1, round($bytes / 1024)) . ' КБ';
if ($title === '') $title = pathinfo($f['name'], PATHINFO_FILENAME) ?: 'Материал';
$size = strtoupper($ext) . ' · ' . $sizeStr;

$pos = (int)(db()->query("SELECT COALESCE(MAX(position),-1)+1 p FROM library_items")->fetch()['p'] ?? 0);
db()->prepare("INSERT INTO library_items (subject_id, category, title, url, size, kind, position) VALUES (?,?,?,?,?,?,?)")
    ->execute([$subjectId, $category, $title, $url, $size, $kind, $pos]);
$id = (int)db()->lastInsertId();
ok(['item' => ['id' => $id, 'subject_id' => $subjectId, 'category' => $category, 'title' => $title, 'url' => $url, 'size' => $size, 'kind' => $kind, 'position' => $pos]], 201);
