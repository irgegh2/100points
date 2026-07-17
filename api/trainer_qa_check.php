<?php
// POST /trainer_qa_check.php  { gen_id, value }
// Проверка ответа на сгенерированный вопрос. Сверяем с сохранённым правильным, отдаём разбор. Одноразово.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$in = body();
$genId = (int)($in['gen_id'] ?? 0);
$value = $in['value'] ?? null;
if ($genId <= 0) fail('Не указан вопрос', 400);

$st = db()->prepare("SELECT payload FROM trainer_qa_generated WHERE id = ? AND user_id = ? LIMIT 1");
$st->execute([$genId, $uid]);
$row = $st->fetch();
if (!$row) fail('Вопрос не найден или уже отвечен', 404);

$p = json_decode($row['payload'], true);
$correct = (int)($p['correct'] ?? 0);
$ok = ($value !== null && $value !== '' && (int)$value === $correct);

// Одноразово: удаляем, чтобы повторно не отвечали на тот же.
db()->prepare("DELETE FROM trainer_qa_generated WHERE id = ?")->execute([$genId]);

ok([
  'verdict'      => $ok ? 'correct' : 'wrong',
  'correct'      => $correct,
  'correct_text' => $p['options'][$correct] ?? '',
  'explanation'  => $p['explanation'] ?? '',
]);
