<?php
// GET /trainer_meta.php — данные для раздела «ИИ-тренажёр».
// Доступные предметы + по каждому: уровни (1–5) с числом ещё НЕ пройденных задач (режим «Решение задач»).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$subjects = user_accessible_subjects($uid);
$out = ['subjects' => $subjects];

$subjectId = (int)($_GET['subject_id'] ?? 0);
$allowed = array_column($subjects, 'id');
if ($subjectId > 0 && in_array($subjectId, $allowed, true)) {
  // Непройденные задачи по уровням.
  $st = db()->prepare(
    "SELECT t.level, COUNT(*) AS total,
            SUM(CASE WHEN a.id IS NULL THEN 1 ELSE 0 END) AS unsolved
     FROM trainer_tasks t
     LEFT JOIN trainer_attempts a ON a.task_id = t.id AND a.user_id = ?
     WHERE t.subject_id = ?
     GROUP BY t.level ORDER BY t.level"
  );
  $st->execute([$uid, $subjectId]);
  $levels = [];
  foreach ($st as $r) $levels[] = ['level' => (int)$r['level'], 'total' => (int)$r['total'], 'unsolved' => (int)$r['unsolved']];
  $out['levels'] = $levels;

  // Сколько тем для режима «Вопрос-ответ» (ИИ-генерация) по предмету.
  $qa = db()->prepare("SELECT COUNT(*) c FROM trainer_qa_topics WHERE subject_id = ?");
  $qa->execute([$subjectId]);
  $out['qa_topics'] = (int)$qa->fetch()['c'];
}

ok($out);
