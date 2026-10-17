<?php
// GET /admin_student_analytics.php?user_id=…&days=90&course_id=…
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();
require __DIR__ . '/student_analytics.php';

$uid = (int)($_GET['user_id'] ?? 0);
if ($uid <= 0) fail('Не указан ученик', 400);
$daysRaw = (string)($_GET['days'] ?? '90');
$days = $daysRaw === 'all' ? 0 : (int)$daysRaw;
$courseId = max(0, (int)($_GET['course_id'] ?? 0));

try {
  ok(student_analytics(db(), $uid, ['days' => $days, 'course_id' => $courseId]));
} catch (RuntimeException $e) {
  fail($e->getMessage(), $e->getMessage() === 'Ученик не найден' ? 404 : 400);
}
