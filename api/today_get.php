<?php
// GET /today_get.php — блок «Обучение сегодня» на Главной.
// Сегодняшние эфиры/практики + домашки, у которых СЕГОДНЯ последний день дедлайна (и не сданы). Пробников нет.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$today = date('Y-m-d');
$deadlineSourceStart = date('Y-m-d', strtotime('-2 days'));
$items = [];

// 1) События сегодня (в доступных курсах).
$ev = db()->prepare(
  "SELECT e.type, e.title, e.starts_at, e.link, s.name AS subject, s.color AS color
   FROM events e
   JOIN courses c ON c.id = e.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
     AND (en.expires_at IS NULL OR en.expires_at = '' OR en.expires_at >= ?)
   WHERE substr(e.starts_at,1,10) = ?
   ORDER BY e.starts_at"
);
$ev->execute([$uid, $today, $today]);
foreach ($ev as $r) $items[] = [
  'kind' => $r['type'] === 'practice' ? 'practice' : 'webinar',
  'title' => $r['title'], 'subject' => $r['subject'], 'color' => $r['color'] ?: '#3f9bff',
  'meta' => 'Сегодня ' . substr($r['starts_at'], 11, 5), 'link' => $r['link'], 'action' => 'Подключиться',
];

// 1b) Уроки из программы курса (вебинар/теория/практика) с эфиром сегодня.
$wl = db()->prepare(
  "SELECT l.type, l.title, l.air_at, l.kinescope_id, s.name AS subject, s.color AS color
   FROM lessons l
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
     AND (en.expires_at IS NULL OR en.expires_at = '' OR en.expires_at >= ?)
   WHERE l.air_at IS NOT NULL AND l.air_at <> '' AND substr(l.air_at,1,10) = ?
   ORDER BY l.air_at"
);
$wl->execute([$uid, $today, $today]);
foreach ($wl as $r) $items[] = [
  'kind' => in_array($r['type'], ['practice', 'theory', 'webinar'], true) ? $r['type'] : 'webinar',
  'title' => $r['title'], 'subject' => $r['subject'], 'color' => $r['color'] ?: '#3f9bff',
  'meta' => 'Сегодня ' . substr($r['air_at'], 11, 5), 'action' => 'Подключиться',
  'link' => $r['kinescope_id'] ? 'https://kinescope.io/' . $r['kinescope_id'] : null,
];

// 2) Домашки с дедлайном сегодня (доступные, ещё не сданные).
$hw = db()->prepare(
  "SELECT hw.id, hw.title, s.name AS subject, s.color AS color, hw.deadline
   FROM homeworks hw
   JOIN lessons l ON l.id = hw.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
     AND (en.expires_at IS NULL OR en.expires_at = '' OR en.expires_at >= ?)
   WHERE substr(hw.deadline,1,10) BETWEEN ? AND ?
     AND NOT EXISTS (SELECT 1 FROM submissions sub WHERE sub.homework_id = hw.id AND sub.user_id = ?)
   ORDER BY hw.id"
);
$hw->execute([$uid, $today, $deadlineSourceStart, $today, $uid]);
foreach ($hw as $r) {
  if (substr(g_effective_deadline($uid, $r['deadline'], (int)$r['id']), 0, 10) !== $today) continue;
  $items[] = [
    'kind' => 'homework', 'title' => $r['title'], 'subject' => $r['subject'], 'color' => $r['color'] ?: '#ff7a00',
    'meta' => 'Последний день сдачи!', 'urgent' => true, 'action' => 'Решать',
  ];
}

ok(['items' => $items, 'date' => $today]);
