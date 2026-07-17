<?php
// GET ?question_id= — восстановить файлы незавершённого ответа после перезагрузки.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
$qid = (int)($_GET['question_id'] ?? 0);
if ($qid <= 0) fail('Не указан вопрос', 400);
$st = db()->prepare(
  "SELECT au.id, au.url, au.original_name, au.size, au.mime
   FROM answer_uploads au JOIN questions q ON q.id = au.question_id
   WHERE au.user_id = ? AND au.question_id = ? AND au.submission_id IS NULL AND q.type = 'file'
   ORDER BY au.id"
);
$st->execute([$uid, $qid]);
$files = [];
foreach ($st as $r) $files[] = ['id' => (int)$r['id'], 'url' => $r['url'], 'name' => $r['original_name'], 'size' => (int)$r['size'], 'mime' => $r['mime']];
ok(['files' => $files]);
