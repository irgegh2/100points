<?php
// GET /daily_get.php — задания дня + недельный бонус (из реальной активности ученика).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

// Этот эндпоинт сам фиксирует вход перед чтением состояния. Так параллельный
// wallet_get не может проиграть гонку и вернуть на главной вчерашний день.
try {
  g_sync($uid);
  g_touch_streak($uid);
} catch (Throwable $e) { error_log('[gamify daily] ' . $e->getMessage()); }

$today = date('Y-m-d');
$w = g_wallet($uid);
$driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
// created_at хранится в UTC; Москва с 2014 года постоянно UTC+3.
$localLogDate = $driver === 'mysql'
  ? "DATE(DATE_ADD(created_at, INTERVAL 3 HOUR))"
  : "date(datetime(created_at, '+3 hours'))";

// Была ли активность сегодня по типу (по журналу начислений).
$hasLog = function (string $refType) use ($uid, $today, $localLogDate): bool {
  $s = db()->prepare("SELECT 1 FROM coin_log WHERE user_id = ? AND ref_type = ? AND $localLogDate = ? LIMIT 1");
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
$ad = db()->prepare("SELECT DISTINCT $localLogDate d FROM coin_log WHERE user_id = ? AND ref_type = 'streak' AND $localLogDate >= ?");
$ad->execute([$uid, $monday]);
$active = array_column($ad->fetchAll(), 'd');
$fd = db()->prepare("SELECT missed_date FROM streak_freezes WHERE user_id = ? AND missed_date IS NOT NULL AND missed_date >= ?");
$fd->execute([$uid, $monday]);
$frozen = array_column($fd->fetchAll(), 'missed_date');
$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = date('Y-m-d', strtotime("$monday +$i days"));
  $days[] = ['date' => $d, 'done' => in_array($d, $active, true), 'frozen' => in_array($d, $frozen, true)];
}

ok([
  'tasks'     => $tasks,
  'days'      => $days,
  'week_done' => count(array_filter($days, fn($x) => $x['done'])),
  'streak'    => (int)$w['streak'],
  'streak_freezes' => g_available_streak_freezes($uid),
]);
