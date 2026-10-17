<?php
// GET /homeworks_list.php — список ДЗ для раздела «Домашние задания».
// Только по курсам, на которые ученик зачислен. Со статусом сдачи и пометкой «замок» для
// повышенного уровня без подписки.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$today = date('Y-m-d');
$hardAccess = user_has_hard_access($uid);
$vip = g_is_vip($uid);

// ДЗ по курсам, где есть активное зачисление.
$stmt = db()->prepare(
  "SELECT hw.id, hw.level, hw.title, hw.deadline, c.id AS course_id, c.title AS course_title,
          m.name AS month, m.kind AS period_kind, s.name AS subject, s.icon, c.color,
          sub.status AS sub_status, sub.score, sub.max_score,
          COALESCE(de.extra_days, 0) AS extra_days
   FROM homeworks hw
   JOIN lessons l ON l.id = hw.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN months m ON m.id = b.month_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
        AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
   LEFT JOIN submissions sub ON sub.id = (
     SELECT sx.id FROM submissions sx WHERE sx.homework_id = hw.id AND sx.user_id = ? ORDER BY sx.id DESC LIMIT 1
   )
   LEFT JOIN (
     SELECT homework_id, SUM(extra_days) AS extra_days
     FROM deadline_extensions WHERE user_id = ? GROUP BY homework_id
   ) de ON de.homework_id = hw.id
   ORDER BY c.position, m.position, b.position, l.position, hw.position, hw.id"
);
$stmt->execute([$uid, $today, $uid, $uid]);

$homeworks = [];
foreach ($stmt as $r) {
  // Статус для UI: todo | review | done; locked — повышенное без подписки.
  $status = 'todo';
  if ($r['sub_status'] === 'review') $status = 'review';
  elseif ($r['sub_status'] === 'graded') $status = 'done';
  $deadline = trim((string)($r['deadline'] ?? ''));
  $extraDays = ($vip ? 1 : 0) + (int)$r['extra_days'];
  if ($deadline !== '' && $extraDays > 0) {
    $deadlineTs = strtotime($deadline);
    if ($deadlineTs) $deadline = date(strlen($deadline) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d', $deadlineTs + 86400 * $extraDays);
  }
  $homeworks[] = [
    'id'        => (int)$r['id'],
    'level'     => $r['level'],
    'title'     => $r['title'],
    'deadline'  => $deadline,
    'month'     => $r['month'],
    'period_kind' => ($r['period_kind'] ?? '') === 'block' ? 'block' : 'month',
    'course'    => $r['course_title'],
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
