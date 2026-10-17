<?php
// POST /admin_question_attachment_upload.php  (multipart: file=<файл>, question_id=<id>, kind=image|audio|file)
// Вложение к вопросу ДЗ/пробника (админ): картинки показываются НАД текстом вопроса (до 10),
// аудио — отдельным плеером, файлы — ПОД текстом (каждого типа до 10).
require __DIR__ . '/bootstrap.php';
require_admin();

$questionId = (int)($_POST['question_id'] ?? 0);
$kind = (string)($_POST['kind'] ?? '');
if ($questionId <= 0) fail('Не указан вопрос', 400);
if (!in_array($kind, ['image', 'audio', 'file'], true)) fail('Неверный тип вложения', 400);

$qs = db()->prepare("SELECT id FROM questions WHERE id = ? LIMIT 1");
$qs->execute([$questionId]);
if (!$qs->fetch()) fail('Вопрос не найден', 404);

$limit = 10;
$cnt = db()->prepare("SELECT COUNT(*) c FROM question_attachments WHERE question_id = ? AND kind = ?");
$cnt->execute([$questionId, $kind]);
if ((int)$cnt->fetch()['c'] >= $limit) {
  $labels = ['image' => 'картинок', 'audio' => 'аудиозаписей', 'file' => 'файлов'];
  fail('У вопроса уже 10 ' . $labels[$kind] . ' — удалите лишние', 400);
}

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  fail('Файл не получен', 400);
}
$f = $_FILES['file'];
// Тип по содержимому, не по расширению.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
$images = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$audio = [
  'audio/mpeg' => 'mp3',
  'audio/mp4' => 'm4a',
  'audio/x-m4a' => 'm4a',
  'audio/ogg' => 'ogg',
  'audio/wav' => 'wav',
  'audio/x-wav' => 'wav',
  'audio/flac' => 'flac',
  'audio/x-flac' => 'flac',
];
$docs = [
  'application/pdf' => 'pdf',
  'application/msword' => 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
  'application/vnd.ms-powerpoint' => 'ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
  'application/vnd.ms-excel' => 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
  'application/zip' => 'zip',
  'text/plain' => 'txt',
];
if ($kind === 'image') {
  if (!isset($images[$mime])) fail('Только изображения: JPG, PNG или WebP', 400);
  $ext = $images[$mime];
} elseif ($kind === 'audio') {
  if (!isset($audio[$mime])) fail('Недопустимое аудио. Разрешены MP3, M4A, OGG, WAV и FLAC.', 400);
  $ext = $audio[$mime];
} else {
  $allowed = $docs + $images;
  if (!isset($allowed[$mime])) fail('Недопустимый тип файла. Разрешены PDF, Word, PowerPoint, Excel, ZIP, TXT, изображения.', 400);
  $ext = $allowed[$mime];
}

$uploadsRoot = uploads_root();
$dir = $uploadsRoot . '/questions';
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
  if ($written === false) error_log('admin_question_attachment_upload: не удалось записать uploads/.htaccess в ' . $uploadsRoot);
}

$fname = 'q' . $questionId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$storageKey = 'questions/' . $fname;
storage_save_uploaded_file($f['tmp_name'], $storageKey, (string)$mime);
$url = storage_upload_url($storageKey);

$bytes = (int)$f['size'];
$sizeStr = $bytes >= 1024 * 1024
  ? round($bytes / (1024 * 1024), 1) . ' МБ'
  : max(1, round($bytes / 1024)) . ' КБ';
$name = pathinfo($f['name'], PATHINFO_FILENAME) ?: 'Файл';

$pos = (int)(db()->query("SELECT COALESCE(MAX(position),-1)+1 p FROM question_attachments WHERE question_id = " . $questionId)->fetch()['p'] ?? 0);

db()->prepare("INSERT INTO question_attachments (question_id, kind, name, size, url, position) VALUES (?,?,?,?,?,?)")
    ->execute([$questionId, $kind, $name, $sizeStr, $url, $pos]);
$id = (int)db()->lastInsertId();

ok(['item' => [
  'id'          => $id,
  'question_id' => $questionId,
  'kind'        => $kind,
  'name'        => $name,
  'size'        => $sizeStr,
  'url'         => $url,
  'position'    => $pos,
]], 201);
