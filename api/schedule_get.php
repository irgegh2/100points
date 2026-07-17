<?php
// GET /schedule_get.php?week=YYYY-MM-DD — события недели (раздел «Расписание», навигация ‹ ›).
// Источники (всё гейтится зачислением на курс):
//   1) events — события из Админка→Расписание;
//   2) уроки-вебинары из программы курса (lessons type=webinar с датой эфира air_at);
//   3) дедлайны домашних заданий (показываем на дату дедлайна).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$anchor = (string)($_GET['week'] ?? date('Y-m-d'));
if (!strtotime($anchor)) $anchor = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week', strtotime($anchor)));
$sunday = date('Y-m-d', strtotime("$monday +6 days"));
$today = date('Y-m-d');
$deadlineStart = g_is_vip($uid) ? date('Y-m-d', strtotime("$monday -1 day")) : $monday;
$enrollOk = "(en.expires_at IS NULL OR en.expires_at = '' OR en.expires_at >= ?)";

$all = []; // плоский список событий недели

// 1) События расписания.
$st = db()->prepare(
  "SELECT e.id, e.type, e.title, e.starts_at, e.duration, e.link, s.name AS subject, s.color AS color
   FROM events e
   JOIN courses c ON c.id = e.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? AND $enrollOk
   WHERE substr(e.starts_at,1,10) BETWEEN ? AND ?"
);
$st->execute([$uid, $today, $monday, $sunday]);
foreach ($st as $r) $all[] = [
  'date' => substr($r['starts_at'], 0, 10), 'time' => substr($r['starts_at'], 11, 5),
  'kind' => $r['type'] === 'practice' ? 'practice' : 'webinar', 'title' => $r['title'],
  'subject' => $r['subject'], 'color' => $r['color'] ?: '#3f9bff', 'link' => $r['link'],
  'duration' => (int)$r['duration'] ?: 60,
];

// 2) Уроки из программы курса (вебинар/теория/практика) с проставленной датой эфира.
$st = db()->prepare(
  "SELECT l.type, l.title, l.air_at, l.duration, l.kinescope_id, s.name AS subject, s.color AS color
   FROM lessons l
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? AND $enrollOk
   WHERE l.air_at IS NOT NULL AND l.air_at <> ''
     AND substr(l.air_at,1,10) BETWEEN ? AND ?"
);
$st->execute([$uid, $today, $monday, $sunday]);
foreach ($st as $r) {
  // duration у урока — текст («90 мин»): вытащим число, иначе 90.
  $dur = preg_match('/\d+/', (string)$r['duration'], $m) ? (int)$m[0] : 90;
  $all[] = [
    'date' => substr($r['air_at'], 0, 10), 'time' => substr($r['air_at'], 11, 5),
    'kind' => in_array($r['type'], ['practice', 'theory', 'webinar'], true) ? $r['type'] : 'webinar',
    'title' => $r['title'], 'subject' => $r['subject'], 'color' => $r['color'] ?: '#3f9bff',
    'link' => $r['kinescope_id'] ? 'https://kinescope.io/' . $r['kinescope_id'] : null,
    'duration' => $dur,
  ];
}

// 3) Дедлайны домашних заданий.
$st = db()->prepare(
  "SELECT hw.title, hw.deadline, s.name AS subject, s.color AS color
   FROM homeworks hw
   JOIN lessons l ON l.id = hw.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? AND $enrollOk
   WHERE hw.deadline IS NOT NULL AND hw.deadline <> ''
     AND substr(hw.deadline,1,10) BETWEEN ? AND ?"
);
$st->execute([$uid, $today, $deadlineStart, $sunday]);
foreach ($st as $r) {
  $effectiveDeadline = g_effective_deadline($uid, $r['deadline']);
  $all[] = [
    'date' => substr($effectiveDeadline, 0, 10), 'time' => '', 'kind' => 'deadline', 'allday' => true,
    'title' => $r['title'], 'subject' => $r['subject'], 'color' => '#e0584f', 'link' => null, 'duration' => 0,
  ];
}

// Раскладываем по дням, дедлайны — в конец дня.
$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = date('Y-m-d', strtotime("$monday +$i days"));
  $days[$d] = ['date' => $d, 'today' => $d === $today, 'events' => []];
}
usort($all, fn($a, $b) => ($a['time'] ?: '99:99') <=> ($b['time'] ?: '99:99'));
$idc = 0;
foreach ($all as $e) {
  if (!isset($days[$e['date']])) continue;
  $e['id'] = ++$idc;
  $days[$e['date']]['events'][] = $e;
}

ok([
  'week_start' => $monday,
  'prev' => date('Y-m-d', strtotime("$monday -7 days")),
  'next' => date('Y-m-d', strtotime("$monday +7 days")),
  'days' => array_values($days),
]);
