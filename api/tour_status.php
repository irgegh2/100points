<?php
// GET /tour_status.php — серверный прогресс автоматического тура по кабинету.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';

$uid = require_user();
$reward = 100;

$progress = db()->prepare("SELECT MAX(ref_id) FROM coin_log
  WHERE user_id = ? AND kind = 'tour' AND ref_type = 'platform_tour_progress'");
$progress->execute([$uid]);

$step = max(0, (int)($progress->fetchColumn() ?: 0));
$wallet = g_wallet($uid);
ok([
  // Завершение тура и факт уже полученной награды — разные вещи: так администратор
  // может сбросить прогресс для повторного прохождения, не выдавая ещё 100 кристаллов.
  'completed' => $step >= 6,
  'step' => $step,
  'reward' => $reward,
  'balance' => (int)$wallet['crystals'],
]);
