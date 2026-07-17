<?php
// POST /admin_lead_update.php  { id, status?, note?, delete? } — обработка заявки админом.
require __DIR__ . '/bootstrap.php';
require_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Не указана заявка', 400);

if (!empty($in['delete'])) {
  db()->prepare("DELETE FROM leads WHERE id = ?")->execute([$id]);
  ok(['deleted' => $id]);
}

$fields = []; $args = [];
if (isset($in['status']) && in_array($in['status'], ['new', 'done', 'rejected'], true)) {
  $fields[] = 'status = ?'; $args[] = $in['status'];
}
if (array_key_exists('note', $in)) {
  $fields[] = 'note = ?'; $args[] = mb_substr(trim((string)$in['note']), 0, 500);
}
if (!$fields) fail('Нечего обновлять', 400);

$args[] = $id;
db()->prepare("UPDATE leads SET " . implode(', ', $fields) . " WHERE id = ?")->execute($args);
$row = db()->prepare("SELECT * FROM leads WHERE id = ?");
$row->execute([$id]);
ok(['item' => $row->fetch()]);
