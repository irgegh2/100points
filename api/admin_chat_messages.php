<?php
// GET /admin_chat_messages.php?chat_id=<id> — сообщения любого чата (для админа, чтение переписки ученика).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$id = (int)($_GET['chat_id'] ?? 0);
if ($id <= 0) fail('Не указан чат', 400);

$c = db()->prepare("SELECT a.id, a.title, a.kind, u.name AS user_name FROM ai_chats a JOIN users u ON u.id = a.user_id WHERE a.id = ? LIMIT 1");
$c->execute([$id]);
$chat = $c->fetch();
if (!$chat) fail('Чат не найден', 404);

$m = db()->prepare("SELECT role, content FROM ai_messages WHERE chat_id = ? AND role <> 'system' ORDER BY id");
$m->execute([$id]);
$messages = [];
foreach ($m as $r) $messages[] = ['role' => $r['role'], 'content' => $r['content']];

ok([
  'chat' => ['id' => (int)$chat['id'], 'title' => $chat['title'], 'kind' => $chat['kind'], 'user_name' => $chat['user_name']],
  'messages' => $messages,
]);
