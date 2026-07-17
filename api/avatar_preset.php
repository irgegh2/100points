<?php
// POST /avatar_preset.php  { "preset": "av-03" }
// Ставит ученику готовый аватар из бандла /img/avatars/. Только из белого списка —
// поэтому подменить путь нельзя (в отличие от старого профиля). Загруженный ранее
// собственный файл при этом удаляется (чистим мусор).
require __DIR__ . '/bootstrap.php';

$uid = require_user();
$data = body();
$preset = trim($data['preset'] ?? '');

// Белый список = реально лежащие в бандле файлы av-01 … av-20.
$allowed = [];
for ($i = 1; $i <= 20; $i++) $allowed[] = sprintf('av-%02d', $i);
if (!in_array($preset, $allowed, true)) fail('Неизвестный аватар', 400);

$url = '/img/avatars/' . $preset . '.svg?v=3'; // ?v=3 — кэш-бастинг (см. AVATAR_PRESETS в api.js)

// Удаляем прежний ЗАГРУЖЕННЫЙ файл этого ученика (пресеты не трогаем — они общие в бандле).
$old = db()->prepare("SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1");
$old->execute([$uid]);
$oldpath = ($old->fetch()['avatar'] ?? '');
$oldUrlPath = parse_url($oldpath, PHP_URL_PATH) ?: $oldpath;
$ownerPrefix = 'u' . $uid . '_';
if ($oldUrlPath
    && strpos($oldUrlPath, '/uploads/avatars/') === 0
    && strpos(basename($oldUrlPath), $ownerPrefix) === 0) {
  $dir = realpath(uploads_root() . '/avatars');
  $oldfile = $dir ? realpath($dir . '/' . basename($oldUrlPath)) : false;
  if ($oldfile && $dir && strpos($oldfile, $dir) === 0 && is_file($oldfile)) @unlink($oldfile);
}

// upsert avatar
$exists = db()->prepare("SELECT user_id FROM profiles WHERE user_id = ? LIMIT 1");
$exists->execute([$uid]);
if ($exists->fetch()) {
  db()->prepare("UPDATE profiles SET avatar = ? WHERE user_id = ?")->execute([$url, $uid]);
} else {
  db()->prepare("INSERT INTO profiles (user_id, avatar) VALUES (?,?)")->execute([$uid, $url]);
}

ok(['avatar' => $url]);
