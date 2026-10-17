<?php
// Одноцелевой установщик серверного видеодвижка из приватного S3.
// Не принимает ни URL, ни пути от клиента и защищён ключом миграций.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';

$secret = (string)(prod_secrets()['migrate_key'] ?? '');
$provided = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (strlen($secret) < 16 || !hash_equals($secret, $provided)) fail('Недоступно', 404);
release_session();

$root = video_account_root();
$binDir = $root . '/bin';
if (!is_dir($binDir) && !mkdir($binDir, 0700, true)) fail('Не удалось создать каталог видеодвижка', 500);

$installed = [];
foreach (['ffmpeg', 'ffprobe'] as $name) {
  $stagingKey = 'system/video-runtime/linux-x86_64/' . $name;
  $target = $binDir . '/' . $name;
  $temp = $target . '.download';
  $fp = fopen($temp, 'wb');
  if (!$fp) fail('Не удалось подготовить ' . $name, 500);
  $ch = curl_init(storage_presigned_get($stagingKey, 3600));
  curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 0,
  ]);
  $success = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  fclose($fp);
  if (!$success || $status < 200 || $status >= 300 || filesize($temp) < 1024 * 1024) {
    @unlink($temp);
    fail('Не удалось получить ' . $name . ': HTTP ' . $status . ' ' . $error, 500);
  }
  chmod($temp, 0755);
  if (!rename($temp, $target)) fail('Не удалось установить ' . $name, 500);
  chmod($target, 0755);
  $version = function_exists('shell_exec')
    ? (string)@shell_exec(escapeshellarg($target) . ' -version 2>&1')
    : '';
  if (!str_contains(strtolower($version), $name . ' version')) {
    fail($name . ' установлен, но не запускается на этом сервере', 500);
  }
  $installed[$name] = basename(strtok($version, "\r\n") ?: $name);
  try { storage_delete_key($stagingKey); } catch (Throwable $ignored) {}
}

video_worker_kick();
ok(['ready' => video_runtime_ready(), 'installed' => $installed]);
