<?php
// Идемпотентно добавляет watchdog видеовочереди в crontab пользователя хостинга.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';

$secret = (string)(prod_secrets()['migrate_key'] ?? '');
$provided = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (strlen($secret) < 16 || !hash_equals($secret, $provided)) fail('Недоступно', 404);
release_session();
if (!function_exists('shell_exec')) fail('На сервере недоступен установщик cron', 500);

function video_cron_run(array $command): array {
  $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process)) return [1, '', 'Не удалось запустить команду'];
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  return [proc_close($process), (string)$stdout, (string)$stderr];
}

$crontab = trim((string)@shell_exec('command -v crontab 2>/dev/null'));
if ($crontab === '' || !is_executable($crontab)) fail('На сервере не найден crontab', 500);

$php = PHP_BINARY;
foreach (['/opt/php/8.3/bin/php', '/opt/php/8.2/bin/php', PHP_BINARY] as $candidate) {
  if (is_file($candidate) && is_executable($candidate)) { $php = $candidate; break; }
}
$marker = '# 100points-video-worker';
$command = '* * * * * ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/video_worker.php')
  . ' --once >> ' . escapeshellarg(video_account_root() . '/video-worker.log') . ' 2>&1 ' . $marker;

$read = video_cron_run([$crontab, '-l']);
$existing = $read[0] === 0 ? $read[1] : '';
$lines = preg_split('/\R/', trim($existing)) ?: [];
$lines = array_values(array_filter($lines, fn($line) => !str_contains((string)$line, $marker)));
$lines[] = $command;
$contents = implode("\n", $lines) . "\n";
$temp = tempnam(sys_get_temp_dir(), '100points-cron-');
if ($temp === false || file_put_contents($temp, $contents) === false) fail('Не удалось подготовить cron', 500);
$install = video_cron_run([$crontab, $temp]);
$code = (int)$install[0];
@unlink($temp);
if ($code !== 0) fail('Cron не установлен: ' . mb_substr(trim($install[2]), 0, 500), 500);

$verifyRun = video_cron_run([$crontab, '-l']);
$verify = $verifyRun[0] === 0 ? $verifyRun[1] : '';
if (!str_contains($verify, $marker)) fail('Cron не появился после установки', 500);
video_worker_kick();
ok(['installed' => true, 'schedule' => 'каждую минуту']);
