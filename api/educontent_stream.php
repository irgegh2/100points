<?php
// Same-origin HLS-шлюз для постоянных ссылок EduContent.
// Браузер не может загрузить их HLS напрямую: внешний сервер не отдаёт CORS.
// Шлюз привязан к конкретному доступному ученику уроку и принимает только
// ожидаемые имена плейлиста, ключа и TS-сегментов — произвольного прокси нет.
require __DIR__ . '/bootstrap.php';

$uid = require_user();
$lessonId = max(0, (int)($_GET['lesson_id'] ?? 0));
$file = trim((string)($_GET['file'] ?? 'playlist.m3u8'));
if ($lessonId <= 0) fail('Урок не указан', 400);
if (!preg_match('/^(?:playlist\.m3u8|video\.key|playlist\d+\.ts)$/', $file)) fail('Файл потока не поддерживается', 400);

$st = db()->prepare(
  "SELECT l.id,l.parent_lesson_id,l.video_source,l.video_url,l.opens_at,
          parent.opens_at AS parent_opens_at,c.id AS course_id
   FROM lessons l
   LEFT JOIN lessons parent ON parent.id=l.parent_lesson_id
   JOIN blocks b ON b.id=l.block_id
   JOIN courses c ON c.id=b.course_id
   WHERE l.id=? LIMIT 1"
);
$st->execute([$lessonId]);
$lesson = $st->fetch();
if (!$lesson || !user_has_course_access($uid, (int)$lesson['course_id'])) fail('Видео недоступно', 403);

$opensAt = max(trim((string)($lesson['opens_at'] ?? '')), trim((string)($lesson['parent_opens_at'] ?? '')));
if ($opensAt !== '' && $opensAt > date('Y-m-d H:i')) fail('Видео пока недоступно', 403);

$gateLessonIds = [$lessonId];
if (!empty($lesson['parent_lesson_id'])) array_unshift($gateLessonIds, (int)$lesson['parent_lesson_id']);
$gatePh = implode(',', array_fill(0, count($gateLessonIds), '?'));
$gate = db()->prepare(
  "SELECT 1 FROM lesson_prereqs p
   JOIN lessons r ON r.id=p.required_lesson_id
   LEFT JOIN lesson_progress lp ON lp.lesson_id=p.required_lesson_id AND lp.user_id=?
   WHERE p.lesson_id IN ($gatePh) AND (
     lp.id IS NULL OR EXISTS (
       SELECT 1 FROM homeworks hp
       WHERE hp.lesson_id=r.id AND (hp.level='normal' OR hp.level IS NULL OR hp.level='')
         AND NOT EXISTS (SELECT 1 FROM submissions sp WHERE sp.homework_id=hp.id AND sp.user_id=?)
     )
   ) LIMIT 1"
);
$gate->execute(array_merge([$uid], $gateLessonIds, [$uid]));
if ($gate->fetchColumn()) fail('Сначала пройди предыдущие уроки', 403);

$url = trim((string)($lesson['video_url'] ?? ''));
$embed = ($lesson['video_source'] ?? '') === 'link' ? external_stream_embed($url) : null;
if (($embed['provider'] ?? '') !== 'educontent') fail('Видео недоступно', 404);

$parts = parse_url((string)$embed['embed_url']);
$path = (string)($parts['path'] ?? '');
parse_str((string)($parts['query'] ?? ''), $query);
if (!preg_match('~^/watch/([0-9a-f-]{36})$~i', $path, $match)
    || !isset($query['partner']) || !is_scalar($query['partner'])
    || !preg_match('/^\d+$/', (string)$query['partner'])) {
  fail('Ссылка EduContent неполная', 422);
}

$videoId = strtolower($match[1]);
$partner = (string)$query['partner'];
$remote = 'https://educontent.cloud/api/stream/' . rawurlencode($videoId) . '/' . rawurlencode($file)
  . '?partner=' . rawurlencode($partner);

$ch = curl_init($remote);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_CONNECTTIMEOUT => 12,
  CURLOPT_TIMEOUT => 90,
  CURLOPT_HTTPHEADER => ['Accept: */*'],
  CURLOPT_USERAGENT => '100points-video-gateway/1.0',
]);
$body = curl_exec($ch);
$error = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($body === false || $status < 200 || $status >= 300) {
  error_log('[educontent stream] HTTP ' . $status . ' ' . $error . ' lesson=' . $lessonId . ' file=' . $file);
  fail('Видеопоток временно недоступен', 502);
}

if ($file === 'playlist.m3u8') {
  $proxyUrl = static function (string $remoteFile) use ($lessonId): string {
    $path = (string)(parse_url($remoteFile, PHP_URL_PATH) ?? '');
    $name = basename($path !== '' ? $path : $remoteFile);
    if (!preg_match('/^(?:video\.key|playlist\d+\.ts)$/', $name)) return $remoteFile;
    return '/api/educontent_stream.php?lesson_id=' . $lessonId . '&file=' . rawurlencode($name);
  };
  $body = preg_replace_callback('/URI="([^"]+)"/', static fn($m) => 'URI="' . $proxyUrl($m[1]) . '"', (string)$body);
  $lines = preg_split('/\r?\n/', (string)$body);
  foreach ($lines as &$line) {
    $trimmed = trim($line);
    if ($trimmed !== '' && !str_starts_with($trimmed, '#')) $line = $proxyUrl($trimmed);
  }
  unset($line);
  $body = implode("\n", $lines);
  header('Content-Type: application/vnd.apple.mpegurl');
  header('Cache-Control: private, max-age=30');
} elseif ($file === 'video.key') {
  header('Content-Type: application/octet-stream');
  header('Cache-Control: private, max-age=3600');
} else {
  header('Content-Type: video/mp2t');
  header('Cache-Control: private, max-age=86400');
}
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen((string)$body));
echo $body;
