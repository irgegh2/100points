<?php
// POST /users_update.php
// Body: { id, name, email, phone, password?, role?, birthdate?, avatar?, goals?[] }
// Админ редактирует аккаунт ЛЮБОГО ученика целиком: логин/пароль/роль + профиль (аватар,
// дата рождения, цели по предметам). Пароль меняется только если передан непустым.
require __DIR__ . '/bootstrap.php';
require_admin();

$data = body();
$id    = (int)($data['id'] ?? 0);
$name  = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = clean_phone($data['phone'] ?? ''); // храним +7XXXXXXXXXX; неполный → ошибка
$password = (string)($data['password'] ?? '');

if ($id <= 0) fail('Не указан ученик', 400);
if ($email === '' && $phone === '') fail('Нужна почта или телефон', 400);

// Роль — из белого списка (или не трогаем, если не передана/пустая).
$roleIn = trim($data['role'] ?? '');
$role = in_array($roleIn, ['student', 'mentor', 'teacher'], true) ? $roleIn : null;

// Профиль (необязательные поля). Признак «передан ли блок профиля» — наличие ключей.
$hasBirthdate = array_key_exists('birthdate', $data);
$hasAvatar    = array_key_exists('avatar', $data);
$hasGoals     = array_key_exists('goals', $data) && is_array($data['goals']);
$birthdate = trim($data['birthdate'] ?? '');
$avatar    = trim($data['avatar'] ?? '');
$goals     = $hasGoals ? $data['goals'] : [];

// Существует ли такой ученик.
$stmt = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
if (!$stmt->fetch()) fail('Ученик не найден', 404);

// Почта/телефон не должны быть заняты ДРУГИМ пользователем.
$dup = db()->prepare("SELECT id FROM users WHERE id <> ? AND ((email <> '' AND email = ?) OR (phone <> '' AND phone = ?)) LIMIT 1");
$dup->execute([$id, $email, $phone]);
if ($dup->fetch()) fail('Почта или телефон уже заняты другим аккаунтом', 409);

if ($password !== '' && strlen($password) < 4) {
  fail('Пароль слишком короткий (минимум 4 символа)', 400);
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  // 1) аккаунт. Собираем SET динамически (пароль/роль — опционально).
  $set = ['name = ?', 'email = ?', 'phone = ?'];
  $params = [$name, $email !== '' ? $email : null, $phone !== '' ? $phone : null];
  if ($password !== '') { $set[] = 'password_hash = ?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
  if ($role !== null)   { $set[] = 'role = ?';          $params[] = $role; }
  $params[] = $id;
  $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?")->execute($params);

  // 2) профиль (birthdate/avatar) — апсертим только если хотя бы одно передано.
  if ($hasBirthdate || $hasAvatar) {
    $exists = $pdo->prepare("SELECT user_id FROM profiles WHERE user_id = ? LIMIT 1");
    $exists->execute([$id]);
    $rowExists = (bool)$exists->fetch();
    if ($rowExists) {
      $pset = []; $pp = [];
      if ($hasBirthdate) { $pset[] = 'birthdate = ?'; $pp[] = $birthdate; }
      if ($hasAvatar)    { $pset[] = 'avatar = ?';    $pp[] = $avatar; }
      $pp[] = $id;
      $pdo->prepare("UPDATE profiles SET " . implode(', ', $pset) . " WHERE user_id = ?")->execute($pp);
    } else {
      $pdo->prepare("INSERT INTO profiles (user_id, birthdate, avatar) VALUES (?,?,?)")
          ->execute([$id, $hasBirthdate ? $birthdate : null, $hasAvatar ? $avatar : null]);
    }
  }

  // 3) цели по предметам — заменяем целиком, только если блок передан.
  if ($hasGoals) {
    $pdo->prepare("DELETE FROM profile_goals WHERE user_id = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO profile_goals (user_id, subject, target_score, hours_per_week) VALUES (?,?,?,?)");
    foreach ($goals as $g) {
      $subject = trim($g['subject'] ?? '');
      if ($subject === '') continue;
      $score = isset($g['target_score'])   && $g['target_score']   !== '' ? (int)$g['target_score']   : null;
      $hours = isset($g['hours_per_week']) && $g['hours_per_week'] !== '' ? (int)$g['hours_per_week'] : null;
      $ins->execute([$id, $subject, $score, $hours]);
    }
  }

  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось сохранить (возможно, дубликат почты/телефона)', 409);
}

// Возвращаем полный актуальный профиль из БД.
$ustmt = db()->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?");
$ustmt->execute([$id]);
$user = $ustmt->fetch();
$pstmt = db()->prepare("SELECT avatar, birthdate FROM profiles WHERE user_id = ? LIMIT 1");
$pstmt->execute([$id]);
$prof = $pstmt->fetch();
$gstmt = db()->prepare("SELECT subject, target_score, hours_per_week FROM profile_goals WHERE user_id = ?");
$gstmt->execute([$id]);
$savedGoals = [];
foreach ($gstmt as $g) {
  $savedGoals[] = [
    'subject'        => $g['subject'],
    'target_score'   => $g['target_score']   !== null ? (int)$g['target_score']   : null,
    'hours_per_week' => $g['hours_per_week'] !== null ? (int)$g['hours_per_week'] : null,
  ];
}

ok([
  'user' => [
    'id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'],
    'phone' => $user['phone'], 'role' => $user['role'], 'created_at' => $user['created_at'],
  ],
  'profile' => [
    'avatar'    => $prof['avatar']    ?? '',
    'birthdate' => $prof['birthdate'] ?? '',
    'goals'     => $savedGoals,
  ],
]);
