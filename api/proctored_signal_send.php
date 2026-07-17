<?php
// POST /proctored_signal_send.php { assignment_id, type, payload }
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$in = body();
$id = (int)($in['assignment_id'] ?? 0);
$type = (string)($in['type'] ?? '');
$payload = $in['payload'] ?? null;
if (!in_array($type, ['offer','answer','ice'], true) || !is_array($payload)) fail('Неверный сигнал', 400);
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($payloadJson === false || strlen($payloadJson) > 100000) fail('Сигнал слишком большой', 413);
if (!empty($_SESSION['admin_id'])) {
  require_admin(); $sender = 'admin'; $recipient = 'student'; $a = p_assignment($id);
} else {
  $uid = require_user(); $sender = 'student'; $recipient = 'admin'; $a = p_assignment_for_user($uid, $id);
}
if (!$a || $a['format'] !== 'proctored') fail('Сессия недоступна', 403);
db()->prepare("INSERT INTO proctored_mock_signals (assignment_id, sender, recipient, signal_type, payload) VALUES (?,?,?,?,?)")
    ->execute([$id, $sender, $recipient, $type, $payloadJson]);
ok(['id' => (int)db()->lastInsertId()]);
