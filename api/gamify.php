<?php
// Геймификация (Этап 9): кошелёк (кристаллы/жизни), журнал, серия, достижения.
// Подключается из триггер-эндпоинтов (homework/mock submit) и из wallet_get/daily_get.
// Все функции безопасны к повторному вызову; начисления логируются в coin_log.

const G_WEEKLY_LIVES   = 5;    // жизней на неделю
const G_LIFE_TO_CRYSTAL = 25;  // кристаллов за каждую сохранённую жизнь в конце недели
const G_HW             = 10;   // за сдачу ДЗ
const G_HW_PERFECT     = 15;   // доп. за тест на 100%
const G_MOCK           = 20;   // за пробник
const G_STREAK_DAY     = 5;    // за день серии (активность)

// Кошелёк ученика (создаёт при первом обращении).
function g_wallet(int $uid): array {
  $w = db()->prepare("SELECT * FROM wallets WHERE user_id = ? LIMIT 1");
  $w->execute([$uid]);
  if ($row = $w->fetch()) return $row;
  db()->prepare("INSERT INTO wallets (user_id, crystals, lives, week_start, streak, streak_best, last_active) VALUES (?,0,?,?,0,0,'')")
      ->execute([$uid, G_WEEKLY_LIVES, date('Y-m-d')]);
  $w->execute([$uid]);
  return $w->fetch();
}

function g_log(int $uid, string $kind, int $amount, string $reason, ?string $refType = null, ?int $refId = null): void {
  db()->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?,?,?,?,?,?)")
      ->execute([$uid, $kind, $amount, $reason, $refType, $refId]);
}

function g_add_crystals(int $uid, int $amount, string $reason, ?string $refType = null, ?int $refId = null): void {
  if ($amount === 0) return;
  db()->prepare("UPDATE wallets SET crystals = crystals + ? WHERE user_id = ?")->execute([$amount, $uid]);
  g_log($uid, 'crystals', $amount, $reason, $refType, $refId);
  if ($amount > 0) g_ach_progress($uid, 'crystals_500', $amount); // прогресс «накопи 500»
}

function g_lose_life(int $uid, int $n, string $reason, ?string $refType = null, ?int $refId = null): void {
  $w = g_wallet($uid);
  $new = max(0, (int)$w['lives'] - $n);
  $delta = $new - (int)$w['lives'];
  if ($delta === 0) return;
  db()->prepare("UPDATE wallets SET lives = ? WHERE user_id = ?")->execute([$new, $uid]);
  g_log($uid, 'lives', $delta, $reason, $refType, $refId);
}

// Отметка активности дня: растит серию, даёт дневной бонус, двигает достижение «7 дней».
function g_touch_streak(int $uid): void {
  $w = g_wallet($uid);
  $today = date('Y-m-d');
  if ($w['last_active'] === $today) return; // уже отмечались сегодня
  $yesterday = date('Y-m-d', strtotime('-1 day'));
  $streak = ($w['last_active'] === $yesterday) ? (int)$w['streak'] + 1 : 1;
  $best = max((int)$w['streak_best'], $streak);
  db()->prepare("UPDATE wallets SET streak = ?, streak_best = ?, last_active = ? WHERE user_id = ?")
      ->execute([$streak, $best, $today, $uid]);
  g_log($uid, 'streak', 0, 'Активность дня', 'streak', $streak); // метка дня для недельной сетки
  g_add_crystals($uid, G_STREAK_DAY, "Серия: день $streak", 'streak', $streak);
  g_ach_set($uid, 'streak_7', $streak);
}

// ── Достижения ──
function g_ach_row(string $code): ?array {
  static $cache = [];
  if (!array_key_exists($code, $cache)) {
    $a = db()->prepare("SELECT * FROM achievements WHERE code = ? LIMIT 1");
    $a->execute([$code]);
    $cache[$code] = $a->fetch() ?: null;
  }
  return $cache[$code];
}
function g_ua(int $uid, int $achId): array {
  $u = db()->prepare("SELECT * FROM user_achievements WHERE user_id = ? AND achievement_id = ? LIMIT 1");
  $u->execute([$uid, $achId]);
  if ($row = $u->fetch()) return $row;
  db()->prepare("INSERT INTO user_achievements (user_id, achievement_id, progress) VALUES (?,?,0)")->execute([$uid, $achId]);
  $u->execute([$uid, $achId]);
  return $u->fetch();
}
function g_ach_apply(int $uid, array $a, int $prog): void {
  $target = max(1, (int)$a['target']);
  if ($prog >= $target) {
    // Сначала помечаем открытым (чтобы награда-кристаллы не зациклила crystals_500), потом награда.
    db()->prepare("UPDATE user_achievements SET progress = ?, unlocked_at = ? WHERE user_id = ? AND achievement_id = ?")
        ->execute([$target, date('Y-m-d H:i:s'), $uid, (int)$a['id']]);
    if ((int)$a['reward'] > 0) g_add_crystals($uid, (int)$a['reward'], 'Достижение: ' . $a['title'], 'achievement', (int)$a['id']);
    if (function_exists('notify')) notify($uid, 'achievement', "Достижение «{$a['title']}» открыто! +{$a['reward']} кристаллов", 'achievements');
  } else {
    db()->prepare("UPDATE user_achievements SET progress = ? WHERE user_id = ? AND achievement_id = ?")
        ->execute([$prog, $uid, (int)$a['id']]);
  }
}
// прогресс += inc
function g_ach_progress(int $uid, string $code, int $inc = 1): void {
  $a = g_ach_row($code);
  if (!$a) return;
  $ua = g_ua($uid, (int)$a['id']);
  if ($ua['unlocked_at']) return;
  g_ach_apply($uid, $a, (int)$ua['progress'] + $inc);
}
// установить прогресс не ниже value (для серии — текущая длина)
function g_ach_set(int $uid, string $code, int $value): void {
  $a = g_ach_row($code);
  if (!$a) return;
  $ua = g_ua($uid, (int)$a['id']);
  if ($ua['unlocked_at']) return;
  g_ach_apply($uid, $a, max($value, (int)$ua['progress']));
}

