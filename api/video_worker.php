<?php
// Лёгкий CLI-воркер: видео остаётся в исходном качестве, FFmpeg создаёт только
// обложку. Запуск: php api/video_worker.php --once
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/storage.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/video_helpers.php';

function vp_run(array $command): void {
  $spec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process)) throw new RuntimeException('Не удалось запустить FFmpeg');
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  $code = proc_close($process);
  if ($code !== 0) throw new RuntimeException('FFmpeg: ' . mb_substr(trim($stderr ?: $stdout), -1200));
}

function vp_probe(string $source): array {
  $command = escapeshellarg(video_binary_path('ffprobe'))
    . ' -v error -show_streams -show_format -of json ' . escapeshellarg($source);
  $data = json_decode((string)shell_exec($command), true);
  if (!$data) throw new RuntimeException('FFprobe не прочитал видео');
  $video = null;
  foreach ($data['streams'] ?? [] as $stream) {
    if (($stream['codec_type'] ?? '') === 'video' && empty($stream['disposition']['attached_pic'])) { $video = $stream; break; }
  }
  if (!$video) throw new RuntimeException('В файле нет видеодорожки');
  return [
    'duration' => (float)($data['format']['duration'] ?? $video['duration'] ?? 0),
    'width' => (int)($video['width'] ?? 0),
    'height' => (int)($video['height'] ?? 0),
  ];
}

function video_row_for_poster(int $id): array {
  $stmt = db()->prepare('SELECT * FROM videos WHERE id=?');
  $stmt->execute([$id]);
  return $stmt->fetch() ?: [];
}

function vp_process(array $row): bool {
  $id = (int)$row['id'];
  $claim = db()->prepare("UPDATE videos SET process_stage='Создаём обложку',updated_at=?
                          WHERE id=? AND status='ready' AND process_stage='Обложка в очереди'");
  $claim->execute([gmdate('Y-m-d H:i:s'), $id]);
  if ($claim->rowCount() < 1) return false;

  $tmp = sys_get_temp_dir() . '/100points-poster-' . $id . '-' . bin2hex(random_bytes(4)) . '.jpg';
  try {
    // S3 поддерживает Range: не скачиваем многогигабайтный исходник целиком
    // только ради одного кадра.
    $source = storage_presigned_get((string)$row['source_key'], 3600);
    $meta = vp_probe($source);
    $at = min(max(2.0, $meta['duration'] * .12), 30.0);
    vp_run([
      video_binary_path('ffmpeg'), '-y', '-ss', (string)$at, '-i', $source,
      '-frames:v', '1', '-vf', 'scale=min(1600\,iw):-2',
      '-c:v', 'mjpeg', '-q:v', '2', '-threads:v', '1', $tmp,
    ]);
    if (!is_file($tmp) || filesize($tmp) < 1) throw new RuntimeException('FFmpeg не создал обложку');

    $current = video_row_for_poster($id);
    $posterKey = (string)($current['poster_key'] ?? '');
    if ($posterKey === '' || !str_contains($posterKey, 'poster-custom.')) {
      $posterKey = 'videos/v' . $id . '/poster.jpg';
      storage_put_file($tmp, $posterKey, 'image/jpeg');
    }
    db()->prepare("UPDATE videos SET duration_sec=?,width=?,height=?,poster_key=?,process_stage='Готово · оригинал',error=NULL,updated_at=? WHERE id=?")
        ->execute([(int)round($meta['duration']), $meta['width'], $meta['height'], $posterKey, gmdate('Y-m-d H:i:s'), $id]);
    live_content_touch();
    return true;
  } finally {
    if (is_file($tmp)) @unlink($tmp);
  }
}

$lock = fopen(sys_get_temp_dir() . '/100points-video-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Воркер уже запущен\n"); exit(1); }
@file_put_contents(video_account_root() . '/video-worker-heartbeat', gmdate('Y-m-d H:i:s'));

$staleBefore = gmdate('Y-m-d H:i:s', time() - 20 * 60);
db()->prepare("UPDATE videos SET process_stage='Обложка в очереди',updated_at=?
               WHERE status='ready' AND process_stage='Создаём обложку' AND updated_at<?")
    ->execute([gmdate('Y-m-d H:i:s'), $staleBefore]);

$once = in_array('--once', $argv ?? [], true);
do {
  $row = db()->query("SELECT * FROM videos WHERE status='ready' AND process_stage='Обложка в очереди' ORDER BY id LIMIT 1")->fetch();
  if (!$row) { if ($once) break; sleep(5); continue; }
  try {
    if (vp_process($row)) echo 'video #' . (int)$row['id'] . " poster ready\n";
  } catch (Throwable $e) {
    db()->prepare("UPDATE videos SET process_stage='Видео готово · ошибка обложки',error=?,updated_at=? WHERE id=?")
        ->execute([mb_substr($e->getMessage(), 0, 4000), gmdate('Y-m-d H:i:s'), (int)$row['id']]);
    fwrite(STDERR, 'video #' . (int)$row['id'] . ': ' . $e->getMessage() . "\n");
  }
} while (!$once);
