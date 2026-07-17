<?php
// POST /bank_session.php — собрать тренировку из банка (Этап 7). Возвращает вопросы БЕЗ ответов.
//   { mode:'topic',   subject_id, topic_id, count }                  — N случайных по номеру задания
//   { mode:'custom',  subject_id, counts:{ subtopic_id: n, ... } }   — своя тренировка по подтемам
//   { mode:'variant', subject_id, variant_id }                       — пробник: вопросы варианта по порядку
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$in = body();
$mode = trim($in['mode'] ?? '');
$subjectId = (int)($in['subject_id'] ?? 0);

// Доступ: предмет должен быть среди доступных ученику (есть курс).
$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($subjectId, $allowed, true)) fail('Нет доступа к предмету', 403);

$rand = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'RAND()' : 'RANDOM()';
$onlyUnsolved = !empty($in['only_unsolved']);
// Условие «только нерешённые» (вопрос ещё не в bank_progress этого ученика).
$unsolvedSql = $onlyUnsolved ? " AND q.id NOT IN (SELECT question_id FROM bank_progress WHERE user_id = ?)" : '';
$ids = [];

if ($mode === 'topic') {
  $topicId = (int)($in['topic_id'] ?? 0);
  $count = max(1, min(100, (int)($in['count'] ?? 10)));
  if ($topicId <= 0) fail('Не указан номер задания', 400);
  $args = [$topicId, $subjectId];
  if ($onlyUnsolved) $args[] = $uid;
  $st = db()->prepare("SELECT q.id FROM questions q JOIN subtopics st ON st.id = q.subtopic_id
                       JOIN topics t ON t.id = st.topic_id
                       WHERE t.id = ? AND t.subject_id = ?$unsolvedSql ORDER BY $rand LIMIT $count");
  $st->execute($args);
  $ids = $st->fetchAll(PDO::FETCH_COLUMN);
} elseif ($mode === 'custom') {
  $counts = is_array($in['counts'] ?? null) ? $in['counts'] : [];
  foreach ($counts as $stId => $n) {
    $n = max(0, min(100, (int)$n));
    if ($n <= 0) continue;
    $args = [(int)$stId, $subjectId];
    if ($onlyUnsolved) $args[] = $uid;
    $st = db()->prepare("SELECT q.id FROM questions q JOIN subtopics st ON st.id = q.subtopic_id
                         JOIN topics t ON t.id = st.topic_id
                         WHERE st.id = ? AND t.subject_id = ?$unsolvedSql ORDER BY $rand LIMIT $n");
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $qid) $ids[] = $qid;
  }
} elseif ($mode === 'variant') {
  $variantId = (int)($in['variant_id'] ?? 0);
  if ($variantId <= 0) fail('Не указан вариант', 400);
  $st = db()->prepare("SELECT vq.question_id FROM variant_questions vq
                       JOIN bank_variants v ON v.id = vq.variant_id
                       WHERE vq.variant_id = ? AND v.subject_id = ? ORDER BY vq.position, vq.id");
  $st->execute([$variantId, $subjectId]);
  $ids = $st->fetchAll(PDO::FETCH_COLUMN);
} else {
  fail('Неизвестный режим', 400);
}

if (!$ids) ok(['questions' => []]);

// Грузим вопросы БЕЗ correct/solution (правильные ответы клиенту до проверки не отдаём).
$ph = implode(',', array_fill(0, count($ids), '?'));
$qs = db()->prepare("SELECT id, type, text, options, points, difficulty FROM questions WHERE id IN ($ph)");
$qs->execute($ids);
$byId = [];
foreach ($qs as $q) $byId[(int)$q['id']] = [
  'id' => (int)$q['id'], 'type' => $q['type'], 'text' => $q['text'],
  'options' => $q['options'] !== null ? json_decode($q['options'], true) : null,
  'points' => (int)$q['points'], 'difficulty' => $q['difficulty'] !== null ? (int)$q['difficulty'] : null,
];
// Сохраняем порядок $ids (для variant важен).
$questions = [];
foreach ($ids as $qid) if (isset($byId[(int)$qid])) $questions[] = $byId[(int)$qid];

ok(['questions' => $questions]);
