<?php
// POST /profile_save.php — ученик сохраняет свой профиль (анкета + цели по предметам).
// Body: { name, birthdate, goals:[{subject,target_score,hours_per_week}, ...] }
// ВАЖНО:
//  • email/phone здесь НЕ меняются — это логин (правится через поддержку/админку).
//  • avatar здесь НЕ принимается из тела (раньше это позволяло подменить чужой путь и
//    удалить чужой файл через чистку в avatar_upload). Аватар ставится ТОЛЬКО в avatar_upload.php.
require __DIR__ . '/bootstrap.php';

$uid = require_user();
$data = body();

$name      = trim($data['name'] ?? '');
$birthdate = trim($data['birthdate'] ?? '');
$goals     = is_array($data['goals'] ?? null) ? $data['goals'] : [];

try {
  $pdo = db();
  $pdo->beginTransaction();

  // 1) имя ученика
  if ($name !== '') {
    $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$name, $uid]);
  }

  // 2) профиль: трогаем только birthdate, avatar НЕ затрагиваем (им владеет avatar_upload).
  $exists = $pdo->prepare("SELECT user_id FROM profiles WHERE user_id = ? LIMIT 1");
  $exists->execute([$uid]);
  if ($exists->fetch()) {
    $pdo->prepare("UPDATE profiles SET birthdate = ? WHERE user_id = ?")->execute([$birthdate, $uid]);
  } else {
    $pdo->prepare("INSERT INTO profiles (user_id, birthdate) VALUES (?,?)")->execute([$uid, $birthdate]);
  }

  // 3) цели по предметам — заменяем целиком (просто и без гонок дублей).
  $pdo->prepare("DELETE FROM profile_goals WHERE user_id = ?")->execute([$uid]);
  $ins = $pdo->prepare("INSERT INTO profile_goals (user_id, subject, target_score, hours_per_week)
                        VALUES (?,?,?,?)");
  foreach ($goals as $g) {
    $subject = trim($g['subject'] ?? '');
    if ($subject === '') continue;
    $score = isset($g['target_score'])   && $g['target_score']   !== '' ? (int)$g['target_score']   : null;
    $hours = isset($g['hours_per_week']) && $g['hours_per_week'] !== '' ? (int)$g['hours_per_week'] : null;
    $ins->execute([$uid, $subject, $score, $hours]);
  }

  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось сохранить профиль', 500);
}

// Возвращаем актуальные данные ИЗ БД (avatar — тоже из БД, не из ввода).
$ustmt = db()->prepare("SELECT id, name, email, phone, role FROM users WHERE id = ? LIMIT 1");
$ustmt->execute([$uid]);
$user = $ustmt->fetch();

$pstmt = db()->prepare("SELECT avatar, birthdate FROM profiles WHERE user_id = ? LIMIT 1");
$pstmt->execute([$uid]);
$prof = $pstmt->fetch();

$gstmt = db()->prepare("SELECT subject, target_score, hours_per_week FROM profile_goals WHERE user_id = ?");
$gstmt->execute([$uid]);
$savedGoals = [];
foreach ($gstmt as $g) {
  $savedGoals[] = [
    'subject'        => $g['subject'],
    'target_score'   => $g['target_score']   !== null ? (int)$g['target_score']   : null,
    'hours_per_week' => $g['hours_per_week'] !== null ? (int)$g['hours_per_week'] : null,
  ];
}

ok([
  'user' => public_user($user),
  'profile' => [
    'avatar'    => $prof['avatar']    ?? '',
    'birthdate' => $prof['birthdate'] ?? '',
    'goals'     => $savedGoals,
  ],
]);
