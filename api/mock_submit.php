<?php
// POST /mock_submit.php  { mock_id, answers: [ { question_id, value } ] }
// Приём ответов пробника + автопроверка (тот же движок grade_question, что и у ДЗ).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();

$in = body();
$mockId = (int)($in['mock_id'] ?? 0);
$assignmentId = (int)($in['assignment_id'] ?? 0);
$answersIn = is_array($in['answers'] ?? null) ? $in['answers'] : [];
if ($mockId <= 0) fail('Не указан пробник', 400);

$m = db()->prepare("SELECT m.id, m.course_id, COALESCE(m.format,'normal') AS format, COALESCE(m.access_mode,'general') AS access_mode FROM mock_exams m WHERE m.id = ? LIMIT 1");
$m->execute([$mockId]);
$mk = $m->fetch();
if (!$mk) fail('Пробник не найден', 404);
$assignment = p_assignment_for_user($uid, $assignmentId, $mockId);
$assignment = $assignment && (int)$assignment['mock_id'] === $mockId ? $assignment : null;
$hasAccess = ($mk['format'] === 'proctored' || $mk['access_mode'] === 'individual') ? (bool)$assignment : user_has_course_access($uid, (int)$mk['course_id']);
if (!$hasAccess) fail('Пробник тебе не назначен', 403);
if ($mk['format'] === 'proctored') {
  if (!$assignment || (int)$assignment['id'] !== $assignmentId) fail('Нет доступа к сессии', 403);
  if (empty($assignment['camera_consent_at']) || empty($assignment['started_at'])) fail('Сначала подключи камеру', 409);
  if (p_time_state($assignment) !== 'live') fail('Сессия уже завершена или ещё не началась', 409);
}

$given = [];
foreach ($answersIn as $a) if (isset($a['question_id'])) $given[(int)$a['question_id']] = $a['value'] ?? null;

$qs = db()->prepare("SELECT id, type, correct, points, optional, case_sensitive, grade_mode, COALESCE(file_limit,1) AS file_limit FROM questions WHERE mock_id = ? ORDER BY position, id");
$qs->execute([$mockId]);
$rows = $qs->fetchAll();

$isAnswered = function ($type, $v) {
  if ($type === 'single') return $v !== null && $v !== '';
  if ($type === 'multi') return is_array($v) && count($v) > 0;
  if ($type === 'file') return is_array($v) && trim((string)($v['note'] ?? '')) !== '' && count($v['files'] ?? []) > 0;
  return is_string($v) && trim($v) !== '';
};

$toGrade = []; $score = 0; $maxScore = 0; $hasPendingTextarea = false;
$autoScore = 0; $autoMax = 0; $autoCount = 0; // авто-часть (для порога награды)
foreach ($rows as $q) {
  $qid = (int)$q['id']; $type = $q['type'];
  $val = array_key_exists($qid, $given) ? $given[$qid] : null;
  if ((int)$q['optional'] === 1 && !$isAnswered($type, $val)) continue;
  if ($type === 'file') $val = canonical_file_answer($uid, $qid, $val, (int)$q['file_limit']);
  $correct = $q['correct'] !== null ? json_decode($q['correct'], true) : null;
  [$ok, $earned] = grade_question($type, $correct, $val, (int)$q['points'], (int)$q['case_sensitive'] === 1, $q['grade_mode'] ?? 'full');
  if ($type === 'textarea' || $type === 'file') $hasPendingTextarea = true;
  else { $autoScore += $earned; $autoMax += (int)$q['points']; $autoCount++; }
  $score += $earned; $maxScore += (int)$q['points'];
  $toGrade[] = [$qid, $type, $val, $ok, $earned];
}
$status = $hasPendingTextarea ? 'review' : 'graded';

$isResubmit = false;
try {
  $pdo = db();
  $pdo->beginTransaction();
  $old = $pdo->prepare("SELECT id FROM submissions WHERE mock_id = ? AND user_id = ?");
  $old->execute([$mockId, $uid]);
  if ($oldId = $old->fetchColumn()) {
    $isResubmit = true;
    $pdo->prepare("DELETE FROM answers WHERE submission_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$oldId]);
  }
  $gradedAt = $status === 'graded' ? date('Y-m-d H:i:s') : null;
  $pdo->prepare("INSERT INTO submissions (mock_id, user_id, status, score, max_score, graded_at) VALUES (?,?,?,?,?,?)")
      ->execute([$mockId, $uid, $status, $score, $maxScore, $gradedAt]);
  $subId = (int)$pdo->lastInsertId();
  $ai = $pdo->prepare("INSERT INTO answers (submission_id, question_id, value, is_correct, earned) VALUES (?,?,?,?,?)");
  foreach ($toGrade as [$qid, $type, $val, $ok, $earned]) {
    $ai->execute([$subId, $qid, json_encode($val, JSON_UNESCAPED_UNICODE), $ok === null ? null : ($ok ? 1 : 0), $earned]);
    if ($type === 'file') bind_answer_files($uid, $qid, $val, $subId);
  }
  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось сохранить ответы', 500);
}

delete_draft($uid, 'mock', $mockId); // ответы отправлены — черновик больше не нужен
if ($mk['format'] === 'proctored' && $assignment) {
  db()->prepare("UPDATE mock_assignments SET status = 'finished', finished_at = ?, updated_at = ? WHERE id = ?")
      ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$assignment['id']]);
  p_event((int)$assignment['id'], 'session_finished', 'Ученик завершил пробник');
}

// Геймификация (Этап 9): порог 50% по авто-части. Нет авто-заданий → сразу. Жизни пробник НЕ трогает.
try {
  require_once __DIR__ . '/gamify.php';
  g_touch_streak($uid);
  g_award_mock($uid, $mockId, $autoScore, $autoMax, $autoCount);
} catch (Throwable $e) { error_log('[gamify] ' . $e->getMessage()); }

ok(['submission_id' => $subId, 'status' => $status, 'score' => $score, 'max_score' => $maxScore]);
