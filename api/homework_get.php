<?php
// GET /homework_get.php?id=<homework_id> — вопросы ДЗ для прохождения.
// БЕЗ правильных ответов и решений (они отдаются только в homework_result после сдачи).
// Гейтинг: нужен доступ к курсу (зачисление); повышенный уровень — ещё и подписка Стандарт+.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указано ДЗ', 400);

$h = db()->prepare(
  "SELECT hw.id, hw.level, hw.title, hw.deadline, c.id AS course_id, s.name AS subject, l.title AS lesson_title
   FROM homeworks hw
   JOIN lessons l ON l.id = hw.lesson_id
   JOIN blocks b ON b.id = l.block_id
   JOIN courses c ON c.id = b.course_id
   JOIN subjects s ON s.id = c.subject_id
   WHERE hw.id = ? LIMIT 1"
);
$h->execute([$id]);
$hw = $h->fetch();
if (!$hw) fail('ДЗ не найдено', 404);

$meta = ['id' => (int)$hw['id'], 'level' => $hw['level'], 'title' => $hw['title'], 'deadline' => g_effective_deadline($uid, $hw['deadline'], (int)$hw['id']), 'subject' => $hw['subject']];

if (!user_has_course_access($uid, (int)$hw['course_id'])) ok(['locked' => 'course', 'homework' => $meta]);
if ($hw['level'] === 'hard' && !user_has_hard_access($uid)) ok(['locked' => 'subscription', 'homework' => $meta]);

// Вопросы без correct/solution.
$qs = db()->prepare("SELECT id, type, text, options, points, optional, hard FROM questions WHERE homework_id = ? ORDER BY position, id");
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

// Уже сдавал? — чтобы фронт сразу открыл результат.
$sub = db()->prepare("SELECT status, score, max_score FROM submissions WHERE homework_id = ? AND user_id = ? LIMIT 1");
$sub->execute([$id, $uid]);
$s = $sub->fetch();

ok([
  'locked'     => false,
  'homework'   => $meta,
  'questions'  => $questions,
  'submission' => $s ? ['status' => $s['status'], 'score' => (int)$s['score'], 'max_score' => (int)$s['max_score']] : null,
  'draft'      => $s ? null : load_draft($uid, 'homework', $id), // черновик нужен только до сдачи
]);
