<?php
// POST /login.php  { "login": "почта или телефон", "password": "..." }
// Проверяет логин+пароль, открывает сессию.
require __DIR__ . '/bootstrap.php';

$data = body();
$login = trim($data['login'] ?? '');
$password = (string)($data['password'] ?? '');

if ($login === '' || $password === '') {
  fail('Введите логин и пароль', 400);
}

// Логин — это почта ИЛИ телефон. Телефон нормализуем, чтобы маска не мешала
// (на входе может быть '+7 (916)…', '8 916…' и т.п. — в БД хранится '+7XXXXXXXXXX').
$nphone = normalize_phone($login);
$stmt = db()->prepare("SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1");
$stmt->execute([$login, $nphone]);
$user = $stmt->fetch();

if (!verify_password($password, $user['password_hash'] ?? null) || !$user) {
  fail('Неверный логин или пароль', 401);
}

$_SESSION['uid'] = (int)$user['id'];
ok(['user' => public_user($user)]);
