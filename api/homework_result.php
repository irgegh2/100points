<?php
// GET /homework_result.php?id=<homework_id> — разбор после сдачи (своей).
// ЗДЕСЬ (и только здесь) отдаём правильные ответы, решения, баллы и комментарии наставника.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указано ДЗ', 400);

$sub = db()->prepare("SELECT id, status, score, max_score, comment, expert_id FROM submissions WHERE homework_id = ? AND user_id = ? LIMIT 1");
$sub->execute([$id, $uid]);
$s = $sub->fetch();
if (!$s) fail('Сдачи ещё нет', 404);
$subId = (int)$s['id'];

// Эксперт, проверивший работу (если развёрнутые ответы уже проверены).
$expert = null;
if (!empty($s['expert_id'])) {
  $ex = db()->prepare("SELECT last_name, first_name, university, ege_score FROM experts WHERE id = ? LIMIT 1");
  $ex->execute([(int)$s['expert_id']]);
  if ($e = $ex->fetch()) $expert = [
    'last_name' => $e['last_name'], 'first_name' => $e['first_name'],
    'university' => $e['university'], 'ege_score' => (int)$e['ege_score'],
  ];
}

$hw = db()->prepare("SELECT id, level, title, deadline FROM homeworks WHERE id = ? LIMIT 1");
$hw->execute([$id]);
$homework = $hw->fetch();

// Вопросы + ответы ученика (только те, что есть в сдаче).
$q = db()->prepare(
  "SELECT q.id, q.type, q.text, q.options, q.correct, q.solution, q.points, q.optional, q.hard,
          a.value AS your_value, a.is_correct, a.earned, a.mentor_comment
   FROM answers a JOIN questions q ON q.id = a.question_id
   WHERE a.submission_id = ?
   ORDER BY q.position, q.id"
);
$q->execute([$subId]);

$results = [];
foreach ($q as $r) {
  $type = $r['type'];
  $earned = (int)$r['earned']; $pts = (int)$r['points'];
  if ($type === 'textarea') {
    $status = ($r['mentor_comment'] !== null && $r['mentor_comment'] !== '') || $r['is_correct'] !== null ? 'graded' : 'pending';
  } else {
    // По набранным баллам: полный балл — верно, часть — частично, ноль — неверно.
    $status = $earned >= $pts ? 'correct' : ($earned > 0 ? 'partial' : 'wrong');
  }
  $results[] = [
    'id'             => (int)$r['id'],
    'type'           => $type,
    'text'           => $r['text'],
    'options'        => $r['options'] !== null ? json_decode($r['options'], true) : null,
    'points'         => (int)$r['points'],
    'optional'       => (int)$r['optional'] === 1,
    'hard'           => (int)$r['hard'] === 1,
    'your_value'     => $r['your_value'] !== null ? json_decode($r['your_value'], true) : null,
    'correct'        => $r['correct'] !== null ? json_decode($r['correct'], true) : null,
    'solution'       => $r['solution'],
    'earned'         => (int)$r['earned'],
    'status'         => $status,
    'mentor_comment' => $r['mentor_comment'],
  ];
}

ok([
  'homework'   => ['id' => (int)$homework['id'], 'level' => $homework['level'], 'title' => $homework['title'], 'deadline' => g_effective_deadline($uid, $homework['deadline'], (int)$homework['id'])],
  'submission' => ['status' => $s['status'], 'score' => (int)$s['score'], 'max_score' => (int)$s['max_score'], 'comment' => $s['comment']],
  'expert'     => $expert,
  'results'    => $results,
]);
