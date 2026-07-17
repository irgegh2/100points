<?php
// GET /mock_list.php — список пробников для раздела «Пробники» (Этап 6).
// Фильтры опциональные: ?subject=<имя>&month=<метка>. Со статусом прохождения ученика.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();
release_session();

$subject = trim($_GET['subject'] ?? '');
$month = trim($_GET['month'] ?? '');

// Общие пробники — по доступу к курсу. Индивидуальные — только по назначению.
$today = date('Y-m-d');
$sql =
  "SELECT m.id, m.month, m.title, COALESCE(m.format,'normal') AS format,
          COALESCE(m.access_mode,'general') AS access_mode, s.name AS subject, s.icon, s.color,
          sub.status AS sub_status, sub.score, sub.max_score,
          a.id AS assignment_id, a.starts_at, a.duration, a.status AS assignment_status,
          a.camera_consent_at, a.started_at, a.finished_at
   FROM mock_exams m
   JOIN courses c ON c.id = m.course_id
   JOIN subjects s ON s.id = c.subject_id
   LEFT JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
        AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
   LEFT JOIN mock_assignments a ON a.mock_id = m.id AND a.user_id = ?
   LEFT JOIN submissions sub ON sub.mock_id = m.id AND sub.user_id = ?";
$args = [$uid, $today, $uid, $uid];
$where = ["((COALESCE(m.format,'normal') = 'proctored' AND a.id IS NOT NULL) OR (COALESCE(m.format,'normal') <> 'proctored' AND ((COALESCE(m.access_mode,'general') = 'general' AND e.user_id IS NOT NULL) OR (COALESCE(m.access_mode,'general') = 'individual' AND a.id IS NOT NULL))))"];
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
  $item = [
    'id'        => (int)$r['id'],
    'month'     => $r['month'],
    'title'     => $r['title'],
    'subject'   => $r['subject'],
    'icon'      => $r['icon'],
    'color'     => $r['color'],
    'status'    => $status,
    'score'     => $r['score'] !== null ? (int)$r['score'] : null,
    'max_score' => $r['max_score'] !== null ? (int)$r['max_score'] : null,
    'format' => $r['format'], 'access_mode' => $r['access_mode'],
    'assignment_id' => $r['assignment_id'] !== null ? (int)$r['assignment_id'] : null,
    'starts_at' => $r['starts_at'], 'duration' => $r['duration'] !== null ? (int)$r['duration'] : null,
  ];
  if ($r['assignment_id'] !== null) {
    $startTs = strtotime((string)$r['starts_at']);
    $item['starts_at_ts'] = $startTs ? $startTs * 1000 : null;
    $item['ends_at_ts'] = $startTs ? ($startTs + max(15, (int)($r['duration'] ?? 180)) * 60) * 1000 : null;
    $item['session_state'] = p_time_state([
      'format' => $r['format'], 'starts_at' => $r['starts_at'], 'duration' => $r['duration'],
      'status' => $r['assignment_status'], 'finished_at' => $r['finished_at'],
    ]);
  }
  $mocks[] = $item;
}

// Доступные месяцы (для фильтра) — в порядке появления пробников (как расставил админ), не по алфавиту.
$months = [];
foreach ($mocks as $mock) if (!in_array($mock['month'], $months, true)) $months[] = $mock['month'];

ok(['mocks' => $mocks, 'months' => $months, 'server_now_ms' => time() * 1000]);
