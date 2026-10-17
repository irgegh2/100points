<?php
// POST /drive_tour_update.php — прогресс, завершение или окончательное закрытие обзора диска.
require __DIR__ . '/bootstrap.php';

$uid = require_user();
$data = body();
$key = 'drive-launch-2026';
$action = (string)($data['action'] ?? 'progress');
$step = max(0, min(3, (int)($data['step'] ?? 0)));

$st = db()->prepare("SELECT id, step, status FROM user_feature_tours WHERE user_id = ? AND tour_key = ? LIMIT 1");
$st->execute([$uid, $key]);
$row = $st->fetch();
if (!$row) ok(['eligible' => false, 'completed' => true, 'step' => 0]);

$savedStep = max($step, (int)$row['step']);
$status = (string)$row['status'];
if ($status === 'pending' && in_array($action, ['complete', 'dismiss'], true)) {
  $status = $action === 'complete' ? 'completed' : 'dismissed';
  $savedStep = $action === 'complete' ? 3 : $savedStep;
  db()->prepare("UPDATE user_feature_tours SET step = ?, status = ?, finished_at = ? WHERE id = ?")
      ->execute([$savedStep, $status, date('Y-m-d H:i:s'), (int)$row['id']]);
} elseif ($status === 'pending' && $savedStep > (int)$row['step']) {
  db()->prepare("UPDATE user_feature_tours SET step = ? WHERE id = ?")->execute([$savedStep, (int)$row['id']]);
}

ok([
  'eligible' => true,
  'completed' => $status !== 'pending',
  'status' => $status,
  'step' => $savedStep,
]);
