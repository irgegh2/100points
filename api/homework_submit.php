<?php
// POST /homework_submit.php  { homework_id, answers: [ { question_id, value }, ... ] }
// Приём ответов + автопроверка на бэке (single/multi/text). textarea → ручная проверка (status review).
// Правильные ответы клиенту НЕ возвращаются — только статус.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$hwId = (int)($in['homework_id'] ?? 0);
$answersIn = is_array($in['answers'] ?? null) ? $in['answers'] : [];
if ($hwId <= 0) fail('Не указано ДЗ', 400);

// Доступ (как в homework_get).
$h = db()->prepare(
  "SELECT hw.id, hw.level, c.id AS course_id
   FROM homeworks hw
   JOIN lessons l ON l.id = hw.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   WHERE hw.id = ? LIMIT 1"
);
$h->execute([$hwId]);
$hw = $h->fetch();
if (!$hw) fail('ДЗ не найдено', 404);
if (!user_has_course_access($uid, (int)$hw['course_id'])) fail('Нет доступа к курсу', 403);
if ($hw['level'] === 'hard' && !user_has_hard_access($uid)) fail('Повышенный уровень доступен по подписке «Стандарт»', 403);

// Карта присланных ответов: question_id → value.
$given = [];
foreach ($answersIn as $a) {
  if (isset($a['question_id'])) $given[(int)$a['question_id']] = $a['value'] ?? null;
}

// Все вопросы ДЗ (с правильными ответами — только на сервере).
$qs = db()->prepare("SELECT id, type, correct, points, optional, case_sensitive, grade_mode FROM questions WHERE homework_id = ? ORDER BY position, id");
$qs->execute([$hwId]);
$rows = $qs->fetchAll();

$isAnswered = function ($type, $v) {
  if ($type === 'single') return $v !== null && $v !== '';
  if ($type === 'multi') return is_array($v) && count($v) > 0;
  return is_string($v) && trim($v) !== '';
};

$toGrade = [];   // [question_id, type, value, is_correct, earned]
$score = 0; $maxScore = 0; $hasPendingTextarea = false;
$autoScore = 0; $autoMax = 0; $autoCount = 0; // авто-проверяемая часть (для порога награды)
foreach ($rows as $q) {
  $qid = (int)$q['id'];
  $type = $q['type'];
  $answered = array_key_exists($qid, $given);
  $val = $answered ? $given[$qid] : null;
  // Необязательный без ответа — пропускаем целиком (не входит в баллы).
  if ((int)$q['optional'] === 1 && !$isAnswered($type, $val)) continue;

  $correct = $q['correct'] !== null ? json_decode($q['correct'], true) : null;
  [$ok, $earned] = grade_question($type, $correct, $val, (int)$q['points'], (int)$q['case_sensitive'] === 1, $q['grade_mode'] ?? 'full');
  if ($type === 'textarea') $hasPendingTextarea = true;
  else { $autoScore += $earned; $autoMax += (int)$q['points']; $autoCount++; }
  $score += $earned;
  $maxScore += (int)$q['points'];
  $toGrade[] = [$qid, $val, $ok, $earned];
}

$status = $hasPendingTextarea ? 'review' : 'graded';

$isResubmit = false;
try {
  $pdo = db();
  $pdo->beginTransaction();
  // Пересдача: удаляем прежнюю сдачу с ответами.
  $old = $pdo->prepare("SELECT id FROM submissions WHERE homework_id = ? AND user_id = ?");
  $old->execute([$hwId, $uid]);
  if ($oldId = $old->fetchColumn()) {
    $isResubmit = true;
    $pdo->prepare("DELETE FROM answers WHERE submission_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$oldId]);
  }
  $gradedAt = $status === 'graded' ? date('Y-m-d H:i:s') : null;
  $pdo->prepare("INSERT INTO submissions (homework_id, user_id, status, score, max_score, graded_at) VALUES (?,?,?,?,?,?)")
      ->execute([$hwId, $uid, $status, $score, $maxScore, $gradedAt]);
  $subId = (int)$pdo->lastInsertId();
  $ai = $pdo->prepare("INSERT INTO answers (submission_id, question_id, value, is_correct, earned) VALUES (?,?,?,?,?)");
  foreach ($toGrade as [$qid, $val, $ok, $earned]) {
    $ai->execute([$subId, $qid, json_encode($val, JSON_UNESCAPED_UNICODE), $ok === null ? null : ($ok ? 1 : 0), $earned]);
  }
  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось сохранить ответы', 500);
}

delete_draft($uid, 'homework', $hwId); // ответы отправлены — черновик больше не нужен

// Геймификация (Этап 9): порог 50% по авто-части (textarea не считается). Нет авто-заданий → сразу.
try {
  require_once __DIR__ . '/gamify.php';
  g_touch_streak($uid);
  g_award_homework($uid, $hwId, $autoScore, $autoMax, $autoCount);
} catch (Throwable $e) { error_log('[gamify] ' . $e->getMessage()); }

ok(['submission_id' => $subId, 'status' => $status, 'score' => $score, 'max_score' => $maxScore]);
