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
  "SELECT c.id, c.title, c.type, c.color, c.teacher_channel_url, s.name AS subject, s.icon, s.slug AS subject_slug
   FROM courses c JOIN subjects s ON s.id = c.subject_id
   WHERE c.id = ? LIMIT 1"
);
$cstmt->execute([$id]);
$course = $cstmt->fetch();
if (!$course) fail('Курс не найден', 404);
$hasAccess = user_has_course_access($uid, $id);

// Месяцы курса.
$mstmt = db()->prepare("SELECT id, name, kind, starts_on, ends_on, gallery_label FROM months WHERE course_id = ? ORDER BY position, id");
$mstmt->execute([$id]);
$months = [];
$monthIndex = [];
foreach ($mstmt as $m) {
  $mid = (int)$m['id'];
  $months[] = ['id' => $mid, 'name' => $m['name'], 'kind' => ($m['kind'] ?? '') === 'block' ? 'block' : 'month', 'starts_on' => $m['starts_on'], 'ends_on' => $m['ends_on'],
               'gallery_label' => $m['gallery_label'], 'photos' => [], 'blocks' => []];
  $monthIndex[$mid] = count($months) - 1;
}

// Фото-расписание месяцев (галерея, до 10 фото на месяц).
$pstmt = db()->prepare(
  "SELECT mp.month_id, mp.url FROM month_photos mp JOIN months m ON m.id = mp.month_id
   WHERE m.course_id = ? ORDER BY mp.position, mp.id"
);
$pstmt->execute([$id]);
foreach ($pstmt as $p) {
  $mid = (int)$p['month_id'];
  if (isset($monthIndex[$mid])) $months[$monthIndex[$mid]]['photos'][] = $p['url'];
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

// Условия открытия уроков курса (lesson_prereqs) одним запросом: lesson_id → [required_id, …].
$prstmt = db()->prepare(
  "SELECT p.lesson_id, p.required_lesson_id
   FROM lesson_prereqs p JOIN lessons l ON l.id = p.lesson_id JOIN blocks b ON b.id = l.block_id
   WHERE b.course_id = ?"
);
$prstmt->execute([$id]);
$prereqs = [];
foreach ($prstmt as $p) $prereqs[(int)$p['lesson_id']][] = (int)$p['required_lesson_id'];

// Уроки всех блоков курса одним запросом (разложим по блокам).
// done — отметка «пройдено» текущего ученика; ставится автоматически (сдача ДЗ / просмотр видео).
$lstmt = db()->prepare(
  "SELECT l.id, l.block_id, l.type, l.title, l.meta, l.duration, l.air, l.air_at, l.opens_at, l.kinescope_id, l.video_source, l.video_id, l.video_url,
          (SELECT COUNT(*) FROM lessons cp WHERE cp.parent_lesson_id=l.id) AS part_count,
          CASE
            WHEN EXISTS (SELECT 1 FROM lessons cp0 WHERE cp0.parent_lesson_id=l.id) THEN
              CASE WHEN EXISTS (
                SELECT 1 FROM lessons cp
                LEFT JOIN lesson_progress cpp ON cpp.lesson_id=cp.id AND cpp.user_id=?
                WHERE cp.parent_lesson_id=l.id AND (
                  cpp.id IS NULL OR EXISTS (
                    SELECT 1 FROM homeworks chd
                    WHERE chd.lesson_id=cp.id AND (chd.level='normal' OR chd.level IS NULL OR chd.level='')
                      AND NOT EXISTS (SELECT 1 FROM submissions csd WHERE csd.homework_id=chd.id AND csd.user_id=?)
                  )
                )
              ) THEN 0 ELSE 1 END
            WHEN lp.id IS NULL THEN 0
            WHEN EXISTS (
              SELECT 1 FROM homeworks hd
              WHERE hd.lesson_id = l.id AND (hd.level = 'normal' OR hd.level IS NULL OR hd.level = '')
                AND NOT EXISTS (SELECT 1 FROM submissions sd WHERE sd.homework_id = hd.id AND sd.user_id = ?)
            ) THEN 0 ELSE 1
          END AS done
   FROM lessons l JOIN blocks b ON b.id = l.block_id
   LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
   WHERE b.course_id = ? AND l.parent_lesson_id IS NULL ORDER BY l.position, l.id"
);
$lstmt->execute([$uid, $uid, $uid, $uid, $id]);
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
    'air_at'      => $l['air_at'] ?: null,
    'opens_at'    => $l['opens_at'] ?: null,
    'prereq_ids'  => $prereqs[(int)$l['id']] ?? [],
    'has_video'   => (($l['video_source'] ?? '') === 'native' && !empty($l['video_id']))
      || (($l['video_source'] ?? '') === 'link' && trim((string)($l['video_url'] ?? '')) !== '')
      || !empty($l['kinescope_id']),
    'part_count'  => (int)$l['part_count'],
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
    // Ссылка действительно закрытая: не отдаём её даже в JSON без доступа к курсу.
    'teacher_channel_url' => $hasAccess && trim((string)($course['teacher_channel_url'] ?? '')) !== '' ? $course['teacher_channel_url'] : null,
  ],
  'has_access' => $hasAccess,
  'months' => $months,
  'today'  => date('Y-m-d'),       // серверная дата (МСК) — по ней фронт выбирает текущий месяц
  'now'    => date('Y-m-d H:i'),   // серверное время (МСК) — для «урок откроется …» (opens_at)
]);
