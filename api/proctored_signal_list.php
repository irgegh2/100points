<?php
// GET /proctored_signal_list.php?assignment_id=&after_id=
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$id = (int)($_GET['assignment_id'] ?? 0);
$after = (int)($_GET['after_id'] ?? 0);
if (!empty($_SESSION['admin_id'])) {
  require_admin(); $recipient = 'admin'; $a = p_assignment($id);
} else {
  $uid = require_user(); $recipient = 'student'; $a = p_assignment_for_user($uid, $id);
}
if (!$a || $a['format'] !== 'proctored') fail('Сессия недоступна', 403);
$s = db()->prepare("SELECT id, signal_type AS type, payload FROM proctored_mock_signals WHERE assignment_id = ? AND recipient = ? AND id > ? ORDER BY id LIMIT 100");
$s->execute([$id, $recipient, $after]);
$out = [];
foreach ($s as $r) $out[] = ['id' => (int)$r['id'], 'type' => $r['type'], 'payload' => json_decode($r['payload'], true)];
ok(['signals' => $out]);
