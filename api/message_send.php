<?php
// POST /message_send.php  { subject_id, text } — ученик пишет наставнику.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/chat_util.php';
$uid = require_user();

$in = body();
$sid = (int)($in['subject_id'] ?? 0);
$text = trim((string)($in['text'] ?? ''));
// Вложение — относительный путь, выданный chat_upload.php. Чужие пути не принимаем.
$attachment = trim((string)($in['attachment'] ?? ''));
if ($attachment !== '' && strpos($attachment, '/uploads/chat/') !== 0) $attachment = '';
if ($sid <= 0) fail('Не указан предмет', 400);
if ($text === '' && $attachment === '') fail('Пустое сообщение', 400);
$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($sid, $allowed, true)) fail('Нет доступа к предмету', 403);

$chatId = get_or_create_chat($uid, $sid);
db()->prepare("INSERT INTO chat_messages (chat_id, from_role, text, attachment, is_read) VALUES (?, 'student', ?, ?, 0)")
    ->execute([$chatId, $text, $attachment !== '' ? $attachment : null]);
db()->prepare("UPDATE chats SET last_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $chatId]);

ok(['sent' => true, 'time' => date('H:i')]);
