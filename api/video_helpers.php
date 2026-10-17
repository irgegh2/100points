<?php

function video_account_root(): string {
  // Production: /var/www/<user>/data/www/100-points.ru/api -> /var/www/<user>/data
  return dirname(__DIR__, 3);
}

function video_binary_path(string $name): string {
  $private = video_account_root() . '/bin/' . basename($name);
  if (is_file($private) && is_executable($private)) return $private;

  foreach (['/usr/bin/', '/usr/local/bin/', '/opt/bin/', '/opt/homebrew/bin/'] as $prefix) {
    $path = $prefix . basename($name);
    if (is_file($path) && is_executable($path)) return $path;
  }
  return basename($name);
}

function video_runtime_ready(): bool {
  if (!function_exists('proc_open')) return false;
  foreach (['ffmpeg', 'ffprobe'] as $name) {
    $path = video_binary_path($name);
    if ($path === $name) return false;
  }
  return true;
}

function video_worker_kick(): bool {
  if (!video_runtime_ready() || !function_exists('shell_exec')) return false;
  $php = PHP_BINARY;
  foreach (['/opt/php/8.3/bin/php', '/opt/php/8.2/bin/php', PHP_BINARY] as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) { $php = $candidate; break; }
  }
  $script = __DIR__ . '/video_worker.php';
  $log = video_account_root() . '/video-worker.log';
  $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
    . ' --once >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
  @shell_exec($command);
  return true;
}

function video_row(int $id): ?array {
  if ($id <= 0) return null;
  $st = db()->prepare(
    "SELECT v.*, l.title AS lesson_title, b.id AS block_id, b.title AS block_title,
            m.id AS month_id, m.name AS month_name, c.id AS course_id, c.title AS course_title
     FROM videos v
     LEFT JOIN lessons l ON l.id = v.lesson_id
     LEFT JOIN blocks b ON b.id = l.block_id
     LEFT JOIN months m ON m.id = b.month_id
     LEFT JOIN courses c ON c.id = b.course_id
     WHERE v.id = ? LIMIT 1"
  );
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

function video_require_admin_row(int $id): array {
  $row = video_row($id);
  if (!$row) fail('Видео не найдено', 404);
  return $row;
}

function video_require_user_row(int $uid, int $id): array {
  $row = video_row($id);
  if (!$row || ($row['status'] ?? '') !== 'ready' || empty($row['lesson_id']) || empty($row['course_id'])) {
    fail('Видео не найдено', 404);
  }
  if (!user_has_course_access($uid, (int)$row['course_id'])) fail('Нет доступа к видео', 403);
  $active = db()->prepare("SELECT 1 FROM lessons WHERE id = ? AND video_source = 'native' AND video_id = ? LIMIT 1");
  $active->execute([(int)$row['lesson_id'], $id]);
  if (!$active->fetchColumn()) fail('Видео не привязано к этому уроку', 403);
  return $row;
}

function video_public_admin(array $row): array {
  $qualities = json_decode((string)($row['qualities'] ?? ''), true);
  return [
    'id' => (int)$row['id'],
    'lesson_id' => $row['lesson_id'] !== null ? (int)$row['lesson_id'] : null,
    'lesson_title' => $row['lesson_title'] ?? null,
    'block_id' => $row['block_id'] !== null ? (int)$row['block_id'] : null,
    'block_title' => $row['block_title'] ?? null,
    'month_id' => $row['month_id'] !== null ? (int)$row['month_id'] : null,
    'month_name' => $row['month_name'] ?? null,
    'period_kind' => ($row['period_kind'] ?? '') === 'block' ? 'block' : 'month',
    'course_id' => $row['course_id'] !== null ? (int)$row['course_id'] : null,
    'course_title' => $row['course_title'] ?? null,
    'title' => (string)$row['title'],
    'original_name' => (string)($row['original_name'] ?? ''),
    'status' => (string)$row['status'],
    'process_percent' => max(0, min(100, (int)($row['process_percent'] ?? 0))),
    'process_stage' => (string)($row['process_stage'] ?? ''),
    'size_bytes' => (int)($row['size_bytes'] ?? 0),
    'duration_sec' => (int)($row['duration_sec'] ?? 0),
    'width' => (int)($row['width'] ?? 0),
    'height' => (int)($row['height'] ?? 0),
    'qualities' => is_array($qualities) ? $qualities : [],
    'error' => (string)($row['error'] ?? ''),
    'poster_url' => !empty($row['poster_key']) ? '/api/admin_video_asset.php?id=' . (int)$row['id'] . '&kind=poster' : null,
    'created_at' => $row['created_at'] ?? null,
    'updated_at' => $row['updated_at'] ?? null,
  ];
}

function video_safe_ext(string $name, string $mime): string {
  $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
  $allowed = ['mp4','mov','m4v','webm','mkv'];
  if (!in_array($ext, $allowed, true)) {
    $byMime = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-matroska' => 'mkv'];
    $ext = $byMime[strtolower($mime)] ?? '';
  }
  if ($ext === '') fail('Поддерживаются MP4, MOV, M4V, WebM и MKV', 400);
  return $ext;
}

function video_hls_mime(string $path): string {
  return match (strtolower((string)pathinfo($path, PATHINFO_EXTENSION))) {
    'm3u8' => 'application/vnd.apple.mpegurl',
    'ts' => 'video/mp2t',
    'm4s' => 'video/iso.segment',
    'mp4' => 'video/mp4',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
  };
}
