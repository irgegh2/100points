<?php
// POST /admin_notify.php  { target: 'all' | <user_id>, text, link? }
// Ручная рассылка уведомлений (Этап 14, п.5): всем ученикам или одному.
require __DIR__ . '/bootstrap.php';
require_admin();

$in = body();
$target = (string)($in['target'] ?? 'all');
$text = trim((string)($in['text'] ?? ''));
$link = trim((string)($in['link'] ?? '')) ?: null;
if ($text === '') fail('Пустой текст уведомления', 400);

if ($target === 'all') {
  $ids = db()->query("SELECT id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($ids as $uid) notify((int)$uid, 'admin', $text, $link);
  ok(['sent' => count($ids)]);
}

$uid = (int)$target;
if ($uid <= 0) fail('Не указан ученик', 400);
$ex = db()->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
$ex->execute([$uid]);
if (!$ex->fetch()) fail('Ученик не найден', 404);
notify($uid, 'admin', $text, $link);
ok(['sent' => 1]);
