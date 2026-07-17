<?php
// POST /admin_mock_unassign.php { assignment_id }
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
require_admin();
$id = (int)(body()['assignment_id'] ?? 0);
$a = p_assignment($id);
if (!$a) fail('Назначение не найдено', 404);
foreach (['proctored_mock_signals','proctored_mock_events','proctored_mock_messages','proctored_mock_presence'] as $table) {
  db()->prepare("DELETE FROM $table WHERE assignment_id = ?")->execute([$id]);
}
db()->prepare("DELETE FROM mock_assignments WHERE id = ?")->execute([$id]);
ok(['deleted' => $id]);
