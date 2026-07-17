<?php
// GET /review_get.php?submission_id=<id> — одна сдача для ручной проверки (Этап 5).
// Возвращает контекст + все ответы (развёрнутые с текстом ученика для оценки, авто — для справки).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$id = (int)($_GET['submission_id'] ?? 0);
if ($id <= 0) fail('Не указана сдача', 400);

$s = db()->prepare(
  "SELECT sub.id, sub.status, sub.score, sub.max_score, sub.mock_id,
          u.name AS user_name, hw.title AS hw_title, hw.level AS hw_level, mk.title AS mk_title
   FROM submissions sub
   JOIN users u ON u.id = sub.user_id
   LEFT JOIN homeworks hw ON hw.id = sub.homework_id
   LEFT JOIN mock_exams mk ON mk.id = sub.mock_id
   WHERE sub.id = ? LIMIT 1"
);
$s->execute([$id]);
$sub = $s->fetch();
if (!$sub) fail('Сдача не найдена', 404);
$isMock = $sub['mock_id'] !== null;

$a = db()->prepare(
  "SELECT a.id, a.value, a.is_correct, a.earned, a.mentor_comment,
          q.type, q.text, q.options, q.correct, q.solution, q.points
   FROM answers a JOIN questions q ON q.id = a.question_id
   WHERE a.submission_id = ? ORDER BY q.position, q.id"
);
$a->execute([$id]);
$answers = [];
foreach ($a as $r) {
  $answers[] = [
    'id'             => (int)$r['id'],
    'type'           => $r['type'],
    'text'           => $r['text'],
    'options'        => $r['options'] !== null ? json_decode($r['options'], true) : null,
    'correct'        => $r['correct'] !== null ? json_decode($r['correct'], true) : null,
    'solution'       => $r['solution'],
    'points'         => (int)$r['points'],
    'your_value'     => $r['value'] !== null ? json_decode($r['value'], true) : null,
    'is_correct'     => $r['is_correct'] === null ? null : ((int)$r['is_correct'] === 1),
    'earned'         => (int)$r['earned'],
    'mentor_comment' => $r['mentor_comment'],
  ];
}

ok([
  'submission' => ['id' => (int)$sub['id'], 'status' => $sub['status'], 'score' => (int)$sub['score'], 'max_score' => (int)$sub['max_score'],
                   'user_name' => $sub['user_name'], 'homework_title' => $isMock ? $sub['mk_title'] : $sub['hw_title'],
                   'level' => $isMock ? 'normal' : $sub['hw_level'], 'kind' => $isMock ? 'mock' : 'homework'],
  'answers' => $answers,
]);
