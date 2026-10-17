<?php
// POST /tour_complete.php — завершает тур и один раз начисляет 100 кристаллов.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';

$uid = require_user();
$reward = 100;
$pdo = db();

// Строка кошелька служит блокировкой: два одновременных клика не дадут двойную награду.
g_wallet($uid);
$pdo->beginTransaction();
try {
  $lockSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? 'SELECT id FROM wallets WHERE user_id = ? FOR UPDATE'
    : 'SELECT id FROM wallets WHERE user_id = ?';
  $lock = $pdo->prepare($lockSql);
  $lock->execute([$uid]);

  $check = $pdo->prepare("SELECT 1 FROM coin_log
    WHERE user_id = ? AND kind = 'crystals' AND ref_type = 'platform_tour' AND ref_id = 1 AND amount > 0
    LIMIT 1");
  $check->execute([$uid]);
  $awarded = !$check->fetchColumn();

  if ($awarded) {
    g_add_crystals($uid, $reward, 'Пройден тур по платформе', 'platform_tour', 1);
  }
  // Прогресс фиксируем и при повторном прохождении. Награда при этом остаётся одноразовой.
  g_log($uid, 'tour', 0, 'Тур по платформе завершён', 'platform_tour_progress', 6);

  $wallet = g_wallet($uid);
  $pdo->commit();
  ok([
    'completed' => true,
    'awarded' => $awarded,
    'reward' => $reward,
    'balance' => (int)$wallet['crystals'],
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}
