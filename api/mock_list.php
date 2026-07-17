<?php
// GET /mock_list.php — список пробников для раздела «Пробники» (Этап 6).
// Фильтры опциональные: ?subject=<имя>&month=<метка>. Со статусом прохождения ученика.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$subject = trim($_GET['subject'] ?? '');
$month = trim($_GET['month'] ?? '');

// Только пробники курсов, на которые ученик зачислён (доступ к пробникам = доступ к курсу).
$today = date('Y-m-d');
$sql =
  "SELECT m.id, m.month, m.title, s.name AS subject, s.icon, s.color,
          sub.status AS sub_status, sub.score, sub.max_score
   FROM mock_exams m
   JOIN courses c ON c.id = m.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
        AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
   LEFT JOIN submissions sub ON sub.mock_id = m.id AND sub.user_id = ?";
$args = [$uid, $today, $uid];
$where = [];
if ($subject !== '') { $where[] = 's.name = ?'; $args[] = $subject; }
if ($month !== '')   { $where[] = 'm.month = ?'; $args[] = $month; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY m.position, m.id';

$stmt = db()->prepare($sql);
$stmt->execute($args);
$mocks = [];
foreach ($stmt as $r) {
  $status = 'todo';
  if ($r['sub_status'] === 'review') $status = 'review';
  elseif ($r['sub_status'] === 'graded') $status = 'done';
  $mocks[] = [
    'id'        => (int)$r['id'],
    'month'     => $r['month'],
    'title'     => $r['title'],
    'subject'   => $r['subject'],
    'icon'      => $r['icon'],
    'color'     => $r['color'],
    'status'    => $status,
    'score'     => $r['score'] !== null ? (int)$r['score'] : null,
    'max_score' => $r['max_score'] !== null ? (int)$r['max_score'] : null,
  ];
}

// Доступные месяцы (для фильтра) — в порядке появления пробников (как расставил админ), не по алфавиту.
$months = [];
foreach (db()->query("SELECT month FROM mock_exams GROUP BY month ORDER BY MIN(position), MIN(id)") as $r) $months[] = $r['month'];

ok(['mocks' => $mocks, 'months' => $months]);
