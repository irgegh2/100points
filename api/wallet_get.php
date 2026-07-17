<?php
// GET /wallet_get.php — баланс ученика (кристаллы, жизни, серия) для хедера и Главной.
// Заодно: применяет недельный сброс жизней + штрафы за просроченные дедлайны, отмечает активность дня.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();

try {
  g_sync($uid);          // недельный сброс жизней (остаток → кристаллы) + штрафы за дедлайн
  g_touch_streak($uid);  // вход в кабинет = активность дня (серия + дневной бонус)
} catch (Throwable $e) { error_log('[gamify] ' . $e->getMessage()); }

$w = g_wallet($uid);
release_session();
ok([
  'crystals'    => (int)$w['crystals'],
  'lives'       => (int)$w['lives'],
  'max_lives'   => g_max_lives($uid),
  'streak'      => (int)$w['streak'],
  'streak_best' => (int)$w['streak_best'],
  'week_start'  => $w['week_start'],
  'booster_until' => (string)($w['booster_until'] ?? ''),
  'vip'         => g_is_vip($uid),
]);
