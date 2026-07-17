<?php
// POST /ai_chat_delete.php  { chat_id }
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$id = (int)($in['chat_id'] ?? 0);
if ($id <= 0) fail('Не указан чат', 400);

$c = db()->prepare("SELECT user_id FROM ai_chats WHERE id = ? LIMIT 1");
$c->execute([$id]);
$row = $c->fetch();
if (!$row || (int)$row['user_id'] !== $uid) fail('Чат не найден', 404);

db()->prepare("DELETE FROM ai_messages WHERE chat_id = ?")->execute([$id]);
db()->prepare("DELETE FROM ai_chats WHERE id = ?")->execute([$id]);
ok(['deleted' => $id]);
