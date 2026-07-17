<?php
// POST /tour_progress.php — сохраняет достигнутый шаг тура (назад прогресс не откатывает).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';

$uid = require_user();
$data = body();
$step = max(0, min(6, (int)($data['step'] ?? 0)));

$current = db()->prepare("SELECT MAX(ref_id) FROM coin_log
  WHERE user_id = ? AND kind = 'tour' AND ref_type = 'platform_tour_progress'");
$current->execute([$uid]);
$saved = max(0, (int)($current->fetchColumn() ?: 0));

if ($step > $saved) {
  g_log($uid, 'tour', 0, 'Прогресс тура по платформе', 'platform_tour_progress', $step);
  $saved = $step;
}

ok(['step' => $saved]);
