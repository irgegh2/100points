<?php
// POST /ai_chat_rename.php  { chat_id, title }
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$id = (int)($in['chat_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
if ($id <= 0) fail('Не указан чат', 400);
if ($title === '') fail('Пустое название', 400);

$c = db()->prepare("SELECT user_id FROM ai_chats WHERE id = ? LIMIT 1");
$c->execute([$id]);
$row = $c->fetch();
if (!$row || (int)$row['user_id'] !== $uid) fail('Чат не найден', 404);

db()->prepare("UPDATE ai_chats SET title = ? WHERE id = ?")->execute([mb_substr($title, 0, 200), $id]);
ok(['title' => $title]);
