<?php
// GET /library_get.php?subject_id= — единая библиотека материалов из доступных уроков.
// Файлы не дублируются в отдельном каталоге: название группы всегда совпадает
// с названием урока, а состав — с блоком «Материалы по уроку» внутри курса.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$subjects = user_accessible_subjects($uid);
$allowedSubjectIds = array_map(fn($subject) => (int)$subject['id'], $subjects);
$subjectId = (int)($_GET['subject_id'] ?? 0);
if ($subjectId > 0 && !in_array($subjectId, $allowedSubjectIds, true)) {
  fail('Нет доступа к предмету', 403);
}

$today = date('Y-m-d');
$now = date('Y-m-d H:i');
$args = [$uid, $today, $now, $uid, $uid];
$subjectSql = '';
if ($subjectId > 0) {
  $subjectSql = ' AND s.id = ?';
  $args[] = $subjectId;
}

// Условия открытия те же, что в самом курсе: дата открытия уже наступила,
// а все уроки-предусловия действительно пройдены вместе с базовыми ДЗ.
$stmt = db()->prepare(
  "SELECT mt.id, mt.name, mt.size, mt.url, mt.kind,
          l.id AS lesson_id, l.title AS lesson_title,
          b.n AS section_n, b.title AS section_title,
          m.name AS period_name, m.kind AS period_kind,
          c.id AS course_id, c.title AS course_title,
          s.id AS subject_id, s.name AS subject_name, s.color AS subject_color
   FROM materials mt
   JOIN lessons l ON l.id = mt.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN months m ON m.id = b.month_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   WHERE EXISTS (
     SELECT 1 FROM enrollments e
     WHERE e.user_id = ? AND e.course_id = c.id
       AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
   )
     AND c.type = 'Основные'
     AND COALESCE(s.show_features, 1) = 1
     AND mt.url IS NOT NULL AND TRIM(mt.url) <> ''
     AND (l.opens_at IS NULL OR l.opens_at = '' OR l.opens_at <= ?)
     AND NOT EXISTS (
       SELECT 1
       FROM lesson_prereqs p
       JOIN lessons required_lesson ON required_lesson.id = p.required_lesson_id
       LEFT JOIN lesson_progress lp
         ON lp.lesson_id = p.required_lesson_id AND lp.user_id = ?
       WHERE p.lesson_id = l.id AND (
         lp.id IS NULL OR EXISTS (
           SELECT 1 FROM homeworks required_hw
           WHERE required_hw.lesson_id = required_lesson.id
             AND (required_hw.level = 'normal' OR required_hw.level IS NULL OR required_hw.level = '')
             AND NOT EXISTS (
               SELECT 1 FROM submissions required_submission
               WHERE required_submission.homework_id = required_hw.id
                 AND required_submission.user_id = ?
             )
         )
       )
     )
     $subjectSql
   ORDER BY s.position, s.name,
            CASE c.type WHEN 'Основные' THEN 0 WHEN 'Летняя школа' THEN 1 WHEN 'Вводные' THEN 2 ELSE 3 END,
            c.title, m.position, m.id, b.position, b.id, l.position, l.id, mt.position, mt.id"
);
$stmt->execute($args);

$items = [];
foreach ($stmt as $row) {
  $items[] = [
    'id' => (int)$row['id'],
    'subject_id' => (int)$row['subject_id'],
    'subject_name' => $row['subject_name'],
    'subject_color' => $row['subject_color'] ?: '#3f9bff',
    'course_id' => (int)$row['course_id'],
    'course_title' => $row['course_title'],
    'period_name' => $row['period_name'],
    'period_kind' => ($row['period_kind'] ?? '') === 'block' ? 'block' : 'month',
    'section_n' => (int)$row['section_n'],
    'section_title' => $row['section_title'],
    'lesson_id' => (int)$row['lesson_id'],
    'lesson_title' => $row['lesson_title'],
    'title' => $row['name'],
    'url' => $row['url'],
    'size' => $row['size'],
    'kind' => $row['kind'],
  ];
}

ok(['subjects' => $subjects, 'items' => $items]);
