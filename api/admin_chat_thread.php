<?php
// GET /admin_chat_thread.php?chat_id=<id> — переписка чата (для куратора). Помечает сообщения ученика прочитанными.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/chat_util.php';
require_admin();

$cid = (int)($_GET['chat_id'] ?? 0);
if ($cid <= 0) fail('Не указан чат', 400);

$c = db()->prepare("SELECT ch.id, u.name AS user_name, s.name AS subject FROM chats ch JOIN users u ON u.id = ch.user_id JOIN subjects s ON s.id = ch.subject_id WHERE ch.id = ? LIMIT 1");
$c->execute([$cid]);
$chat = $c->fetch();
if (!$chat) fail('Чат не найден', 404);

db()->prepare("UPDATE chat_messages SET is_read = 1 WHERE chat_id = ? AND from_role = 'student'")->execute([$cid]);

$m = db()->prepare("SELECT from_role, text, attachment, created_at FROM chat_messages WHERE chat_id = ? ORDER BY id");
$m->execute([$cid]);
$messages = [];
foreach ($m as $r) $messages[] = ['from' => $r['from_role'], 'text' => $r['text'], 'attachment' => $r['attachment'] ?? null, 'time' => msg_time($r['created_at'])];

ok(['chat' => ['id' => $cid, 'user_name' => $chat['user_name'], 'subject' => $chat['subject']], 'messages' => $messages]);
