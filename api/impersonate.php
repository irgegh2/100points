<?php
// Одноразовый вход администратора в кабинет ученика. Сессия строго read-only.
require __DIR__ . '/bootstrap.php';
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) fail('Ссылка просмотра недействительна', 400);
$hash = hash('sha256', $token);
$now = gmdate('Y-m-d H:i:s');
$st = db()->prepare("SELECT id, admin_id, user_id, expires_at, used_at FROM admin_impersonation_tokens WHERE token_hash = ? LIMIT 1");
$st->execute([$hash]);
$row = $st->fetch();
if (!$row || $row['used_at'] || $row['expires_at'] < $now) fail('Ссылка просмотра истекла или уже использована', 410);

db()->prepare("UPDATE admin_impersonation_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL")
    ->execute([$now, (int)$row['id']]);
session_regenerate_id(true);
$_SESSION['uid'] = (int)$row['user_id'];
$_SESSION['impersonation'] = [
  'admin_id' => (int)$row['admin_id'],
  'read_only' => true,
  'expires_at' => gmdate('Y-m-d H:i:s', time() + 4 * 3600),
];
header('Location: https://lk.100-points.ru/#home', true, 302);
exit;
