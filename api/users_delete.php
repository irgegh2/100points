<?php
// POST /users_delete.php  { id }
// Админ удаляет ученика вместе с его профилем и целями. Только для админа.
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/delete_helpers.php';
require_admin();

$data = body();
$id = (int)($data['id'] ?? 0);
if ($id <= 0) fail('Не указан ученик', 400);

$stmt = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
if (!$stmt->fetch()) fail('Ученик не найден', 404);

try {
  $pdo = db();
  $pdo->beginTransaction();
  delete_root_row($pdo, 'users', $id);
  $pdo->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  error_log('[user delete] #' . $id . ': ' . $e->getMessage());
  fail('Удаление остановлено: связанные данные не удалось безопасно очистить', 409);
}

ok(['deleted' => $id]);
