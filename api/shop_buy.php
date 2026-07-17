<?php
// POST /shop_buy.php  { item_id, homework_id? }
// Покупка: списывает кристаллы и создаёт заявку. Цифровое оформление
// активируется сразу, остальные товары сохраняют ручную выдачу через админку.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();

$in = body();
$itemId = (int)($in['item_id'] ?? 0);
$homeworkId = (int)($in['homework_id'] ?? 0);
if ($itemId <= 0) fail('Не указан товар', 400);

$it = db()->prepare("SELECT id, name, category, price, once_only FROM shop_items WHERE id = ? AND active = 1 LIMIT 1");
$it->execute([$itemId]);
$item = $it->fetch();
if (!$item) fail('Товар недоступен', 404);
$comingSoonItems = ['Секретный конспект', 'Запись закрытого вебинара'];
if (in_array($item['name'], $comingSoonItems, true)) fail('Этот товар скоро появится', 409);
$price = (int)$item['price'];
$themeCodes = [
  'Тёмная тема' => 'dark',
  'Неоновая тема' => 'neon',
  'Тема «Космос»' => 'space',
];
$themeCode = $item['category'] === 'theme' ? ($themeCodes[$item['name']] ?? null) : null;
$cosmeticCodes = [
  'Рамка для аватара' => 'avatar_frame',
  'Цвет ника' => 'nickname_color',
];
$cosmeticCode = $item['category'] === 'theme' ? ($cosmeticCodes[$item['name']] ?? null) : null;
$effectCodes = [
  'Заморозка стрика' => 'streak_freeze',
  'Продление дедлайна +24ч' => 'deadline_extension',
  '+1 жизнь' => 'extra_life',
  'Бустер ×2 кристалла' => 'crystal_booster',
  'VIP-статус навсегда' => 'vip',
];
$effectCode = $effectCodes[$item['name']] ?? null;
$instant = $themeCode !== null || $cosmeticCode !== null || $effectCode !== null;
$status = $instant ? 'done' : 'pending';

$selectedHomework = null;
if ($effectCode === 'deadline_extension') {
  if ($homeworkId <= 0) fail('Сначала выбери домашнее задание', 400);
  foreach (g_eligible_homeworks_for_extension($uid) as $hw) {
    if ((int)$hw['id'] === $homeworkId) { $selectedHomework = $hw; break; }
  }
  if (!$selectedHomework) fail('Это ДЗ уже сдано, просрочено или недоступно для продления', 409);
}

// once-only — нельзя купить повторно.
if ((int)$item['once_only'] === 1) {
  $c = db()->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND item_id = ? AND status IN ('pending','done') LIMIT 1");
  $c->execute([$uid, $itemId]);
  if ($c->fetch()) fail('Этот товар покупается один раз — он уже у тебя', 409);
}

$w = g_wallet($uid);
if ((int)$w['crystals'] < $price) fail('Недостаточно кристаллов', 400);

