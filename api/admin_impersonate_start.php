<?php
// POST /admin_impersonate_start.php { user_id } — одноразовая ссылка на кабинет ученика.
require __DIR__ . '/bootstrap.php';
$adminId = require_admin();
$in = body();
$uid = (int)($in['user_id'] ?? 0);
if ($uid <= 0) fail('Не указан ученик', 400);
$user = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$user->execute([$uid]);
if (!$user->fetchColumn()) fail('Ученик не найден', 404);

$token = bin2hex(random_bytes(32));
$hash = hash('sha256', $token);
$expires = gmdate('Y-m-d H:i:s', time() + 300);
$pdo = db();
$pdo->prepare("DELETE FROM admin_impersonation_tokens WHERE expires_at < ? OR used_at IS NOT NULL")
    ->execute([gmdate('Y-m-d H:i:s')]);
$pdo->prepare("INSERT INTO admin_impersonation_tokens (token_hash, admin_id, user_id, expires_at) VALUES (?,?,?,?)")
    ->execute([$hash, $adminId, $uid, $expires]);

ok(['url' => 'https://lk.100-points.ru/api/impersonate.php?token=' . rawurlencode($token), 'expires_in' => 300]);
