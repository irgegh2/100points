<?php
// GET /admin_purchases.php?status=pending|done|canceled — заявки на выдачу (для админа).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$status = (string)($_GET['status'] ?? '');
$sql = "SELECT p.id, p.item_name, p.price, p.status, p.note, p.created_at,
               u.id AS user_id, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
        FROM purchases p JOIN users u ON u.id = p.user_id";
$args = [];
if (in_array($status, ['pending', 'done', 'canceled'], true)) { $sql .= " WHERE p.status = ?"; $args[] = $status; }
$sql .= " ORDER BY p.id DESC";
$st = db()->prepare($sql);
$st->execute($args);

$out = [];
foreach ($st as $r) $out[] = [
  'id' => (int)$r['id'], 'item_name' => $r['item_name'], 'price' => (int)$r['price'],
  'status' => $r['status'], 'note' => $r['note'], 'created_at' => $r['created_at'],
  'user_id' => (int)$r['user_id'], 'user_name' => $r['user_name'],
  'user_email' => $r['user_email'], 'user_phone' => $r['user_phone'],
];
// Счётчик ожидающих — для бейджа.
$pending = (int)db()->query("SELECT COUNT(*) FROM purchases WHERE status = 'pending'")->fetchColumn();
ok(['purchases' => $out, 'pending' => $pending]);
