<?php
// POST /admin_mock_assign.php { mock_id, user_id, starts_at?, duration? }
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
require_admin();

$in = body();
$mockId = (int)($in['mock_id'] ?? 0);
$userId = (int)($in['user_id'] ?? 0);
$duration = max(15, min(480, (int)($in['duration'] ?? 180)));
$startsAt = trim(str_replace('T', ' ', (string)($in['starts_at'] ?? '')));
if ($mockId <= 0 || $userId <= 0) fail('Выбери пробник и ученика', 400);

$m = db()->prepare("SELECT id, format, access_mode FROM mock_exams WHERE id = ? LIMIT 1");
$m->execute([$mockId]);
$mock = $m->fetch();
if (!$mock) fail('Пробник не найден', 404);
if ($mock['format'] !== 'proctored' && $mock['access_mode'] !== 'individual') {
  fail('Назначать ученикам можно только индивидуальный пробник', 409);
}
if ($mock['format'] === 'proctored' && (!$startsAt || !strtotime($startsAt))) fail('Для пробника с наставником укажи дату и время', 400);
if ($startsAt !== '' && !strtotime($startsAt)) fail('Неверная дата', 400);
$u = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'student'");
$u->execute([$userId]);
if (!$u->fetchColumn()) fail('Ученик не найден', 404);

$old = db()->prepare("SELECT id, status FROM mock_assignments WHERE mock_id = ? AND user_id = ? LIMIT 1");
$old->execute([$mockId, $userId]);
$now = date('Y-m-d H:i:s');
if ($row = $old->fetch()) {
  $id = (int)$row['id'];
  db()->prepare("UPDATE mock_assignments SET starts_at = ?, duration = ?, status = 'scheduled', camera_consent_at = NULL, started_at = NULL, finished_at = NULL, updated_at = ? WHERE id = ?")
      ->execute([$startsAt ?: null, $duration, $now, $id]);
  db()->prepare("DELETE FROM proctored_mock_signals WHERE assignment_id = ?")->execute([$id]);
  db()->prepare("DELETE FROM proctored_mock_presence WHERE assignment_id = ?")->execute([$id]);
} else {
  db()->prepare("INSERT INTO mock_assignments (mock_id, user_id, starts_at, duration, status, updated_at) VALUES (?,?,?,?, 'scheduled', ?)")
      ->execute([$mockId, $userId, $startsAt ?: null, $duration, $now]);
  $id = (int)db()->lastInsertId();
}
notify($userId, 'event', $mock['format'] === 'proctored' ? 'Тебе назначен пробник с наставником' : 'Тебе назначен индивидуальный пробник', 'mock');
$a = p_assignment($id);
ok(['assignment' => p_public_assignment($a)]);
