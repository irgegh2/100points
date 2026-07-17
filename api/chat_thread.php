<?php
// GET /chat_thread.php?subject_id=<id> — сообщения чата ученика по предмету (создаёт чат при первом входе).
// Помечает сообщения наставника прочитанными.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/chat_util.php';
$uid = require_user();

$sid = (int)($_GET['subject_id'] ?? 0);
if ($sid <= 0) fail('Не указан предмет', 400);
$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($sid, $allowed, true)) fail('Нет доступа к предмету', 403);

$chatId = get_or_create_chat($uid, $sid);
db()->prepare("UPDATE chat_messages SET is_read = 1 WHERE chat_id = ? AND from_role = 'mentor'")->execute([$chatId]);
release_session();

$m = db()->prepare("SELECT from_role, text, attachment, created_at FROM chat_messages WHERE chat_id = ? ORDER BY id");
$m->execute([$chatId]);
$messages = [];
foreach ($m as $r) $messages[] = [
  'from' => $r['from_role'] === 'student' ? 'me' : 'mentor',
  'text' => $r['text'], 'attachment' => $r['attachment'] ?? null, 'time' => msg_time($r['created_at']),
];
ok(['chat_id' => $chatId, 'messages' => $messages]);
