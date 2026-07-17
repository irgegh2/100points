<?php
// GET /admin_ai_chats.php?q= — все ИИ-чаты учеников (для просмотра админом, только чтение).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$q = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT a.id, a.title, a.kind, a.created_at, u.id AS user_id, u.name AS user_name, u.email AS user_email
        FROM ai_chats a JOIN users u ON u.id = a.user_id";
$args = [];
if ($q !== '') { $sql .= " WHERE u.name LIKE ? OR u.email LIKE ? OR a.title LIKE ?"; $like = '%' . $q . '%'; $args = [$like, $like, $like]; }
$sql .= " ORDER BY a.id DESC LIMIT 300";
$st = db()->prepare($sql);
$st->execute($args);

$out = [];
foreach ($st as $r) $out[] = [
  'id' => (int)$r['id'], 'title' => $r['title'], 'kind' => $r['kind'],
  'created_at' => $r['created_at'], 'user_id' => (int)$r['user_id'],
  'user_name' => $r['user_name'], 'user_email' => $r['user_email'],
];
ok(['chats' => $out]);
