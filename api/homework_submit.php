<?php
// POST /homework_submit.php  { homework_id, answers: [ { question_id, value }, ... ] }
// Приём ответов + автопроверка на бэке (single/multi/text). textarea/file → ручная проверка.
// Правильные ответы клиенту НЕ возвращаются — только статус.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$hwId = (int)($in['homework_id'] ?? 0);
$answersIn = is_array($in['answers'] ?? null) ? $in['answers'] : [];
if ($hwId <= 0) fail('Не указано ДЗ', 400);

// Доступ (как в homework_get).
$h = db()->prepare(
  "SELECT hw.id, hw.level, hw.lesson_id, l.type AS lesson_type, c.id AS course_id
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
if ($hw['level'] === 'hard' && !user_has_hard_access($uid)) fail('Продвинутый уровень доступен по подписке «Стандарт»', 403);

// Карта присланных ответов: question_id → value.
$given = [];
foreach ($answersIn as $a) {
  if (isset($a['question_id'])) $given[(int)$a['question_id']] = $a['value'] ?? null;
}

// Все вопросы ДЗ (с правильными ответами — только на сервере).
$qs = db()->prepare("SELECT id, type, correct, points, optional, case_sensitive, grade_mode, COALESCE(file_limit,1) AS file_limit FROM questions WHERE homework_id = ? ORDER BY position, id");
$qs->execute([$hwId]);
$rows = $qs->fetchAll();

$isAnswered = function ($type, $v) {
  if ($type === 'single') return $v !== null && $v !== '';
  if ($type === 'multi') return is_array($v) && count($v) > 0;
  if ($type === 'file') return is_array($v) && trim((string)($v['note'] ?? '')) !== '' && count($v['files'] ?? []) > 0;
  if ($type === 'audio') return is_array($v) && count($v['files'] ?? []) === 1;
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

  if ($type === 'file') $val = canonical_file_answer($uid, $qid, $val, (int)$q['file_limit']);
  if ($type === 'audio') $val = canonical_audio_answer($uid, $qid, $val);
  $correct = $q['correct'] !== null ? json_decode($q['correct'], true) : null;
  [$ok, $earned] = grade_question($type, $correct, $val, (int)$q['points'], (int)$q['case_sensitive'] === 1, $q['grade_mode'] ?? 'full');
  if (in_array($type, ['textarea', 'file', 'audio'], true)) $hasPendingTextarea = true;
  else { $autoScore += $earned; $autoMax += (int)$q['points']; $autoCount++; }
  $score += $earned;
  $maxScore += (int)$q['points'];
  $toGrade[] = [$qid, $type, $val, $ok, $earned];
}

$status = $hasPendingTextarea ? 'review' : 'graded';

$isResubmit = false;
try {
  $pdo = db();
  $pdo->beginTransaction();
  // Пересдача: прежние попытки больше не удаляем — они нужны в аналитике ученика.
  $old = $pdo->prepare("SELECT id FROM submissions WHERE homework_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
  $old->execute([$hwId, $uid]);
  if ($old->fetchColumn()) $isResubmit = true;
  $gradedAt = $status === 'graded' ? date('Y-m-d H:i:s') : null;
  $pdo->prepare("INSERT INTO submissions (homework_id, user_id, status, score, max_score, graded_at) VALUES (?,?,?,?,?,?)")
      ->execute([$hwId, $uid, $status, $score, $maxScore, $gradedAt]);
  $subId = (int)$pdo->lastInsertId();
  $ai = $pdo->prepare("INSERT INTO answers (submission_id, question_id, value, is_correct, earned) VALUES (?,?,?,?,?)");
  foreach ($toGrade as [$qid, $type, $val, $ok, $earned]) {
    $ai->execute([$subId, $qid, json_encode($val, JSON_UNESCAPED_UNICODE), $ok === null ? null : ($ok ? 1 : 0), $earned]);
    if ($type === 'file' || $type === 'audio') bind_answer_files($uid, $qid, $val, $subId);
  }
  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось сохранить ответы', 500);
}

delete_draft($uid, 'homework', $hwId); // ответы отправлены — черновик больше не нужен

// Автостатус «урок пройден»: сдача обычного (не повышенного) ДЗ засчитывает урок,
// сколько бы баллов ни набрал ученик. Сбой отметки не должен ломать сдачу.
if ($hw['level'] !== 'hard') {
  try {
    $lid = (int)$hw['lesson_id'];
    $pending = db()->prepare("SELECT 1 FROM homeworks h
      WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
        AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.homework_id = h.id AND s.user_id = ?)
      LIMIT 1");
    $pending->execute([$lid, $uid]);
    if (!$pending->fetchColumn()) {
      if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        db()->prepare("INSERT INTO lesson_progress (user_id, lesson_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE done_at = done_at")
            ->execute([$uid, $lid]);
      } else {
        db()->prepare("INSERT OR IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)")
            ->execute([$uid, $lid]);
      }
    }
  } catch (Throwable $e) { error_log('[lesson_done] ' . $e->getMessage()); }
}

// Геймификация (Этап 9): порог 50% по авто-части (ручные типы не считаются). Нет авто-заданий → сразу.
try {
  require_once __DIR__ . '/gamify.php';
  g_touch_streak($uid);
  g_award_homework($uid, $hwId, $autoScore, $autoMax, $autoCount);
  g_sync_achievements($uid);
} catch (Throwable $e) { error_log('[gamify] ' . $e->getMessage()); }

ok(['submission_id' => $subId, 'status' => $status, 'score' => $score, 'max_score' => $maxScore]);
