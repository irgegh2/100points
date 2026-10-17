<?php
// POST JSON {id}: снимает резерв после отменённой или оборванной прямой загрузки.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_helpers.php';
$uid = require_user();
$id = (int)(body()['id'] ?? 0);
if ($id <= 0) ok(['aborted' => false]);
$item = drive_item($uid, $id, 'file');
if (!$item || ($item['upload_status'] ?? 'ready') !== 'pending') ok(['aborted' => false]);
try { storage_delete_key((string)$item['storage_key']); } catch (Throwable $e) { error_log('[drive abort] ' . $e->getMessage()); }
db()->prepare("DELETE FROM drive_items WHERE id = ? AND user_id = ? AND upload_status = 'pending'")->execute([$id, $uid]);
ok(['aborted' => true, 'quota' => drive_quota($uid)]);
