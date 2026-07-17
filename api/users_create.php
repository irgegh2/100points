<?php
// POST /users_create.php  { "name": "...", "email": "...", "phone": "...", "password": "..." }
// Создаёт аккаунт ученика. Только для админа. Это единственный способ "регистрации".
require __DIR__ . '/bootstrap.php';
require_admin();

$data = body();
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = clean_phone($data['phone'] ?? ''); // храним +7XXXXXXXXXX; неполный → ошибка
$password = (string)($data['password'] ?? '');

// Нужны почта или телефон + пароль.
if (($email === '' && $phone === '') || $password === '') {
  fail('Укажите почту или телефон и пароль', 400);
}
if (strlen($password) < 4) {
  fail('Пароль слишком короткий (минимум 4 символа)', 400);
}

// Проверка на занятость почты/телефона.
$check = db()->prepare("SELECT id FROM users WHERE (email <> '' AND email = ?) OR (phone <> '' AND phone = ?) LIMIT 1");
$check->execute([$email, $phone]);
if ($check->fetch()) {
  fail('Пользователь с такой почтой или телефоном уже есть', 409);
}

try {
  $stmt = db()->prepare("INSERT INTO users (name, email, phone, password_hash, role) VALUES (?,?,?,?,'student')");
  $stmt->execute([
    $name,
    $email !== '' ? $email : null,
    $phone !== '' ? $phone : null,
    password_hash($password, PASSWORD_DEFAULT),
  ]);
} catch (Exception $e) {
  fail('Не удалось создать аккаунт (возможно, дубликат)', 409);
}

$id = (int)db()->lastInsertId();
$stmt = db()->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?");
$stmt->execute([$id]);
ok(['user' => $stmt->fetch()], 201);
