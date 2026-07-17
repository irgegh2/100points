<?php
// POST /users_delete.php  { id }
// Админ удаляет ученика вместе с его профилем и целями. Только для админа.
require __DIR__ . '/bootstrap.php';
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
  // Сначала зависимые строки (FK), потом сам аккаунт.
  // Сдачи ученика + ответы по ним (иначе осиротевшие записи копятся в БД).
  $subs = $pdo->prepare("SELECT id FROM submissions WHERE user_id = ?");
  $subs->execute([$id]);
  foreach ($subs->fetchAll(PDO::FETCH_COLUMN) as $sid) {
    $pdo->prepare("DELETE FROM answers WHERE submission_id = ?")->execute([$sid]);
  }
  $pdo->prepare("DELETE FROM submissions WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM enrollments WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM subscriptions WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM profile_goals WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM profiles WHERE user_id = ?")->execute([$id]);
  // Этапы 7–10: банк/тренажёр/черновики/геймификация/магазин/ИИ-чаты — чтобы не упереться в FK.
  foreach (['answer_drafts', 'bank_progress', 'trainer_attempts', 'trainer_qa_generated',
            'wallets', 'coin_log', 'user_achievements', 'deadline_penalties', 'purchases'] as $t) {
    try { $pdo->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$id]); } catch (Exception $e) { /* таблицы может не быть на старой БД */ }
  }
  try {
    $pdo->prepare("DELETE FROM ai_messages WHERE chat_id IN (SELECT id FROM ai_chats WHERE user_id = ?)")->execute([$id]);
    $pdo->prepare("DELETE FROM ai_chats WHERE user_id = ?")->execute([$id]);
  } catch (Exception $e) { /* нет таблиц ИИ-чатов */ }
  $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось удалить ученика', 500);
}

ok(['deleted' => $id]);
