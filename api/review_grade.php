<?php
// POST /review_grade.php  { submission_id, grades: [ { answer_id, points, comment }, ... ] }
// Наставник выставляет баллы и комментарии за развёрнутые ответы. Пересчитывает итог,
// статус → graded, назначает СЛУЧАЙНОГО эксперта (для ощущения живой проверки). Этап 5.
require __DIR__ . '/bootstrap.php';
require_admin();

$in = body();
$subId = (int)($in['submission_id'] ?? 0);
$grades = is_array($in['grades'] ?? null) ? $in['grades'] : [];
if ($subId <= 0) fail('Не указана сдача', 400);

$s = db()->prepare("SELECT id, status FROM submissions WHERE id = ? LIMIT 1");
$s->execute([$subId]);
if (!$s->fetch()) fail('Сдача не найдена', 404);

try {
  $pdo = db();
  $pdo->beginTransaction();

  // Обновляем оценённые развёрнутые ответы. Баллы режем в [0, points] этого вопроса.
  $upd = $pdo->prepare("UPDATE answers SET earned = ?, is_correct = ?, mentor_comment = ? WHERE id = ? AND submission_id = ?");
  foreach ($grades as $g) {
    $aid = (int)($g['answer_id'] ?? 0);
    if ($aid <= 0) continue;
    $pts = $pdo->prepare("SELECT q.points FROM answers a JOIN questions q ON q.id = a.question_id WHERE a.id = ? AND a.submission_id = ? AND q.type IN ('textarea','file')");
    $pts->execute([$aid, $subId]);
    $maxRaw = $pts->fetchColumn();
    if ($maxRaw === false) continue;
    $maxPts = (int)$maxRaw;
    $earned = max(0, min($maxPts, (int)($g['points'] ?? 0)));
    $comment = mb_substr(trim((string)($g['comment'] ?? '')), 0, 50000);
    $upd->execute([$earned, $earned > 0 ? 1 : 0, $comment !== '' ? $comment : null, $aid, $subId]);
  }

  // Пересчёт итогового балла = сумма earned по всем ответам сдачи.
  $sum = $pdo->prepare("SELECT COALESCE(SUM(earned),0) FROM answers WHERE submission_id = ?");
  $sum->execute([$subId]);
  $score = (int)$sum->fetchColumn();

  // Случайный эксперт.
  $expId = (int)($pdo->query("SELECT id FROM experts ORDER BY " . (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'RAND()' : 'RANDOM()') . " LIMIT 1")->fetchColumn() ?: 0);

  $pdo->prepare("UPDATE submissions SET status = 'graded', score = ?, expert_id = ?, graded_at = ? WHERE id = ?")
      ->execute([$score, $expId ?: null, date('Y-m-d H:i:s'), $subId]);

  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось сохранить проверку', 500);
}

// Уведомление ученику о проверке (Этап 13).
try {
  $r = db()->prepare("SELECT user_id, homework_id, mock_id, max_score FROM submissions WHERE id = ?");
  $r->execute([$subId]);
  if ($row = $r->fetch()) {
    $isHomework = !empty($row['homework_id']);
    $kind = $isHomework ? 'homework' : 'mock';
    $message = $isHomework
      ? "Домашняя работа проверена: {$score} из {$row['max_score']} баллов"
      : "Пробник проверен: {$score} из {$row['max_score']} баллов";
    notify((int)$row['user_id'], 'review', $message, $kind);
  }
} catch (Throwable $e) { error_log('[notify] ' . $e->getMessage()); }

ok(['submission_id' => $subId, 'status' => 'graded', 'score' => $score, 'expert_id' => $expId]);
