<?php
// POST multipart: file, question_id, assignment_id? — файл к ручному ответу ученика.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();

$qid = (int)($_POST['question_id'] ?? 0);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($qid <= 0) fail('Не указан вопрос', 400);
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Файл не получен', 400);

$qst = db()->prepare(
  "SELECT q.id, q.type, COALESCE(q.file_limit,1) AS file_limit, q.homework_id, q.mock_id,
          hw.level AS homework_level, hc.id AS homework_course_id,
          m.course_id AS mock_course_id, COALESCE(m.format,'normal') AS mock_format,
          COALESCE(m.access_mode,'general') AS mock_access_mode
   FROM questions q
   LEFT JOIN homeworks hw ON hw.id = q.homework_id
   LEFT JOIN lessons l ON l.id = hw.lesson_id
   LEFT JOIN blocks b ON b.id = l.block_id
   LEFT JOIN courses hc ON hc.id = b.course_id
   LEFT JOIN mock_exams m ON m.id = q.mock_id
   WHERE q.id = ? LIMIT 1"
);
$qst->execute([$qid]);
$q = $qst->fetch();
if (!$q || !in_array($q['type'], ['file', 'audio'], true)) fail('Этот вопрос не принимает файлы', 400);
$isAudio = $q['type'] === 'audio';

if (!empty($q['homework_id'])) {
  if (!user_has_course_access($uid, (int)$q['homework_course_id'])) fail('Нет доступа к заданию', 403);
  if ($q['homework_level'] === 'hard' && !user_has_hard_access($uid)) fail('Нет доступа к продвинутому заданию', 403);
} elseif (!empty($q['mock_id'])) {
  $assignment = p_assignment_for_user($uid, $assignmentId, (int)$q['mock_id']);
  $hasAccess = ($q['mock_format'] === 'proctored' || $q['mock_access_mode'] === 'individual')
    ? (bool)$assignment
    : user_has_course_access($uid, (int)$q['mock_course_id']);
  if (!$hasAccess) fail('Пробник тебе не назначен', 403);
  if ($q['mock_format'] === 'proctored') {
    if (!$assignment || (int)$assignment['id'] !== $assignmentId || p_time_state($assignment) !== 'live') fail('Файлы можно добавить только во время сессии', 403);
  }
} else {
  fail('К этому вопросу нельзя загрузить файл', 400);
}

$limit = $isAudio ? 1 : max(1, min(10, (int)$q['file_limit']));
$cnt = db()->prepare("SELECT COUNT(*) FROM answer_uploads WHERE user_id = ? AND question_id = ? AND submission_id IS NULL");
$cnt->execute([$uid, $qid]);
if ((int)$cnt->fetchColumn() >= $limit) fail($isAudio ? 'Для устного ответа нужна одна аудиозапись' : "Для этого ответа разрешено не больше $limit файлов", 409);

$f = $_FILES['file'];
if ((int)$f['size'] <= 0 || (int)$f['size'] > ($isAudio ? 100 : 500) * 1024 * 1024) {
  fail($isAudio ? 'Размер аудиозаписи — не более 100 МБ' : 'Размер одного файла — не более 500 МБ', 400);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($f['tmp_name']);
$documents = [
  'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
  'application/pdf' => 'pdf',
  'application/msword' => 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
  'application/vnd.ms-excel' => 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
  'text/plain' => 'txt',
];
$audio = [
  'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
  'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/wav' => 'wav',
  'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'video/webm' => 'webm',
  'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
];
$allowed = $isAudio ? $audio : $documents;
if (!isset($allowed[$mime])) {
  fail($isAudio ? 'Можно загрузить MP3, M4A, OGG, WAV, WebM или AAC' : 'Можно загрузить фото, PDF, Word, Excel или TXT', 400);
}

$root = uploads_root();
$relDir = 'answers/u' . $uid;
$dir = $root . '/' . $relDir;
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) fail('Не удалось создать каталог загрузок', 500);
$fname = 'q' . $qid . '_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
$storageKey = $relDir . '/' . $fname;
storage_save_uploaded_file($f['tmp_name'], $storageKey, $mime);

$original = mb_substr(trim((string)($f['name'] ?? 'Файл')), 0, 240);
$url = storage_upload_url($storageKey);
try {
  $ins = db()->prepare("INSERT INTO answer_uploads (user_id, question_id, url, original_name, mime, size) VALUES (?,?,?,?,?,?)");
  $ins->execute([$uid, $qid, $url, $original ?: 'Файл', $mime, (int)$f['size']]);
  $id = (int)db()->lastInsertId();
} catch (Throwable $e) {
  storage_delete_url($url);
  throw $e;
}

ok(['file' => ['id' => $id, 'url' => $url, 'name' => $original ?: 'Файл', 'size' => (int)$f['size'], 'mime' => $mime]]);
