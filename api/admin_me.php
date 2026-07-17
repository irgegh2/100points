<?php
// GET /admin_me.php — текущий админ по сессии или 401.
require __DIR__ . '/bootstrap.php';

$id = $_SESSION['admin_id'] ?? null;
if (!$id) fail('Нужен вход в админку', 401);

$stmt = db()->prepare("SELECT id, username FROM admins WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$admin = $stmt->fetch();
if (!$admin) fail('Нужен вход в админку', 401);

ok(['admin' => ['id' => (int)$admin['id'], 'username' => $admin['username']]]);
