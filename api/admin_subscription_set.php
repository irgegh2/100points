<?php
// POST /admin_subscription_set.php  { user_id, plan_id|null, status, started_at?, ends_at? }
// Админ задаёт/снимает подписку ученика. Держим одну строку на ученика (перезаписываем).
// status='none' (или plan_id пустой) — снять подписку.
require __DIR__ . '/bootstrap.php';
require_admin();

$data = body();
$uid    = (int)($data['user_id'] ?? 0);
$planId = ($data['plan_id'] ?? '') === '' ? null : (int)$data['plan_id'];
$status = trim($data['status'] ?? 'active');
$started = trim($data['started_at'] ?? '');
$ends    = trim($data['ends_at'] ?? '');

if ($uid <= 0) fail('Не указан ученик', 400);

$exists = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$exists->execute([$uid]);
if (!$exists->fetch()) fail('Ученик не найден', 404);

// Снятие подписки: нет плана или статус none → удаляем все строки ученика.
if ($planId === null || $status === 'none') {
  db()->prepare("DELETE FROM subscriptions WHERE user_id = ?")->execute([$uid]);
  ok(['subscription' => null]);
}

if (!in_array($status, ['active', 'expired'], true)) $status = 'active';

// Перезаписываем одну строку: удалить старые, вставить новую.
db()->prepare("DELETE FROM subscriptions WHERE user_id = ?")->execute([$uid]);
db()->prepare("INSERT INTO subscriptions (user_id, plan_id, status, started_at, ends_at) VALUES (?,?,?,?,?)")
    ->execute([$uid, $planId, $status, $started ?: date('Y-m-d'), $ends ?: null]);

ok(['subscription' => ['plan_id' => $planId, 'status' => $status, 'started_at' => $started ?: date('Y-m-d'), 'ends_at' => $ends ?: null]]);
