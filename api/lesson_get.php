<?php
// GET /lesson_get.php?id=<id>
// Один урок: видео (kinescope_id), материалы и тайм-коды + контекст (предмет, курс, блок).
// Гейтинг (Этап 3): нужен вход; без доступа к курсу видео/материалы НЕ отдаём — только «замок».
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session(); // read-only — снимаем лок сессии сразу

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указан урок', 400);

$lstmt = db()->prepare(
  "SELECT l.id, l.type, l.title, l.meta, l.kinescope_id, l.air, l.duration, l.locked_hint,
          b.id AS block_id, b.title AS block_title,
          c.id AS course_id, c.title AS course_title,
          s.name AS subject
   FROM lessons l
   JOIN blocks b   ON b.id = l.block_id
   JOIN courses c  ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   WHERE l.id = ? LIMIT 1"
);
$lstmt->execute([$id]);
$l = $lstmt->fetch();
if (!$l) fail('Урок не найден', 404);

// Нет доступа к курсу → отдаём «замок» без видео/материалов (контент не утекает клиенту).
if (!user_has_course_access($uid, (int)$l['course_id'])) {
  ok([
    'locked' => true,
    'lesson' => [
      'id'           => (int)$l['id'],
      'type'         => $l['type'],
      'title'        => $l['title'],
      'subject'      => $l['subject'],
      'block_id'     => (int)$l['block_id'],
      'block_title'  => $l['block_title'],
      'course_id'    => (int)$l['course_id'],
      'course_title' => $l['course_title'],
    ],
    'materials' => [],
    'timecodes' => [],
  ]);
}

$mstmt = db()->prepare("SELECT id, name, size, url, kind FROM materials WHERE lesson_id = ? ORDER BY position, id");
$mstmt->execute([$id]);
$materials = [];
foreach ($mstmt as $m) {
  $materials[] = [
    'id'   => (int)$m['id'],
    'name' => $m['name'],
    'size' => $m['size'],
    'url'  => $m['url'],
    'kind' => $m['kind'],
  ];
}

$tstmt = db()->prepare("SELECT id, t, label FROM timecodes WHERE lesson_id = ? ORDER BY position, id");
$tstmt->execute([$id]);
$timecodes = [];
foreach ($tstmt as $t) {
  $timecodes[] = ['id' => (int)$t['id'], 't' => $t['t'], 'label' => $t['label']];
}

// ДЗ этого урока (для кнопок). Повышенное помечаем замком, если нет подписки Стандарт+.
$hstmt = db()->prepare("SELECT id, level, title FROM homeworks WHERE lesson_id = ? ORDER BY position, id");
$hstmt->execute([$id]);
$hardAccess = user_has_hard_access($uid);
$homeworks = [];
foreach ($hstmt as $h) {
  $homeworks[] = [
    'id'     => (int)$h['id'],
    'level'  => $h['level'],
    'title'  => $h['title'],
    'locked' => $h['level'] === 'hard' && !$hardAccess,
  ];
}

ok([
  'locked' => false,
  'lesson' => [
    'id'           => (int)$l['id'],
    'type'         => $l['type'],
    'title'        => $l['title'],
    'meta'         => $l['meta'],
    'kinescope_id' => $l['kinescope_id'],
    'air'          => $l['air'],
    'duration'     => $l['duration'],
    'subject'      => $l['subject'],
    'block_id'     => (int)$l['block_id'],
    'block_title'  => $l['block_title'],
    'course_id'    => (int)$l['course_id'],
    'course_title' => $l['course_title'],
  ],
  'materials'  => $materials,
  'timecodes'  => $timecodes,
  'homeworks'  => $homeworks,
]);
