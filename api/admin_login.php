<?php
// POST /admin_login.php  { "username": "...", "password": "..." }
require __DIR__ . '/bootstrap.php';

$data = body();
$username = trim($data['username'] ?? '');
$password = (string)($data['password'] ?? '');

if ($username === '' || $password === '') {
  fail('Введите логин и пароль', 400);
}

$stmt = db()->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$admin = $stmt->fetch();

if (!verify_password($password, $admin['password_hash'] ?? null) || !$admin) {
  fail('Неверный логин или пароль', 401);
}

$_SESSION['admin_id'] = (int)$admin['id'];
ok(['admin' => ['id' => (int)$admin['id'], 'username' => $admin['username']]]);
