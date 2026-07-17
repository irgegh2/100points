<?php
// GET /ai_chat_get.php?id=<id> — сообщения чата (без системного промпта).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указан чат', 400);

$c = db()->prepare("SELECT id, user_id, title, kind FROM ai_chats WHERE id = ? LIMIT 1");
$c->execute([$id]);
$chat = $c->fetch();
if (!$chat || (int)$chat['user_id'] !== $uid) fail('Чат не найден', 404);

$m = db()->prepare("SELECT role, content FROM ai_messages WHERE chat_id = ? AND role <> 'system' ORDER BY id");
$m->execute([$id]);
$messages = [];
foreach ($m as $r) $messages[] = ['role' => $r['role'], 'content' => $r['content']];

ok(['chat' => ['id' => (int)$chat['id'], 'title' => $chat['title'], 'kind' => $chat['kind']], 'messages' => $messages]);
