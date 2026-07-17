<?php
// GET /admin_mock_assignments.php?mock_id= — назначения выбранного пробника.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
require_admin();

$mockId = (int)($_GET['mock_id'] ?? 0);
if ($mockId <= 0) fail('Не указан пробник', 400);
$m = db()->prepare("SELECT id, title, format, access_mode FROM mock_exams WHERE id = ? LIMIT 1");
$m->execute([$mockId]);
$mock = $m->fetch();
if (!$mock) fail('Пробник не найден', 404);

$s = db()->prepare("SELECT id FROM mock_assignments WHERE mock_id = ? ORDER BY id DESC");
$s->execute([$mockId]);
$assignments = [];
foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
  $row = p_assignment((int)$id);
  if ($row) $assignments[] = p_public_assignment($row);
}
$users = db()->query("SELECT id, name, email, phone FROM users WHERE role = 'student' ORDER BY name, email, id")->fetchAll();
foreach ($users as &$u) $u['id'] = (int)$u['id'];
ok(['mock' => $mock, 'assignments' => $assignments, 'users' => $users]);
