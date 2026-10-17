<?php
// POST /drive_folder_create.php {name,parent_id?}
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$in = body();
$parentId = max(0, (int)($in['parent_id'] ?? 0));
drive_require_parent($uid, $parentId);
$name = drive_clean_name((string)($in['name'] ?? ''), 120);

$st = db()->prepare("INSERT INTO drive_items (user_id, parent_id, kind, name, size_bytes, updated_at)
                     VALUES (?,?, 'folder', ?, 0, ?)");
$st->execute([$uid, $parentId > 0 ? $parentId : null, $name, date('Y-m-d H:i:s')]);
$item = drive_item($uid, (int)db()->lastInsertId());
ok(['item' => drive_public_item($item)], 201);
