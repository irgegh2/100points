<?php
// GET /course_get.php?id=<id>
// Полная программа курса: курс → месяцы → блоки → уроки (для раздела «Урок внутри курса»).
// Уроки отдаём без «тяжёлых» данных (материалы/тайм-коды грузятся в lesson_get).
require __DIR__ . '/bootstrap.php';
$uid = (int)($_SESSION['uid'] ?? 0); // читаем до release: программа видна всем, но отметим доступ
release_session(); // read-only — снимаем лок сессии сразу

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указан курс', 400);

$cstmt = db()->prepare(
  "SELECT c.id, c.title, c.type, c.color, s.name AS subject, s.icon, s.slug AS subject_slug
   FROM courses c JOIN subjects s ON s.id = c.subject_id
   WHERE c.id = ? LIMIT 1"
);
$cstmt->execute([$id]);
$course = $cstmt->fetch();
if (!$course) fail('Курс не найден', 404);

// Месяцы курса.
$mstmt = db()->prepare("SELECT id, name FROM months WHERE course_id = ? ORDER BY position, id");
$mstmt->execute([$id]);
$months = [];
$monthIndex = [];
foreach ($mstmt as $m) {
  $mid = (int)$m['id'];
  $months[] = ['id' => $mid, 'name' => $m['name'], 'blocks' => []];
  $monthIndex[$mid] = count($months) - 1;
}

// Блоки курса (разложим по месяцам).
$bstmt = db()->prepare("SELECT id, month_id, n, title FROM blocks WHERE course_id = ? ORDER BY position, id");
$bstmt->execute([$id]);
$blockIndex = [];
foreach ($bstmt as $b) {
  $mid = (int)$b['month_id'];
  if (!isset($monthIndex[$mid])) continue;
  $mi = $monthIndex[$mid];
  $months[$mi]['blocks'][] = [
    'id'      => (int)$b['id'],
    'n'       => (int)$b['n'],
    'title'   => $b['title'],
    'lessons' => [],
  ];
  $blockIndex[(int)$b['id']] = [$mi, count($months[$mi]['blocks']) - 1];
}

// Уроки всех блоков курса одним запросом (разложим по блокам).
// done — отметка «пройдено» текущего ученика (для фильтра «Скрыть пройденное»); для гостя всегда 0.
$lstmt = db()->prepare(
  "SELECT l.id, l.block_id, l.type, l.title, l.meta, l.duration, l.air, l.locked_hint, l.kinescope_id,
          CASE WHEN lp.id IS NULL THEN 0 ELSE 1 END AS done
   FROM lessons l JOIN blocks b ON b.id = l.block_id
   LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
   WHERE b.course_id = ? ORDER BY l.position, l.id"
);
$lstmt->execute([$uid, $id]);
foreach ($lstmt as $l) {
  $bid = (int)$l['block_id'];
  if (!isset($blockIndex[$bid])) continue;
  [$mi, $bi] = $blockIndex[$bid];
  $months[$mi]['blocks'][$bi]['lessons'][] = [
    'id'          => (int)$l['id'],
    'type'        => $l['type'],
    'title'       => $l['title'],
    'meta'        => $l['meta'],
    'duration'    => $l['duration'],
    'air'         => $l['air'],
    'locked_hint' => $l['locked_hint'],
    'has_video'   => !empty($l['kinescope_id']),
    'done'        => (int)$l['done'] === 1,
  ];
}

ok([
  'course' => [
    'id'      => (int)$course['id'],
    'title'   => $course['title'],
    'type'    => $course['type'],
    'color'   => $course['color'],
    'subject' => $course['subject'],
    'icon'    => $course['icon'],
  ],
  'has_access' => user_has_course_access($uid, $id),
  'months' => $months,
]);
