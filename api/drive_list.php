<?php
// GET /drive_list.php?parent_id=…&q=… — содержимое папки или поиск по всему диску.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();

$parentId = max(0, (int)($_GET['parent_id'] ?? 0));
$query = trim((string)($_GET['q'] ?? ''));
drive_require_parent($uid, $parentId);

if ($query !== '') {
  $st = db()->prepare("SELECT * FROM drive_items WHERE user_id = ? AND (upload_status IS NULL OR upload_status = 'ready') AND name LIKE ?
                       ORDER BY CASE WHEN kind = 'folder' THEN 0 ELSE 1 END, name, id DESC LIMIT 250");
  $st->execute([$uid, '%' . mb_substr($query, 0, 100) . '%']);
} elseif ($parentId > 0) {
  $st = db()->prepare("SELECT * FROM drive_items WHERE user_id = ? AND (upload_status IS NULL OR upload_status = 'ready') AND parent_id = ?
                       ORDER BY CASE WHEN kind = 'folder' THEN 0 ELSE 1 END, name, id DESC");
  $st->execute([$uid, $parentId]);
} else {
  $st = db()->prepare("SELECT * FROM drive_items WHERE user_id = ? AND (upload_status IS NULL OR upload_status = 'ready') AND parent_id IS NULL
                       ORDER BY CASE WHEN kind = 'folder' THEN 0 ELSE 1 END, name, id DESC");
  $st->execute([$uid]);
}

$folderSizes = drive_folder_sizes($uid);
$items = [];
foreach ($st->fetchAll() as $row) {
  $item = drive_public_item($row);
  if ($item['kind'] === 'folder') $item['size_bytes'] = (int)($folderSizes[$item['id']] ?? 0);
  $items[] = $item;
}
ok([
  'items' => $items,
  'breadcrumbs' => drive_breadcrumbs($uid, $parentId),
  'quota' => drive_quota($uid),
  'search' => $query,
]);
