<?php
// GET /achievements_list.php — каталог достижений с прогрессом ученика.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();
require_once __DIR__ . '/gamify.php';
g_sync_achievements($uid);

$rows = db()->query("SELECT id, code, title, description, icon, reward, target, position FROM achievements ORDER BY position, id")->fetchAll();
$up = db()->prepare("SELECT achievement_id, progress, unlocked_at FROM user_achievements WHERE user_id = ?");
$up->execute([$uid]);
$mine = [];
foreach ($up as $r) $mine[(int)$r['achievement_id']] = $r;

$out = [];
foreach ($rows as $a) {
  $u = $mine[(int)$a['id']] ?? null;
  $out[] = [
    'code'        => $a['code'],
    'title'       => $a['title'],
    'description' => $a['description'],
    'icon'        => $a['icon'],
    'reward'      => (int)$a['reward'],
    'target'      => (int)$a['target'],
    'progress'    => $u ? (int)$u['progress'] : 0,
    'unlocked'    => $u ? (bool)$u['unlocked_at'] : false,
  ];
}
$unlocked = count(array_filter($out, fn($x) => $x['unlocked']));
ok(['achievements' => $out, 'unlocked' => $unlocked, 'total' => count($out)]);
