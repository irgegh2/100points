<?php
// GET /me.php — возвращает текущего пользователя по сессии или 401.
require __DIR__ . '/bootstrap.php';

$uid = $_SESSION['uid'] ?? null;
if (!$uid) fail('Не авторизован', 401);
if (!empty($_SESSION['impersonation']['expires_at']) && $_SESSION['impersonation']['expires_at'] < gmdate('Y-m-d H:i:s')) {
  unset($_SESSION['uid'], $_SESSION['impersonation']);
  fail('Время просмотра кабинета истекло', 401);
}
block_if_maintenance(); // тех.работы — закрываем кабинет
$impersonation = !empty($_SESSION['impersonation']['read_only']) ? [
  'read_only' => true,
  'expires_at' => $_SESSION['impersonation']['expires_at'] ?? null,
] : null;
release_session(); // отпускаем блокировку сессии — не держим параллельные запросы кабинета

$stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$uid]);
$user = $stmt->fetch();
if (!$user) fail('Не авторизован', 401);

ok(['user' => public_user($user), 'impersonation' => $impersonation]);
