<?php
// GET /homeworks_list.php — список ДЗ для раздела «Домашние задания».
// Только по курсам, на которые ученик зачислен. Со статусом сдачи и пометкой «замок» для
// повышенного уровня без подписки.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$today = date('Y-m-d');
$hardAccess = user_has_hard_access($uid);

// ДЗ по курсам, где есть активное зачисление.
$stmt = db()->prepare(
  "SELECT hw.id, hw.level, hw.title, hw.deadline, c.id AS course_id, s.name AS subject, s.icon, c.color,
          sub.status AS sub_status, sub.score, sub.max_score
   FROM homeworks hw
   JOIN lessons l ON l.id = hw.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
        AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
   LEFT JOIN submissions sub ON sub.homework_id = hw.id AND sub.user_id = ?
   ORDER BY c.position, hw.position, hw.id"
);
$stmt->execute([$uid, $today, $uid]);

$homeworks = [];
foreach ($stmt as $r) {
  // Статус для UI: todo | review | done; locked — повышенное без подписки.
  $status = 'todo';
  if ($r['sub_status'] === 'review') $status = 'review';
  elseif ($r['sub_status'] === 'graded') $status = 'done';
  $homeworks[] = [
    'id'        => (int)$r['id'],
    'level'     => $r['level'],
    'title'     => $r['title'],
    'deadline'  => $r['deadline'],
    'subject'   => $r['subject'],
    'icon'      => $r['icon'],
    'color'     => $r['color'],
    'status'    => $status,
    'locked'    => $r['level'] === 'hard' && !$hardAccess,
    'score'     => $r['score'] !== null ? (int)$r['score'] : null,
    'max_score' => $r['max_score'] !== null ? (int)$r['max_score'] : null,
  ];
}

ok(['homeworks' => $homeworks]);
