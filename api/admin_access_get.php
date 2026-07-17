<?php
// GET /admin_access_get.php?user_id=<id> — доступ ученика для админки:
// текущая подписка, список зачислений на курсы + справочники (тарифы, курсы).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session(); // read-only — снимаем лок сессии сразу

$uid = (int)($_GET['user_id'] ?? 0);
if ($uid <= 0) fail('Не указан ученик', 400);

$sub = db()->prepare("SELECT plan_id, status, started_at, ends_at FROM subscriptions
                      WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$sub->execute([$uid]);
$s = $sub->fetch();

$enr = db()->prepare("SELECT course_id, source, expires_at FROM enrollments WHERE user_id = ?");
$enr->execute([$uid]);
$enrollments = [];
foreach ($enr as $e) $enrollments[] = [
  'course_id'  => (int)$e['course_id'],
  'source'     => $e['source'],
  'expires_at' => $e['expires_at'],
];

$plans = db()->query("SELECT id, name FROM plans ORDER BY position, id")->fetchAll();
$courses = db()->query("SELECT id, title FROM courses ORDER BY position, id")->fetchAll();

ok([
  'subscription' => $s ? [
    'plan_id'    => $s['plan_id'] !== null ? (int)$s['plan_id'] : null,
    'status'     => $s['status'],
    'started_at' => $s['started_at'],
    'ends_at'    => $s['ends_at'],
  ] : null,
  'enrollments' => $enrollments,
  'plans'       => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $plans),
  'courses'     => array_map(fn($c) => ['id' => (int)$c['id'], 'title' => $c['title']], $courses),
]);
