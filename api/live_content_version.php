<?php
require __DIR__ . '/bootstrap.php';

require_user();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$appVersion = '';
$versionFile = dirname(__DIR__) . '/version.json';
if (is_file($versionFile)) {
  $payload = json_decode((string)file_get_contents($versionFile), true);
  $appVersion = is_array($payload) ? (string)($payload['version'] ?? '') : '';
}

ok([
  'version' => live_content_version(),
  'app_version' => $appVersion,
  'checked_at' => gmdate('c'),
]);
