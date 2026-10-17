<?php
// GET /admin_course_lessons.php?course_id=<id>
// Все уроки курса одним списком (месяц → блок → урок) — для выбора уроков-условий
// в настройке «Блокировка» (lesson_prereqs) в редакторе курса.
require __DIR__ . '/bootstrap.php';
require_admin();

$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) fail('Не указан курс', 400);

$st = db()->prepare(
  "SELECT l.id, l.title, l.type, b.n AS block_n, m.name AS month_name, m.kind AS period_kind
   FROM lessons l
   JOIN blocks b ON b.id = l.block_id
   JOIN months m ON m.id = b.month_id
   WHERE b.course_id = ?
   ORDER BY m.position, m.id, b.position, b.id, l.position, l.id"
);
$st->execute([$courseId]);

$items = [];
foreach ($st as $l) {
  $items[] = [
    'id'    => (int)$l['id'],
    'title' => $l['title'],
    'type'  => $l['type'],
    'block' => (int)$l['block_n'],
    'month' => $l['month_name'],
    'period_kind' => ($l['period_kind'] ?? '') === 'block' ? 'block' : 'month',
  ];
}
ok(['items' => $items]);
