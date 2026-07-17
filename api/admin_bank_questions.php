<?php
// GET /admin_bank_questions.php?subject_id=&variant_id=
// Вопросы банка предмета (через темы → подтемы) + какие из них уже включены в вариант-пробник.
// Используется модалкой «Вопросы варианта» в админ-банке.
require __DIR__ . '/bootstrap.php';
require_admin();

$subjectId = (int)($_GET['subject_id'] ?? 0);
$variantId = (int)($_GET['variant_id'] ?? 0);
if ($subjectId <= 0) fail('Не указан предмет', 400);

// Все вопросы банка предмета (только привязанные к подтемам — homework/mock сюда не попадают).
$st = db()->prepare(
  "SELECT q.id, q.text, q.type, t.number AS topic_number, t.name AS topic_name, st.name AS subtopic_name
   FROM questions q
   JOIN subtopics st ON st.id = q.subtopic_id
   JOIN topics t ON t.id = st.topic_id
   WHERE t.subject_id = ?
   ORDER BY t.number, t.position, st.position, q.position, q.id"
);
$st->execute([$subjectId]);
$questions = [];
foreach ($st as $r) $questions[] = [
  'id'            => (int)$r['id'],
  'text'          => $r['text'],
  'type'          => $r['type'],
  'topic_number'  => $r['topic_number'] !== null ? (int)$r['topic_number'] : null,
  'topic_name'    => $r['topic_name'],
  'subtopic_name' => $r['subtopic_name'],
];

// Уже выбранные в варианте (связи variant_questions, в порядке).
$selected = [];
if ($variantId > 0) {
  $sel = db()->prepare("SELECT id, question_id, position FROM variant_questions WHERE variant_id = ? ORDER BY position, id");
  $sel->execute([$variantId]);
  foreach ($sel as $r) $selected[] = [
    'id'          => (int)$r['id'],
    'question_id' => (int)$r['question_id'],
    'position'    => (int)$r['position'],
  ];
}

ok(['questions' => $questions, 'selected' => $selected]);
