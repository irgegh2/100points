<?php
// GET /bank_solved.php?subject_id=<id> — задачи банка, которые ученик уже решал (для просмотра).
// С пометкой «верно/неверно», правильным ответом и решением (показываем — задача уже пройдена).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$subjectId = (int)($_GET['subject_id'] ?? 0);
$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($subjectId, $allowed, true)) fail('Нет доступа к предмету', 403);

$st = db()->prepare(
  "SELECT q.id, q.type, q.text, q.options, q.correct, q.solution, q.points,
          bp.correct AS is_correct, bp.updated_at,
          t.number AS topic_number, t.name AS topic_name, st.name AS subtopic_name
   FROM bank_progress bp
   JOIN questions q ON q.id = bp.question_id
   JOIN subtopics st ON st.id = q.subtopic_id
   JOIN topics t ON t.id = st.topic_id
   WHERE bp.user_id = ? AND t.subject_id = ?
   ORDER BY t.position, t.number, st.position, q.position, q.id"
);
$st->execute([$uid, $subjectId]);

$items = [];
foreach ($st as $r) {
  $items[] = [
    'id' => (int)$r['id'], 'type' => $r['type'], 'text' => $r['text'],
    'options' => $r['options'] !== null ? json_decode($r['options'], true) : null,
    'correct' => $r['correct'] !== null ? json_decode($r['correct'], true) : null,
    'solution' => $r['solution'], 'points' => (int)$r['points'],
    'is_correct' => (int)$r['is_correct'] === 1,
    'topic' => ['number' => $r['topic_number'] !== null ? (int)$r['topic_number'] : null, 'name' => $r['topic_name']],
    'subtopic' => $r['subtopic_name'],
  ];
}

ok(['items' => $items]);
