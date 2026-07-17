<?php
// POST /bank_check.php  { answers: [ { question_id, value }, ... ] }
// Автопроверка тренировки банка (эфемерно, без сохранения). Возвращает разбор: верно/частично/неверно,
// правильный ответ и решение. Проверяем ТОЛЬКО вопросы банка (subtopic_id задан) — чтобы через этот
// эндпоинт нельзя было вытащить правильные ответы ДЗ/пробников.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
// НЕ release_session здесь: ниже пишем прогресс (но в сессию не пишем — лок не нужен).
release_session();

$in = body();
$answersIn = is_array($in['answers'] ?? null) ? $in['answers'] : [];
if (!$answersIn) ok(['results' => [], 'score' => 0, 'max_score' => 0]);

$given = [];
foreach ($answersIn as $a) if (isset($a['question_id'])) $given[(int)$a['question_id']] = $a['value'] ?? null;

$ids = array_keys($given);
$ph = implode(',', array_fill(0, count($ids), '?'));
$qs = db()->prepare("SELECT id, type, text, options, correct, solution, points, case_sensitive, grade_mode
                     FROM questions WHERE id IN ($ph) AND subtopic_id IS NOT NULL");
$qs->execute($ids);

$driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
$results = []; $score = 0; $maxScore = 0;
$progress = []; // [question_id => correct(0/1)]
foreach ($qs as $q) {
  $qid = (int)$q['id']; $type = $q['type']; $pts = (int)$q['points'];
  $correct = $q['correct'] !== null ? json_decode($q['correct'], true) : null;
  $val = $given[$qid] ?? null;
  [$ok, $earned] = grade_question($type, $correct, $val, $pts, (int)$q['case_sensitive'] === 1, $q['grade_mode'] ?? 'full');
  $status = $earned >= $pts ? 'correct' : ($earned > 0 ? 'partial' : 'wrong');
  $score += $earned; $maxScore += $pts;
  $progress[$qid] = $earned >= $pts ? 1 : 0;
  $results[] = [
    'id' => $qid, 'type' => $type, 'text' => $q['text'],
    'options' => $q['options'] !== null ? json_decode($q['options'], true) : null,
    'points' => $pts, 'your_value' => $val, 'correct' => $correct,
    'solution' => $q['solution'], 'earned' => $earned, 'status' => $status,
  ];
}

// Сохраняем прогресс: «решена» = отвечал; correct — был ли ответ верным (перезаписываем последним).
$now = $driver === 'mysql' ? 'CURRENT_TIMESTAMP' : "datetime('now')";
$sel = db()->prepare("SELECT id FROM bank_progress WHERE user_id = ? AND question_id = ? LIMIT 1");
$updP = db()->prepare("UPDATE bank_progress SET correct = ?, updated_at = $now WHERE user_id = ? AND question_id = ?");
$insP = db()->prepare("INSERT INTO bank_progress (user_id, question_id, correct) VALUES (?,?,?)");
foreach ($progress as $qid => $c) {
  $sel->execute([$uid, $qid]);
  if ($sel->fetch()) $updP->execute([$c, $uid, $qid]);
  else $insP->execute([$uid, $qid, $c]);
}

ok(['results' => $results, 'score' => $score, 'max_score' => $maxScore]);
