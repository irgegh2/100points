<?php
// GET /me.php — возвращает текущего пользователя по сессии или 401.
require __DIR__ . '/bootstrap.php';

$uid = $_SESSION['uid'] ?? null;
if (!$uid) fail('Не авторизован', 401);
block_if_maintenance(); // тех.работы — закрываем кабинет
release_session(); // отпускаем блокировку сессии — не держим параллельные запросы кабинета

$stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$uid]);
$user = $stmt->fetch();
if (!$user) fail('Не авторизован', 401);

ok(['user' => public_user($user)]);
