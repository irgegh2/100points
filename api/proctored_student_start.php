<?php
// POST /proctored_student_start.php { assignment_id } — вызывается только после разрешения камеры.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();
$id = (int)(body()['assignment_id'] ?? 0);
$a = p_assignment_for_user($uid, $id);
if (!$a || $a['format'] !== 'proctored') fail('Сессия не назначена', 403);
$state = p_time_state($a);
if (!in_array($state, ['ready','live'], true)) {
  if ($state === 'scheduled') fail('Подключение откроется за 15 минут до начала', 409);
  if ($state === 'ended' || $state === 'finished') fail('Время сессии закончилось', 409);
  fail('Для сессии не указано время', 409);
}
$now = date('Y-m-d H:i:s');
db()->prepare("UPDATE mock_assignments SET camera_consent_at = COALESCE(camera_consent_at, ?), started_at = COALESCE(started_at, ?), status = 'live', updated_at = ? WHERE id = ?")
    ->execute([$now, $now, $now, $id]);
$p = db()->prepare("SELECT id FROM proctored_mock_presence WHERE assignment_id = ?");
$p->execute([$id]);
if (!$p->fetchColumn()) db()->prepare("INSERT INTO proctored_mock_presence (assignment_id, camera_state, peer_state, last_seen) VALUES (?, 'on', 'connecting', ?)")->execute([$id, $now]);
else db()->prepare("UPDATE proctored_mock_presence SET camera_state = 'on', peer_state = 'connecting', last_seen = ? WHERE assignment_id = ?")->execute([$now, $id]);
p_event($id, 'session_started', 'Камера разрешена, ученик подключился');
$a = p_assignment($id);
ok(['assignment' => p_public_assignment($a)]);
