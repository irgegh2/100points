<?php
// GET /chat_list.php — список чатов наставника у ученика.
// Чаты только по предметам, где есть доступный курс И стоит галочка «показывать в разделах»
// (user_accessible_subjects уже фильтрует show_features).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/chat_util.php'; // msg_time()
$uid = require_user();
release_session();

$subjects = user_accessible_subjects($uid);
$out = [];
foreach ($subjects as $s) {
  $sid = (int)$s['id'];
  // существующий чат (если есть) — последнее сообщение + непрочитанные от наставника.
  $c = db()->prepare("SELECT id FROM chats WHERE user_id = ? AND subject_id = ? LIMIT 1");
  $c->execute([$uid, $sid]);
  $chatId = (int)($c->fetchColumn() ?: 0);
  $last = ''; $time = ''; $unread = 0;
  if ($chatId) {
    $m = db()->prepare("SELECT text, attachment, created_at FROM chat_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
    $m->execute([$chatId]);
    if ($lm = $m->fetch()) { $last = ($lm['text'] !== null && $lm['text'] !== '') ? $lm['text'] : (!empty($lm['attachment']) ? '📎 Вложение' : ''); $time = msg_time($lm['created_at']); }
    $u = db()->prepare("SELECT COUNT(*) FROM chat_messages WHERE chat_id = ? AND from_role = 'mentor' AND is_read = 0");
    $u->execute([$chatId]);
    $unread = (int)$u->fetchColumn();
  }
  $out[] = [
    'subject_id' => $sid, 'subject' => $s['name'], 'color' => $s['color'],
    'last' => $last, 'time' => $time, 'unread' => $unread,
  ];
}
ok(['chats' => $out]);
