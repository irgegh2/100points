<?php
// POST /trainer_task_next.php  { subject_id, level? }
// Выдаёт случайную ЕЩЁ НЕ ПРОЙДЕННУЮ задачу ученика (без эталонного ответа). Этап 8, режим «Решение задач».
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$in = body();
$subjectId = (int)($in['subject_id'] ?? 0);
$level = isset($in['level']) && $in['level'] !== '' ? (int)$in['level'] : null;

$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($subjectId, $allowed, true)) fail('Нет доступа к предмету', 403);

$rand = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'RAND()' : 'RANDOM()';
$sql = "SELECT t.id, t.level, t.text FROM trainer_tasks t
        LEFT JOIN trainer_attempts a ON a.task_id = t.id AND a.user_id = ?
        WHERE t.subject_id = ? AND a.id IS NULL";
$args = [$uid, $subjectId];
if ($level !== null) { $sql .= " AND t.level = ?"; $args[] = $level; }
$sql .= " ORDER BY $rand LIMIT 1";

$st = db()->prepare($sql);
$st->execute($args);
$task = $st->fetch();

if (!$task) ok(['task' => null]); // все пройдены
ok(['task' => ['id' => (int)$task['id'], 'level' => (int)$task['level'], 'text' => $task['text']]]);
