<?php
// POST /admin_chat_send.php  { chat_id, text } — куратор (админ) отвечает ученику.
require __DIR__ . '/bootstrap.php';
require_admin();

$in = body();
$cid = (int)($in['chat_id'] ?? 0);
$text = trim((string)($in['text'] ?? ''));
if ($cid <= 0) fail('Не указан чат', 400);
if ($text === '') fail('Пустое сообщение', 400);

$c = db()->prepare("SELECT ch.user_id, s.name AS subject FROM chats ch JOIN subjects s ON s.id = ch.subject_id WHERE ch.id = ? LIMIT 1");
$c->execute([$cid]);
$chat = $c->fetch();
if (!$chat) fail('Чат не найден', 404);

db()->prepare("INSERT INTO chat_messages (chat_id, from_role, text, is_read) VALUES (?, 'mentor', ?, 0)")->execute([$cid, $text]);
// Ответил — значит прочитал входящие.
db()->prepare("UPDATE chat_messages SET is_read = 1 WHERE chat_id = ? AND from_role = 'student'")->execute([$cid]);
db()->prepare("UPDATE chats SET last_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $cid]);

notify((int)$chat['user_id'], 'mentor', "Куратор ответил в чате по предмету «{$chat['subject']}»", 'mentor');
ok(['sent' => true]);
