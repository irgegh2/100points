<?php
// GET /tour_status.php — серверный прогресс автоматического тура по кабинету.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';

$uid = require_user();
$reward = 100;

$done = db()->prepare("SELECT 1 FROM coin_log
  WHERE user_id = ? AND kind = 'crystals' AND ref_type = 'platform_tour' AND ref_id = 1 AND amount > 0
  LIMIT 1");
$done->execute([$uid]);

$progress = db()->prepare("SELECT MAX(ref_id) FROM coin_log
  WHERE user_id = ? AND kind = 'tour' AND ref_type = 'platform_tour_progress'");
$progress->execute([$uid]);

$wallet = g_wallet($uid);
ok([
  'completed' => (bool)$done->fetchColumn(),
  'step' => max(0, (int)($progress->fetchColumn() ?: 0)),
  'reward' => $reward,
  'balance' => (int)$wallet['crystals'],
]);
