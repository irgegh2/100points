<?php
// GET /daily_get.php — задания дня + недельный бонус (из реальной активности ученика).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$today = date('Y-m-d');
$w = g_wallet($uid);

// Была ли активность сегодня по типу (по журналу начислений).
$hasLog = function (string $refType) use ($uid, $today): bool {
  $s = db()->prepare("SELECT 1 FROM coin_log WHERE user_id = ? AND ref_type = ? AND substr(created_at,1,10) = ? LIMIT 1");
  $s->execute([$uid, $refType, $today]);
  return (bool)$s->fetch();
};

// Пункты = реальным начислениям (без дублей): вход за день серии (+5), сдача ДЗ (+10), пробник (+20).
$tasks = [
  ['label' => 'Зайди на платформу',     'pts' => g_daily_reward($uid) * g_reward_multiplier($uid, 'streak'), 'done' => $w['last_active'] === $today],
  ['label' => 'Сдай домашку на 50%+',   'pts' => G_HW * g_reward_multiplier($uid, 'homework'), 'done' => $hasLog('homework')],
  ['label' => 'Пройди пробник на 50%+', 'pts' => G_MOCK * g_reward_multiplier($uid, 'mock'), 'done' => $hasLog('mock')],
];

// Недельная сетка: какие дни этой недели были активны (есть метка серии).
$monday = date('Y-m-d', strtotime('monday this week'));
$ad = db()->prepare("SELECT DISTINCT substr(created_at,1,10) d FROM coin_log WHERE user_id = ? AND ref_type = 'streak' AND substr(created_at,1,10) >= ?");
$ad->execute([$uid, $monday]);
$active = array_column($ad->fetchAll(), 'd');
$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = date('Y-m-d', strtotime("$monday +$i days"));
  $days[] = ['date' => $d, 'done' => in_array($d, $active, true)];
}

ok([
  'tasks'     => $tasks,
  'days'      => $days,
  'week_done' => count(array_filter($days, fn($x) => $x['done'])),
  'streak'    => (int)$w['streak'],
]);
