<?php
// POST /lesson_progress_set.php  { lesson_id, done } — ученик отмечает урок пройденным / снимает отметку.
// Для фильтра «Скрыть пройденное» в программе курса. Доступно только при доступе к курсу.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$lessonId = (int)($in['lesson_id'] ?? 0);
$done = !empty($in['done']);
if ($lessonId <= 0) fail('Не указан урок', 400);

$st = db()->prepare("SELECT b.course_id, l.type FROM lessons l JOIN blocks b ON b.id = l.block_id WHERE l.id = ? LIMIT 1");
$st->execute([$lessonId]);
$row = $st->fetch();
if (!$row) fail('Урок не найден', 404);
if (!user_has_course_access($uid, (int)$row['course_id'])) fail('Нет доступа к курсу', 403);

if ($done) {
  $pending = db()->prepare("SELECT 1 FROM homeworks h
    WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
      AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.homework_id = h.id AND s.user_id = ?)
    LIMIT 1");
  $pending->execute([$lessonId, $uid]);
  if ($pending->fetchColumn()) fail('Сначала сдай базовое домашнее задание', 409);
  $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($driver === 'mysql') {
    db()->prepare("INSERT INTO lesson_progress (user_id, lesson_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE done_at = done_at")
        ->execute([$uid, $lessonId]);
  } else {
    db()->prepare("INSERT OR IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)")
        ->execute([$uid, $lessonId]);
  }
} else {
  db()->prepare("DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?")->execute([$uid, $lessonId]);
}

ok(['done' => $done]);
