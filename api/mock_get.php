<?php
// GET /mock_get.php?id=<mock_id> — вопросы пробника для прохождения (без правильных ответов).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();
release_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указан пробник', 400);

$m = db()->prepare("SELECT m.id, m.month, m.title, COALESCE(m.format,'normal') AS format, COALESCE(m.access_mode,'general') AS access_mode, c.id AS course_id, s.name AS subject
                    FROM mock_exams m JOIN courses c ON c.id = m.course_id
                    JOIN subjects s ON s.id = c.subject_id WHERE m.id = ? LIMIT 1");
$m->execute([$id]);
$mock = $m->fetch();
if (!$mock) fail('Пробник не найден', 404);

$assignment = p_assignment_for_user($uid, 0, $id);
$hasAccess = ($mock['format'] === 'proctored' || $mock['access_mode'] === 'individual') ? (bool)$assignment : user_has_course_access($uid, (int)$mock['course_id']);
if (!$hasAccess) {
  ok(['locked' => 'course', 'homework' => ['id' => (int)$mock['id'], 'level' => 'normal', 'title' => $mock['title'], 'deadline' => $mock['month'], 'subject' => $mock['subject']]]);
}
if ($mock['format'] === 'proctored') {
  ok(['locked' => 'proctored', 'assignment_id' => (int)$assignment['id'], 'homework' => ['id' => (int)$mock['id'], 'level' => 'normal', 'title' => $mock['title'], 'deadline' => $mock['month'], 'subject' => $mock['subject']]]);
}

$qs = db()->prepare("SELECT id, type, text, options, points, optional, hard FROM questions WHERE mock_id = ? ORDER BY position, id");
$qs->execute([$id]);
$questions = [];
foreach ($qs as $q) {
  $questions[] = [
    'id'       => (int)$q['id'],
    'type'     => $q['type'],
    'text'     => $q['text'],
    'options'  => $q['options'] !== null ? json_decode($q['options'], true) : null,
    'points'   => (int)$q['points'],
    'optional' => (int)$q['optional'] === 1,
    'hard'     => (int)$q['hard'] === 1,
  ];
}

$sub = db()->prepare("SELECT status, score, max_score FROM submissions WHERE mock_id = ? AND user_id = ? LIMIT 1");
$sub->execute([$id, $uid]);
$s = $sub->fetch();

ok([
  'locked'     => false,
  'homework'   => ['id' => (int)$mock['id'], 'level' => 'normal', 'title' => $mock['title'], 'deadline' => $mock['month'], 'subject' => $mock['subject']],
  'questions'  => $questions,
  'submission' => $s ? ['status' => $s['status'], 'score' => (int)$s['score'], 'max_score' => (int)$s['max_score']] : null,
  'draft'      => $s ? null : load_draft($uid, 'mock', $id),
]);
