<?php
// GET /mock_result.php?id=<mock_id> — разбор пробника после сдачи (своей).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указан пробник', 400);

$sub = db()->prepare("SELECT id, status, score, max_score, comment, expert_id FROM submissions WHERE mock_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
$sub->execute([$id, $uid]);
$s = $sub->fetch();
if (!$s) fail('Сдачи ещё нет', 404);
$subId = (int)$s['id'];

$expert = null;
if (!empty($s['expert_id'])) {
  $ex = db()->prepare("SELECT last_name, first_name, university, ege_score FROM experts WHERE id = ? LIMIT 1");
  $ex->execute([(int)$s['expert_id']]);
  if ($e = $ex->fetch()) $expert = ['last_name' => $e['last_name'], 'first_name' => $e['first_name'], 'university' => $e['university'], 'ege_score' => (int)$e['ege_score']];
}

$mk = db()->prepare("SELECT id, month, title FROM mock_exams WHERE id = ? LIMIT 1");
$mk->execute([$id]);
$mock = $mk->fetch();

$q = db()->prepare(
  "SELECT q.id, q.type, q.text, q.options, q.correct, q.solution, q.points, q.optional, q.hard,
          a.value AS your_value, a.is_correct, a.earned, a.mentor_comment
   FROM answers a JOIN questions q ON q.id = a.question_id
   WHERE a.submission_id = ? ORDER BY q.position, q.id"
);
$q->execute([$subId]);
$results = [];
foreach ($q as $r) {
  $type = $r['type']; $earned = (int)$r['earned']; $pts = (int)$r['points'];
  if (in_array($type, ['textarea', 'file', 'audio'], true)) {
    $status = ($r['mentor_comment'] !== null && $r['mentor_comment'] !== '') || $r['is_correct'] !== null ? 'graded' : 'pending';
  } else {
    $status = $earned >= $pts ? 'correct' : ($earned > 0 ? 'partial' : 'wrong');
  }
  $results[] = [
    'id' => (int)$r['id'], 'type' => $type, 'text' => $r['text'],
    'images' => [], 'audio' => [], 'videos' => [], 'files' => [],
    'options' => $r['options'] !== null ? json_decode($r['options'], true) : null,
    'points' => $pts, 'optional' => (int)$r['optional'] === 1, 'hard' => (int)$r['hard'] === 1,
    'your_value' => $r['your_value'] !== null ? json_decode($r['your_value'], true) : null,
    'correct' => $r['correct'] !== null ? json_decode($r['correct'], true) : null,
    'solution' => $r['solution'], 'earned' => $earned, 'status' => $status,
    'mentor_comment' => $r['mentor_comment'],
  ];
}

// Вложения вопросов (картинки, аудио, файлы) — как в mock_get.
if ($results) {
  $byId = [];
  foreach ($results as $i => $r) $byId[$r['id']] = $i;
  $ph = implode(',', array_fill(0, count($byId), '?'));
  $at = db()->prepare("SELECT question_id, kind, name, size, url FROM question_attachments WHERE question_id IN ($ph) ORDER BY position, id");
  $at->execute(array_keys($byId));
  foreach ($at as $a) {
    $qi = $byId[(int)$a['question_id']];
    if ($a['kind'] === 'image') $results[$qi]['images'][] = $a['url'];
    elseif ($a['kind'] === 'audio') $results[$qi]['audio'][] = ['name' => $a['name'], 'size' => $a['size'], 'url' => $a['url']];
    elseif ($a['kind'] === 'video') $results[$qi]['videos'][] = ['url' => $a['url']];
    else $results[$qi]['files'][] = ['name' => $a['name'], 'size' => $a['size'], 'url' => $a['url']];
  }
}

ok([
  'homework'   => ['id' => (int)$mock['id'], 'level' => 'normal', 'title' => $mock['title'], 'deadline' => $mock['month']],
  'submission' => ['status' => $s['status'], 'score' => (int)$s['score'], 'max_score' => (int)$s['max_score'], 'comment' => $s['comment']],
  'expert'     => $expert,
  'results'    => $results,
]);
