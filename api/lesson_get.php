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
  "SELECT l.id, l.parent_lesson_id, l.type, l.title, l.meta, l.kinescope_id, l.video_source, l.video_id, l.video_url, l.air, l.air_at, l.duration, l.opens_at, l.description, l.restream_url, l.restream_duration,
          parent.opens_at AS parent_opens_at,
          b.id AS block_id, b.title AS block_title,
          c.id AS course_id, c.title AS course_title,
          s.name AS subject
   FROM lessons l
   LEFT JOIN lessons parent ON parent.id=l.parent_lesson_id
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
      'parent_lesson_id' => $l['parent_lesson_id'] !== null ? (int)$l['parent_lesson_id'] : null,
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
    'parts' => [],
  ]);
}

// Стаб урока без контента — для «откроется …» и «сначала пройди …» (видео/материалы не утекают).
$stub = function (array $extra) use ($l) {
  ok(array_merge([
    'locked' => false,
    'lesson' => [
      'id'           => (int)$l['id'],
      'parent_lesson_id' => $l['parent_lesson_id'] !== null ? (int)$l['parent_lesson_id'] : null,
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
    'homeworks' => [],
    'parts' => [],
  ], $extra));
};

// «Открыть доступ»: до назначенного времени урок закрыт для всех (видно только когда откроется).
$opensAt = max(trim((string)($l['opens_at'] ?? '')), trim((string)($l['parent_opens_at'] ?? '')));
if ($opensAt !== '' && $opensAt > date('Y-m-d H:i')) {
  $stub(['not_open' => true, 'opens_at' => $opensAt]);
}

// Блокировка: вложенное занятие наследует условия контейнера и может иметь свои.
$gateLessonIds = [(int)$l['id']];
if (!empty($l['parent_lesson_id'])) array_unshift($gateLessonIds, (int)$l['parent_lesson_id']);
$gatePh = implode(',', array_fill(0, count($gateLessonIds), '?'));
$pq = db()->prepare(
  "SELECT r.title FROM lesson_prereqs p
   JOIN lessons r ON r.id = p.required_lesson_id
   LEFT JOIN lesson_progress lp ON lp.lesson_id = p.required_lesson_id AND lp.user_id = ?
   WHERE p.lesson_id IN ($gatePh) AND (
     lp.id IS NULL OR EXISTS (
       SELECT 1 FROM homeworks hp
       WHERE hp.lesson_id = r.id AND (hp.level = 'normal' OR hp.level IS NULL OR hp.level = '')
         AND NOT EXISTS (SELECT 1 FROM submissions sp WHERE sp.homework_id = hp.id AND sp.user_id = ?)
     )
   )
   ORDER BY r.position, r.id"
);
$pq->execute(array_merge([$uid], $gateLessonIds, [$uid]));
$needTitles = $pq->fetchAll(PDO::FETCH_COLUMN);
if ($needTitles) {
  $stub(['need_lessons' => $needTitles]);
}

// Лёгкий список вложенных занятий. Тяжёлый контент выбранного пункта клиент
// запросит отдельно, поэтому составной урок не замедляет первое открытие.
$parts = [];
if (empty($l['parent_lesson_id'])) {
  $partSt = db()->prepare(
    "SELECT cp.id,cp.type,cp.title,cp.meta,cp.duration,cp.opens_at,cp.kinescope_id,cp.video_source,cp.video_id,cp.video_url,
            CASE WHEN lp.id IS NULL THEN 0
              WHEN EXISTS (
                SELECT 1 FROM homeworks hd
                WHERE hd.lesson_id=cp.id AND (hd.level='normal' OR hd.level IS NULL OR hd.level='')
                  AND NOT EXISTS (SELECT 1 FROM submissions sd WHERE sd.homework_id=hd.id AND sd.user_id=?)
              ) THEN 0 ELSE 1 END AS done
     FROM lessons cp
     LEFT JOIN lesson_progress lp ON lp.lesson_id=cp.id AND lp.user_id=?
     WHERE cp.parent_lesson_id=?
     ORDER BY cp.position,cp.id"
  );
  $partSt->execute([$uid,$uid,$id]);
  $partRows = $partSt->fetchAll();
  $unmetByPart = [];
  if ($partRows) {
    $partIds = array_map(fn($r) => (int)$r['id'], $partRows);
    $partPh = implode(',', array_fill(0, count($partIds), '?'));
    $unmetSt = db()->prepare(
      "SELECT p.lesson_id,r.title FROM lesson_prereqs p
       JOIN lessons r ON r.id=p.required_lesson_id
       LEFT JOIN lesson_progress lp ON lp.lesson_id=p.required_lesson_id AND lp.user_id=?
       WHERE p.lesson_id IN ($partPh) AND (
         lp.id IS NULL OR EXISTS (
           SELECT 1 FROM homeworks hp
           WHERE hp.lesson_id=r.id AND (hp.level='normal' OR hp.level IS NULL OR hp.level='')
             AND NOT EXISTS (SELECT 1 FROM submissions sp WHERE sp.homework_id=hp.id AND sp.user_id=?)
         )
       ) ORDER BY r.position,r.id"
    );
    $unmetSt->execute(array_merge([$uid], $partIds, [$uid]));
    foreach ($unmetSt as $u) $unmetByPart[(int)$u['lesson_id']][] = $u['title'];
  }
  $now = date('Y-m-d H:i');
  foreach ($partRows as $part) {
    $partId = (int)$part['id'];
    $partOpensAt = trim((string)($part['opens_at'] ?? ''));
    $need = $unmetByPart[$partId] ?? [];
    $lockType = $partOpensAt !== '' && $partOpensAt > $now ? 'date' : ($need ? 'prereq' : null);
    $parts[] = [
      'id' => $partId,
      'type' => $part['type'],
      'title' => $part['title'],
      'meta' => $part['meta'],
      'duration' => $part['duration'],
      'opens_at' => $partOpensAt !== '' ? $partOpensAt : null,
      'has_video' => (($part['video_source'] ?? '') === 'native' && !empty($part['video_id']))
        || (($part['video_source'] ?? '') === 'link' && trim((string)($part['video_url'] ?? '')) !== '')
        || !empty($part['kinescope_id']),
      'done' => (int)$part['done'] === 1,
      'locked' => $lockType !== null,
      'lock_type' => $lockType,
      'need_lessons' => $need,
    ];
  }
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

// ДЗ этого урока (для кнопок). Продвинутое помечаем замком, если нет подписки Стандарт+.
// done — есть ли сдача текущего ученика (кнопка становится «Посмотреть результат»).
$hstmt = db()->prepare(
  "SELECT h.id, h.level, h.title, CASE WHEN EXISTS (
       SELECT 1 FROM submissions sx WHERE sx.homework_id = h.id AND sx.user_id = ?
     ) THEN 1 ELSE 0 END AS done
   FROM homeworks h
   WHERE h.lesson_id = ? ORDER BY h.position, h.id"
);
$hstmt->execute([$uid, $id]);
$hardAccess = user_has_hard_access($uid);
$homeworks = [];
foreach ($hstmt as $h) {
  $homeworks[] = [
    'id'     => (int)$h['id'],
    'level'  => $h['level'],
    'title'  => $h['title'],
    'locked' => $h['level'] === 'hard' && !$hardAccess,
    'done'   => (int)$h['done'] === 1,
  ];
}

$video = null;
if (($l['video_source'] ?? '') === 'native' && !empty($l['video_id'])) {
  $vstmt = db()->prepare("SELECT id,title,status,duration_sec,width,height,poster_key,qualities FROM videos WHERE id=? AND lesson_id=? LIMIT 1");
  $vstmt->execute([(int)$l['video_id'],$id]);
  $v = $vstmt->fetch();
  if ($v && $v['status'] === 'ready') {
    $video = [
      'id'=>(int)$v['id'], 'title'=>$v['title'], 'status'=>$v['status'],
      'duration_sec'=>(int)$v['duration_sec'], 'width'=>(int)$v['width'], 'height'=>(int)$v['height'],
      'poster_url'=>!empty($v['poster_key']) ? '/api/video_asset.php?id='.(int)$v['id'].'&kind=poster' : null,
      'qualities'=>json_decode((string)($v['qualities'] ?? ''),true) ?: [],
    ];
  }
}

$externalVideo = null;
$videoUrl = trim((string)($l['video_url'] ?? ''));
if (($l['video_source'] ?? '') === 'link' && $videoUrl !== '') {
  $embed = external_stream_embed($videoUrl);
  $externalVideo = [
    'url' => $videoUrl,
    'provider' => $embed['provider'] ?? null,
    'embed_url' => $embed['embed_url'] ?? null,
    'player' => ($embed['provider'] ?? null) === 'educontent'
      ? ['hls_url' => '/api/educontent_stream.php?lesson_id=' . $id . '&file=playlist.m3u8']
      : null,
  ];
}

$restream = null;
$restreamUrl = trim((string)($l['restream_url'] ?? ''));
$hasReadyVideo = $video !== null || $externalVideo !== null || trim((string)($l['kinescope_id'] ?? '')) !== '';
if ($restreamUrl !== '' && !$hasReadyVideo) {
  $minutes = max(1, (int)($l['restream_duration'] ?? 120));
  $airTs = trim((string)($l['air_at'] ?? '')) !== '' ? strtotime((string)$l['air_at']) : null;
  // До начала кнопка уже видна. После старта живёт длительность эфира + 30 минут.
  $expiresTs = $airTs ? $airTs + ($minutes + 30) * 60 : null;
  if ($expiresTs === null || time() < $expiresTs) {
    $embed = external_stream_embed($restreamUrl);
    $restream = [
      'url' => $restreamUrl,
      'visible' => true,
      'duration_minutes' => $minutes,
      'expires_at_ts' => $expiresTs,
      'provider' => $embed['provider'] ?? null,
      'embed_url' => $embed['embed_url'] ?? null,
    ];
  }
}

ok([
  'locked' => false,
  'lesson' => [
    'id'           => (int)$l['id'],
    'parent_lesson_id' => $l['parent_lesson_id'] !== null ? (int)$l['parent_lesson_id'] : null,
    'type'         => $l['type'],
    'title'        => $l['title'],
    'meta'         => $l['meta'],
    'kinescope_id' => $l['kinescope_id'],
    'video_source' => $l['video_source'] ?: (!empty($l['kinescope_id']) ? 'kinescope' : 'none'),
    'video_id'     => $l['video_id'] !== null ? (int)$l['video_id'] : null,
    'video_url'    => $videoUrl !== '' ? $videoUrl : null,
    'air'          => $l['air'],
    'air_at'       => trim((string)($l['air_at'] ?? '')) !== '' ? $l['air_at'] : null,
    'duration'     => $l['duration'],
    'description'  => trim((string)($l['description'] ?? '')) !== '' ? $l['description'] : null,
    'subject'      => $l['subject'],
    'block_id'     => (int)$l['block_id'],
    'block_title'  => $l['block_title'],
    'course_id'    => (int)$l['course_id'],
    'course_title' => $l['course_title'],
  ],
  'materials'  => $materials,
  'timecodes'  => $timecodes,
  'homeworks'  => $homeworks,
  'parts'      => $parts,
  'video'      => $video,
  'external_video' => $externalVideo,
  'restream'   => $restream,
  // Unix-время не зависит от часового пояса устройства ученика. Фронт также
  // получает время сервера, чтобы неверные часы на телефоне не сдвигали отсчёт.
  'air_at_ts'  => trim((string)($l['air_at'] ?? '')) !== '' ? strtotime((string)$l['air_at']) : null,
  'server_ts'  => time(),
]);