// ── Начисления за сдачу работ (честно: только при результате ≥50%, один раз на работу) ──

// Уже начисляли кристаллы за эту работу? (защита от повтора при пересдаче/перепроверке)
function g_already_awarded(int $uid, string $kind, int $refId): bool {
  $s = db()->prepare("SELECT 1 FROM coin_log WHERE user_id = ? AND ref_type = ? AND ref_id = ? AND kind = 'crystals' AND amount > 0 LIMIT 1");
  $s->execute([$uid, $kind, $refId]);
  return (bool)$s->fetch();
}

// Награда за ДЗ. Порог 50% считаем ТОЛЬКО по авто-проверяемым заданиям (без textarea).
// Если авто-заданий нет (только вторая часть) — начисляем сразу. Один раз на ДЗ.
function g_award_homework(int $uid, int $hwId, int $autoScore, int $autoMax, int $autoCount): void {
  if ($autoCount > 0 && ($autoMax <= 0 || $autoScore / $autoMax < 0.5)) return;
  if (g_already_awarded($uid, 'homework', $hwId)) return;
  g_add_crystals($uid, G_HW, 'Сдано домашнее задание', 'homework', $hwId);
  g_ach_progress($uid, 'first_homework', 1);
  g_ach_progress($uid, 'homeworks_10', 1);
  if ($autoCount > 0 && $autoMax > 0 && $autoScore >= $autoMax) {
    g_add_crystals($uid, G_HW_PERFECT, 'Тест на 100%', 'homework', $hwId);
    g_ach_progress($uid, 'perfect_test', 1);
  }
}

// Награда за пробник — та же логика (порог по авто-части).
function g_award_mock(int $uid, int $mockId, int $autoScore, int $autoMax, int $autoCount): void {
  if ($autoCount > 0 && ($autoMax <= 0 || $autoScore / $autoMax < 0.5)) return;
  if (g_already_awarded($uid, 'mock', $mockId)) return;
  g_add_crystals($uid, G_MOCK, 'Пройден пробник', 'mock', $mockId);
  g_ach_progress($uid, 'mock_done', 1);
  g_ach_progress($uid, 'mocks_5', 1);
}

// ── Ленивая синхронизация: штрафы за дедлайн + недельный сброс жизней ──
function g_sync(int $uid): void {
  g_apply_deadline_penalties($uid);

  // Недельный сброс: остаток жизней → кристаллы, жизни снова 5. Обрабатываем все прошедшие недели.
  $guard = 0;
  while ($guard++ < 60) {
    $w = g_wallet($uid);
    $start = $w['week_start'] ?: date('Y-m-d');
    if (strtotime($start . ' +7 days') > strtotime(date('Y-m-d'))) break;
    $saved = (int)$w['lives'];
    if ($saved > 0) g_add_crystals($uid, $saved * G_LIFE_TO_CRYSTAL, 'Бонус за сохранённые жизни', 'weekly', $saved);
    $next = date('Y-m-d', strtotime($start . ' +7 days'));
    db()->prepare("UPDATE wallets SET lives = ?, week_start = ? WHERE user_id = ?")->execute([G_WEEKLY_LIVES, $next, $uid]);
  }
}

// Просроченные дедлайны доступных ДЗ без сдачи → −1 жизнь (один раз на ДЗ).
function g_apply_deadline_penalties(int $uid): void {
  $today = date('Y-m-d');
  $st = db()->prepare(
    "SELECT h.id, h.deadline FROM homeworks h
     JOIN lessons l ON l.id = h.lesson_id
     JOIN blocks b ON b.id = l.block_id
     JOIN courses c ON c.id = b.course_id
     JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
        AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
     WHERE h.deadline IS NOT NULL AND h.deadline <> ''
       AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.homework_id = h.id AND s.user_id = ?)
       AND NOT EXISTS (SELECT 1 FROM deadline_penalties dp WHERE dp.homework_id = h.id AND dp.user_id = ?)"
  );
  $st->execute([$uid, $today, $uid, $uid]);
  foreach ($st->fetchAll() as $h) {
    $ts = strtotime((string)$h['deadline']);
    if (!$ts) continue;                 // дедлайн не дата — пропускаем
    if ($ts + 86400 > time()) continue; // ещё не прошёл (конец дня дедлайна)
    db()->prepare("INSERT INTO deadline_penalties (user_id, homework_id) VALUES (?,?)")->execute([$uid, (int)$h['id']]);
    g_lose_life($uid, 1, 'Просрочен дедлайн домашки', 'homework', (int)$h['id']);
  }
}
