<?php
// Короткая диагностика среды обработки. Никакие пути, ключи и настройки
// сервера наружу не отдаём — админке нужен только итоговый статус.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
$allowed = !empty($_SESSION['admin_id']);
if (!$allowed) {
  $key = (string)(prod_secrets()['migrate_key'] ?? '');
  $valid = strlen($key) >= 16 && $key !== 'change-me-to-a-long-random-string';
  $allowed = $valid && hash_equals($key, (string)($_GET['key'] ?? ''));
}
if (!$allowed) fail('Нужен вход в админку', 401);
release_session();

function video_runtime_binary(string $name): bool {
  return video_binary_path($name) !== $name;
}

$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$proc = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
$ffmpeg = video_runtime_binary('ffmpeg');
$ffprobe = video_runtime_binary('ffprobe');
$ready = $proc && $ffmpeg && $ffprobe;
$cron = function_exists('shell_exec') ? (string)@shell_exec('crontab -l 2>/dev/null') : '';
$watchdog = str_contains($cron, '# 100points-video-worker');
$heartbeatFile = video_account_root() . '/video-worker-heartbeat';
$heartbeatAge = is_file($heartbeatFile) ? max(0, time() - (int)filemtime($heartbeatFile)) : null;
$queue = [];
try {
  $rows = db()->query('SELECT status, COUNT(*) AS total FROM videos GROUP BY status')->fetchAll();
  foreach ($rows as $row) $queue[(string)$row['status']] = (int)$row['total'];
} catch (Throwable $ignored) {}
if ($ready && (!$watchdog || (int)($queue['queued'] ?? 0) > 0)) video_worker_kick();
ok([
  'ready' => $ready,
  'proc_open' => $proc,
  'ffmpeg' => $ffmpeg,
  'ffprobe' => $ffprobe,
  'arch' => php_uname('m'),
  'watchdog' => $watchdog,
  'heartbeat_age_sec' => $heartbeatAge,
  'queue' => $queue,
]);
