<?php
// GET /admin_chats.php?unread=1 — чаты наставника для админа (куратора): ученик, предмет, последнее, непрочитанные.
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$onlyUnread = !empty($_GET['unread']);
$rows = db()->query(
  "SELECT ch.id, ch.user_id, ch.last_at, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
          s.name AS subject, s.color AS color
   FROM chats ch
   JOIN users u ON u.id = ch.user_id
   JOIN subjects s ON s.id = ch.subject_id
   ORDER BY ch.last_at DESC, ch.id DESC"
)->fetchAll();

$out = []; $pending = 0;
foreach ($rows as $r) {
  $cid = (int)$r['id'];
  $m = db()->prepare("SELECT text, attachment, from_role, created_at FROM chat_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
  $m->execute([$cid]);
  $lm = $m->fetch();
  $lmText = ($lm && $lm['text'] !== null && $lm['text'] !== '') ? $lm['text'] : (($lm && !empty($lm['attachment'])) ? '📎 Вложение' : '');
  $u = db()->prepare("SELECT COUNT(*) FROM chat_messages WHERE chat_id = ? AND from_role = 'student' AND is_read = 0");
  $u->execute([$cid]);
  $unread = (int)$u->fetchColumn();
  if ($unread > 0) $pending++;
  if ($onlyUnread && $unread === 0) continue;
  $out[] = [
    'id' => $cid, 'user_id' => (int)$r['user_id'], 'user_name' => $r['user_name'],
    'user_email' => $r['user_email'], 'user_phone' => $r['user_phone'],
    'subject' => $r['subject'], 'color' => $r['color'],
    'last' => $lmText, 'last_from' => $lm['from_role'] ?? '',
    'time' => substr($lm['created_at'] ?? '', 0, 16), 'unread' => $unread,
  ];
}
ok(['chats' => $out, 'pending' => $pending]);