try {
  $pdo = db();
  $pdo->beginTransaction();

  // Блокируем кошелёк: параллельные клики не спишут деньги и не создадут дубль.
  $lockSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? 'SELECT crystals, lives, booster_until FROM wallets WHERE user_id = ? FOR UPDATE'
    : 'SELECT crystals, lives, booster_until FROM wallets WHERE user_id = ?';
  $lock = $pdo->prepare($lockSql);
  $lock->execute([$uid]);
  $lockedWallet = $lock->fetch();
  $lockedBalance = (int)($lockedWallet['crystals'] ?? 0);
  if ($lockedBalance < $price) throw new RuntimeException('insufficient');
  if ($effectCode === 'extra_life' && (int)$lockedWallet['lives'] >= g_max_lives($uid)) {
    throw new RuntimeException('full_lives');
  }

  if ((int)$item['once_only'] === 1) {
    $dup = $pdo->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND item_id = ? AND status IN ('pending','done') LIMIT 1");
    $dup->execute([$uid, $itemId]);
    if ($dup->fetchColumn()) throw new RuntimeException('duplicate');
  }

  if ($effectCode === 'deadline_extension') {
    $stillEligible = false;
    foreach (g_eligible_homeworks_for_extension($uid) as $hw) {
      if ((int)$hw['id'] === $homeworkId) { $selectedHomework = $hw; $stillEligible = true; break; }
    }
    if (!$stillEligible) throw new RuntimeException('homework_unavailable');
  }

  $pdo->prepare("UPDATE wallets SET crystals = crystals - ? WHERE user_id = ?")->execute([$price, $uid]);
  $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'crystals', ?, ?, 'purchase', ?)")
      ->execute([$uid, -$price, 'Покупка: ' . $item['name'], $itemId]);
  $purchaseNote = $instant ? 'Доступ активирован автоматически' : null;
  if ($effectCode === 'deadline_extension') $purchaseNote = 'Продлено ДЗ: ' . $selectedHomework['title'];
  if ($effectCode === 'streak_freeze') $purchaseNote = 'Заморозка добавлена и сработает автоматически';
  $pdo->prepare("INSERT INTO purchases (user_id, item_id, item_name, price, status, note, updated_at) VALUES (?,?,?,?, ?, ?, ?)")
      ->execute([$uid, $itemId, $item['name'], $price, $status, $purchaseNote, date('Y-m-d H:i:s')]);
  $pid = (int)$pdo->lastInsertId();

  if ($effectCode === 'deadline_extension') {
    $pdo->prepare("INSERT INTO deadline_extensions (user_id, homework_id, purchase_id, extra_days) VALUES (?, ?, ?, 1)")
        ->execute([$uid, $homeworkId, $pid]);
  } elseif ($effectCode === 'streak_freeze') {
    $pdo->prepare("INSERT INTO streak_freezes (user_id, purchase_id) VALUES (?, ?)")->execute([$uid, $pid]);
  } elseif ($effectCode === 'extra_life') {
    $pdo->prepare("UPDATE wallets SET lives = lives + 1 WHERE user_id = ?")->execute([$uid]);
    g_log($uid, 'lives', 1, 'Покупка: +1 жизнь', 'purchase', $pid);
  } elseif ($effectCode === 'crystal_booster') {
    $base = !empty($lockedWallet['booster_until']) && strtotime((string)$lockedWallet['booster_until']) > time()
      ? strtotime((string)$lockedWallet['booster_until']) : time();
    $boosterUntil = gmdate('Y-m-d\TH:i:s\Z', $base + 86400);
    $pdo->prepare("UPDATE wallets SET booster_until = ? WHERE user_id = ?")->execute([$boosterUntil, $uid]);
  } elseif ($effectCode === 'vip') {
    $newLives = min(7, (int)$lockedWallet['lives'] + 2);
    $delta = $newLives - (int)$lockedWallet['lives'];
    $pdo->prepare("UPDATE wallets SET lives = ? WHERE user_id = ?")->execute([$newLives, $uid]);
    if ($delta > 0) g_log($uid, 'lives', $delta, 'VIP: запас жизней увеличен', 'purchase', $pid);
  }
  $pdo->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  if ($e->getMessage() === 'duplicate') fail('Этот товар покупается один раз — он уже у тебя', 409);
  if ($e->getMessage() === 'insufficient') fail('Недостаточно кристаллов', 400);
  if ($e->getMessage() === 'full_lives') fail('У тебя уже полный запас жизней', 409);
  if ($e->getMessage() === 'homework_unavailable') fail('Это ДЗ уже сдано, просрочено или недоступно для продления', 409);
  fail('Не удалось оформить покупку', 500);
}

$message = $instant
  ? "Покупка «{$item['name']}» активирована сразу."
  : "Покупка «{$item['name']}» оформлена. Заявка принята — скоро выдадим.";
notify($uid, 'shop', $message, 'shop');

$w = g_wallet($uid);
ok([
  'purchase_id' => $pid,
  'balance' => (int)$w['crystals'],
  'status' => $status,
  'instant' => $instant,
  'theme_unlocked' => $themeCode !== null,
  'theme' => $themeCode,
  'cosmetic_unlocked' => $cosmeticCode !== null,
  'cosmetic' => $cosmeticCode,
  'effect' => $effectCode,
  'lives' => (int)$w['lives'],
  'max_lives' => g_max_lives($uid),
  'booster_until' => (string)($w['booster_until'] ?? ''),
  'vip' => g_is_vip($uid),
  'streak_freezes' => g_available_streak_freezes($uid),
  'extended_homework' => $effectCode === 'deadline_extension' ? [
    'id' => $homeworkId,
    'title' => $selectedHomework['title'],
    'deadline' => $selectedHomework['new_deadline'],
  ] : null,
]);
