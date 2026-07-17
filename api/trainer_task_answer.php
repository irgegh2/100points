<?php
// POST /trainer_task_answer.php  { task_id, answer }
// Ученик отвечает на задачу. ИИ (модель из задачи) сверяет с эталонным ответом и объясняет,
// верно ли и в чём ошибка. Записываем попытку (повторно задача не выдаётся). Этап 8.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
$uid = require_user();

$in = body();
$taskId = (int)($in['task_id'] ?? 0);
$answer = trim((string)($in['answer'] ?? ''));
if ($taskId <= 0) fail('Не указана задача', 400);

$st = db()->prepare("SELECT t.id, t.subject_id, t.text, t.answer, t.solution, t.model FROM trainer_tasks t WHERE t.id = ? LIMIT 1");
$st->execute([$taskId]);
$task = $st->fetch();
if (!$task) fail('Задача не найдена', 404);

$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array((int)$task['subject_id'], $allowed, true)) fail('Нет доступа к предмету', 403);

// ИИ-проверка: просим строгий JSON с вердиктом и объяснением.
$sys = 'Ты — опытный преподаватель ЕГЭ. Оцени ответ ученика на задачу, опираясь на эталонный ответ. '
     . 'Отвечай СТРОГО в формате JSON без markdown: {"verdict":"correct|partial|wrong","explanation":"..."}. '
     . 'В explanation 2–4 предложения: верно ли, какой правильный ответ и в чём именно ошибка ученика. Пиши по-русски, дружелюбно.';
$user = "Задача:\n{$task['text']}\n\nЭталонный (правильный) ответ: {$task['answer']}\n"
      . ($task['solution'] ? "Краткое решение: {$task['solution']}\n" : '')
      . "\nОтвет ученика: " . ($answer !== '' ? $answer : '(пусто)');

$ai = ai_complete((string)$task['model'], [
  ['role' => 'system', 'content' => $sys],
  ['role' => 'user', 'content' => $user],
], ['temperature' => 0.2]);

$verdict = null; $explanation = '';
if ($ai['ok']) {
  $raw = trim($ai['content']);
  // вырезаем возможные ```json ... ```
  $raw = preg_replace('/^```(json)?|```$/m', '', $raw);
  $parsed = json_decode(trim($raw), true);
  if (is_array($parsed)) {
    $verdict = $parsed['verdict'] ?? null;
    $explanation = (string)($parsed['explanation'] ?? '');
  } else {
    $explanation = $ai['content']; // не JSON — показываем как есть
  }
} else {
  $explanation = 'Не удалось получить разбор ИИ: ' . ($ai['error'] ?? '') . '. Эталонный ответ: ' . $task['answer'];
}
$correct = $verdict === 'correct' ? 1 : ($verdict === null ? null : 0);

// Записываем попытку (пройденную задачу не выдаём снова). upsert.
$ex = db()->prepare("SELECT id FROM trainer_attempts WHERE user_id = ? AND task_id = ? LIMIT 1");
$ex->execute([$uid, $taskId]);
if ($row = $ex->fetch()) db()->prepare("UPDATE trainer_attempts SET correct = ? WHERE id = ?")->execute([$correct, (int)$row['id']]);
else db()->prepare("INSERT INTO trainer_attempts (user_id, task_id, correct) VALUES (?,?,?)")->execute([$uid, $taskId, $correct]);

ok([
  'verdict'        => $verdict,         // correct | partial | wrong | null
  'explanation'    => $explanation,
  'correct_answer' => $task['answer'],  // после ответа эталон можно показать
  'solution'       => $task['solution'],
]);
