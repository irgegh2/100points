<?php
// GET /drive_tour_status.php — одноразовый обзор Стобалльного диска.
// Доступен только пользователям, которых миграция включила в стартовую когорту.
require __DIR__ . '/bootstrap.php';

$uid = require_user();
$key = 'drive-launch-2026';

$st = db()->prepare("SELECT step, status FROM user_feature_tours WHERE user_id = ? AND tour_key = ? LIMIT 1");
$st->execute([$uid, $key]);
$row = $st->fetch();

if (!$row) {
  ok(['eligible' => false, 'completed' => true, 'step' => 0]);
}

ok([
  'eligible' => true,
  'completed' => (string)$row['status'] !== 'pending',
  'status' => (string)$row['status'],
  'step' => max(0, min(3, (int)$row['step'])),
]);
