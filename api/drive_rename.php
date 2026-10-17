<?php
// POST /drive_rename.php {id,name}
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$in = body();
$id = (int)($in['id'] ?? 0);
$item = drive_item($uid, $id);
if (!$item) fail('Файл или папка не найдены', 404);
$name = drive_clean_name((string)($in['name'] ?? ''), ($item['kind'] ?? '') === 'folder' ? 120 : 240);
if (($item['kind'] ?? '') === 'file' && !drive_allowed_extension($name)) fail('Это расширение файла запрещено', 400);
db()->prepare("UPDATE drive_items SET name = ?, updated_at = ? WHERE id = ? AND user_id = ?")
    ->execute([$name, date('Y-m-d H:i:s'), $id, $uid]);
$updated = drive_item($uid, $id);
ok(['item' => drive_public_item($updated)]);
