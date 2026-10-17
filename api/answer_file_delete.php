<?php
// POST { upload_id } — удалить файл из ещё не сданного ответа.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
$in = body();
$id = (int)($in['upload_id'] ?? 0);
if ($id <= 0) fail('Не указан файл', 400);

$st = db()->prepare("SELECT id, url FROM answer_uploads WHERE id = ? AND user_id = ? AND submission_id IS NULL LIMIT 1");
$st->execute([$id, $uid]);
$row = $st->fetch();
if (!$row) fail('Файл уже отправлен или не найден', 404);

db()->prepare("DELETE FROM answer_uploads WHERE id = ? AND user_id = ? AND submission_id IS NULL")->execute([$id, $uid]);
$key = storage_key_from_url((string)$row['url']);
if ($key !== null && str_starts_with($key, 'answers/u' . $uid . '/')) storage_delete_url((string)$row['url']);
ok(['deleted' => true]);
