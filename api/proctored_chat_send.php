<?php
// POST /proctored_chat_send.php { assignment_id, content }
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$in = body();
$id = (int)($in['assignment_id'] ?? 0);
$content = trim((string)($in['content'] ?? ''));
if ($content === '') fail('Пустое сообщение', 400);
if (mb_strlen($content) > 1000) fail('Сообщение слишком длинное', 400);
if (!empty($_SESSION['admin_id'])) {
  require_admin(); $sender = 'admin'; $a = p_assignment($id);
} else {
  $uid = require_user(); $sender = 'student'; $a = p_assignment_for_user($uid, $id);
}
if (!$a || $a['format'] !== 'proctored') fail('Сессия недоступна', 403);
db()->prepare("INSERT INTO proctored_mock_messages (assignment_id, sender, content) VALUES (?,?,?)")
    ->execute([$id, $sender, $content]);
$messages = p_messages($id);
ok(['message' => end($messages)]);
