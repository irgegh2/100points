<?php
// GET /admin_leads.php?status=&kind= — заявки для админа: лиды лендинга, запросы тарифа, эскалации из ИИ-помощи.
require __DIR__ . '/bootstrap.php';
require_admin();

$status = trim((string)($_GET['status'] ?? ''));
$kind   = trim((string)($_GET['kind'] ?? ''));

$sql = "SELECT l.id, l.kind, l.name, l.phone, l.grade, l.user_id, l.plan, l.message, l.source,
               l.status, l.note, l.created_at, u.name AS user_name, u.email AS user_email
        FROM leads l LEFT JOIN users u ON u.id = l.user_id";
$where = []; $args = [];
if (in_array($status, ['new', 'done', 'rejected'], true)) { $where[] = 'l.status = ?'; $args[] = $status; }
if (in_array($kind, ['lead', 'subscription', 'support'], true)) { $where[] = 'l.kind = ?'; $args[] = $kind; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY l.id DESC LIMIT 500';

$st = db()->prepare($sql);
$st->execute($args);
$items = [];
foreach ($st as $r) $items[] = [
  'id'         => (int)$r['id'],
  'kind'       => $r['kind'],
  'name'       => $r['name'],
  'phone'      => $r['phone'],
  'grade'      => $r['grade'],
  'user_id'    => $r['user_id'] !== null ? (int)$r['user_id'] : null,
  'user_name'  => $r['user_name'],
  'user_email' => $r['user_email'],
  'plan'       => $r['plan'],
  'message'    => $r['message'],
  'source'     => $r['source'],
  'status'     => $r['status'],
  'note'       => $r['note'],
  'created_at' => $r['created_at'],
];

$new = (int)db()->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
ok(['items' => $items, 'new' => $new]);
