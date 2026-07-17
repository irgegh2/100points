<?php
// POST /trainer_qa_generate.php  { subject_id, level? }
// Режим «Вопрос-ответ»: берём случайную тему-пул и просим ИИ сгенерировать ОДИН вопрос с вариантами.
// Правильный ответ клиенту НЕ отдаём — храним на сервере (trainer_qa_generated) до проверки.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
$uid = require_user();

$in = body();
$subjectId = (int)($in['subject_id'] ?? 0);
$level = isset($in['level']) && $in['level'] !== '' && $in['level'] !== 'Любой' ? (int)$in['level'] : null;

$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($subjectId, $allowed, true)) fail('Нет доступа к предмету', 403);

$rand = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'RAND()' : 'RANDOM()';
$sql = "SELECT t.id, t.level, t.name, t.examples, t.model, s.name AS subject_name
        FROM trainer_qa_topics t JOIN subjects s ON s.id = t.subject_id
        WHERE t.subject_id = ?";
$args = [$subjectId];
if ($level !== null) { $sql .= " AND t.level = ?"; $args[] = $level; }
$sql .= " ORDER BY $rand LIMIT 1";
$st = db()->prepare($sql);
$st->execute($args);
$topic = $st->fetch();
if (!$topic) fail('По этому предмету нет тем для генерации', 404);

$sys = 'Ты — генератор тренировочных заданий ЕГЭ. По теме и указаниям составь ОДИН вопрос с 4 вариантами ответа, '
     . 'ровно один из которых верный. Верни СТРОГО JSON без markdown: '
     . '{"text":"текст вопроса","options":["A","B","C","D"],"correct":0,"explanation":"почему этот вариант верный"}. '
     . 'correct — индекс правильного варианта (0–3). Вопрос должен быть осмысленным и решаемым, по-русски.';
$user = "Предмет: {$topic['subject_name']}.\nТема: {$topic['name']}\nЧто спрашивать / примеры: {$topic['examples']}\nУровень сложности (1–5): {$topic['level']}";

$ai = ai_complete((string)$topic['model'], [
  ['role' => 'system', 'content' => $sys],
  ['role' => 'user', 'content' => $user],
], ['temperature' => 0.9]);
if (!$ai['ok']) fail('ИИ не смог сгенерировать вопрос: ' . ($ai['error'] ?? ''), 502);

$raw = preg_replace('/^```(json)?|```$/m', '', trim($ai['content']));
$q = json_decode(trim($raw), true);
if (!is_array($q) || !isset($q['text'], $q['options'], $q['correct']) || !is_array($q['options'])) {
  fail('ИИ вернул некорректный формат, попробуй ещё раз', 502);
}
$options = array_values(array_map('strval', $q['options']));
$correct = (int)$q['correct'];
if ($correct < 0 || $correct >= count($options)) $correct = 0;

// Сохраняем с правильным ответом.
$payload = json_encode([
  'text' => (string)$q['text'], 'options' => $options, 'correct' => $correct,
  'explanation' => (string)($q['explanation'] ?? ''), 'topic' => $topic['name'],
], JSON_UNESCAPED_UNICODE);
db()->prepare("INSERT INTO trainer_qa_generated (user_id, payload) VALUES (?,?)")->execute([$uid, $payload]);
$genId = (int)db()->lastInsertId();

ok([
  'gen_id'   => $genId,
  'topic'    => $topic['name'],
  'level'    => (int)$topic['level'],
  'question' => ['text' => (string)$q['text'], 'options' => $options],
]);
