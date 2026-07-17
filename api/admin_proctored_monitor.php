<?php
// GET /admin_proctored_monitor.php?assignment_id=
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
require_admin();
$id = (int)($_GET['assignment_id'] ?? 0);
$a = p_assignment($id);
if (!$a || $a['format'] !== 'proctored') fail('Сессия не найдена', 404);
$e = db()->prepare("SELECT id, event_type, detail, created_at FROM proctored_mock_events WHERE assignment_id = ? ORDER BY id DESC LIMIT 50");
$e->execute([$id]);
ok([
  'assignment' => p_public_assignment($a), 'questions' => p_questions((int)$a['mock_id']),
  'presence' => p_presence($id), 'messages' => p_messages($id), 'events' => $e->fetchAll(),
]);
