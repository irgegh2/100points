<?php
// GET /ai_chat_list.php — список ИИ-чатов ученика (для раздела «ИИ-чат»).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$st = db()->prepare("SELECT id, title, kind, created_at FROM ai_chats WHERE user_id = ? ORDER BY id DESC");
$st->execute([$uid]);
$chats = [];
foreach ($st as $r) $chats[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'kind' => $r['kind'], 'created_at' => $r['created_at']];
ok(['chats' => $chats]);
