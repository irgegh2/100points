<?php
// GET /admin_user_chats.php?user_id=<id> — список ИИ-чатов ученика (для админа).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$uid = (int)($_GET['user_id'] ?? 0);
if ($uid <= 0) fail('Не указан ученик', 400);

$st = db()->prepare("SELECT id, title, kind, created_at FROM ai_chats WHERE user_id = ? ORDER BY id DESC");
$st->execute([$uid]);
$chats = [];
foreach ($st as $r) $chats[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'kind' => $r['kind'], 'created_at' => $r['created_at']];
ok(['chats' => $chats]);
