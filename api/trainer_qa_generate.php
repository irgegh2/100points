<?php
// POST /trainer_qa_generate.php  { subject_id, level? }
// Режим «Вопрос-ответ»: берём случайную тему-пул и просим ИИ сгенерировать ОДИН вопрос с вариантами.
// Правильный ответ клиенту НЕ отдаём — храним на сервере (trainer_qa_generated) до проверки.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
$uid = require_user();
// Генерация и независимая проверка могут занять несколько секунд. Не держим
// PHP-сессию заблокированной, чтобы остальные запросы кабинета работали параллельно.
release_session();

$in = body();
$subjectId = (int)($in['subject_id'] ?? 0);
$level = isset($in['level']) && $in['level'] !== '' && $in['level'] !== 'Любой' ? (int)$in['level'] : null;

$allowed = array_column(user_accessible_subjects($uid), 'id');
if (!in_array($subjectId, $allowed, true)) fail('Нет доступа к предмету', 403);

$rand = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'RAND()' : 'RANDOM()';
$sql = "SELECT t.id, t.level, t.name, t.examples, s.name AS subject_name
        FROM trainer_qa_topics t JOIN subjects s ON s.id = t.subject_id
        WHERE t.subject_id = ?";
$args = [$subjectId];
if ($level !== null) { $sql .= " AND t.level = ?"; $args[] = $level; }
$sql .= " ORDER BY $rand LIMIT 1";
$st = db()->prepare($sql);
$st->execute($args);
$topic = $st->fetch();
if (!$topic) fail('По этому предмету нет тем для генерации', 404);

// История уникальности строго из этой темы, этого уровня и этого предмета.
// Ответы здесь не храним: для защиты от повторов достаточно формулировок.
$historySt = db()->prepare("SELECT question_text FROM trainer_qa_history
  WHERE subject_id = ? AND level = ? AND topic_id = ?
  ORDER BY id DESC LIMIT 25");
$historySt->execute([$subjectId, (int)$topic['level'], (int)$topic['id']]);
$recentQuestions = array_values(array_filter(array_map(
  fn($row) => trim((string)$row['question_text']),
  $historySt->fetchAll()
)));

$sys = 'Ты — генератор тренировочных заданий ЕГЭ. По теме и указаниям составь ОДИН вопрос с 4 вариантами ответа, '
     . 'ровно один из которых верный. Верни СТРОГО JSON без markdown: '
     . '{"text":"текст вопроса","options":["A","B","C","D"],"correct":0,"explanation":"почему этот вариант верный"}. '
     . 'correct — индекс правильного варианта (0–3). Вопрос должен быть осмысленным и решаемым, по-русски. '
     . 'У интерфейса НЕТ возможности показывать графики, диаграммы, рисунки, схемы, чертежи, карты и фотографии. '
     . 'Никогда не ссылайся на такой визуал и не пиши «на графике/рисунке/схеме показано». '
     . 'Если тема связана с чтением графиков, замени график самодостаточной Markdown-таблицей внутри text либо полностью перечисли исходные данные словами. '
     . 'Если вопрос ссылается на таблицу, сама таблица ОБЯЗАТЕЛЬНО должна находиться в text в Markdown-формате: строка заголовков, строка | --- | --- | и строки данных. '
     . 'В text разрешены переносы строк, Markdown-таблицы, списки, выделение **жирным** и математические формулы $...$. '
     . 'Используй только школьную программу и кодификатор ЕГЭ: не требуй вузовских или олимпиадных теорем. '
     . 'Перед возвратом JSON сам реши задачу заново, проверь граничные случаи и убедись, что correct действительно совпадает с решением.';
$user = "Предмет: {$topic['subject_name']}.\nТема: {$topic['name']}\nЧто спрашивать / примеры: {$topic['examples']}\nУровень сложности (1–5): {$topic['level']}";
if ($recentQuestions) {
  $numbered = [];
  foreach ($recentQuestions as $i => $oldQuestion) $numbered[] = ($i + 1) . '. ' . $oldQuestion;
  $user .= "\n\nНиже последние вопросы ИМЕННО по этой теме, этому уровню и этому предмету. "
    . "Не повторяй их и не делай близкий пересказ. Простая замена чисел, имён, дат или порядка вариантов тоже считается повтором. "
    . "Выбери другой аспект темы, другой тип рассуждения и другую формулировку:\n" . implode("\n", $numbered);
}

$normalize = static fn(string $text): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($text)));
$knownNormalized = array_map($normalize, $recentQuestions);
$referencesMissingVisual = static function (string $text): bool {
  // Генератор возвращает только текст. Любая ссылка на внешний визуал делает
  // вопрос нерешаемым, поэтому такая попытка никогда не попадёт ученику.
  return (bool)preg_match(
    '/(?:график|диаграмм|рисунк|схем|черт[её]ж|изображен|картин|фотограф|карт[аеуы]|иллюстрац)/ui',
    $text
  );
};
$hasMarkdownTable = static function (string $text): bool {
  // Заголовок с колонками + обязательная markdown-строка-разделитель.
  return (bool)preg_match('/^\s*\|?.+\|.+\s*\R\s*\|?\s*:?-{3,}:?\s*\|/mu', $text);
};
$q = null;
$questionText = '';
for ($attempt = 0; $attempt < 3; $attempt++) {
  $attemptPrompt = $user;
  if ($attempt > 0) {
    $attemptPrompt .= "\n\nПредыдущая попытка повторила старую формулировку или вернула неверный формат. Создай другой корректный вопрос.";
  }
  $ai = ai_complete('', [
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user', 'content' => $attemptPrompt],
  ], ['temperature' => 0.9, 'max_tokens' => 500]);
  if (!$ai['ok']) fail('ИИ не смог сгенерировать вопрос: ' . ($ai['error'] ?? ''), 502);

  $raw = preg_replace('/^```(json)?|```$/m', '', trim($ai['content']));
  $candidate = json_decode(trim($raw), true);
  if (!is_array($candidate) || !isset($candidate['text'], $candidate['options'], $candidate['correct'])
      || !is_array($candidate['options']) || count($candidate['options']) !== 4) continue;
  $candidateText = trim((string)$candidate['text']);
  $generatedCorrect = (int)$candidate['correct'];
  if ($candidateText === '' || $generatedCorrect < 0 || $generatedCorrect > 3
      || in_array($normalize($candidateText), $knownNormalized, true)) continue;
  if ($referencesMissingVisual($candidateText)) continue;
  if (preg_match('/таблиц/ui', $candidateText) && !$hasMarkdownTable($candidateText)) continue;

  // Независимая перепроверка без показа выбранного генератором ответа: второй
  // проход решает уже готовый вопрос с нуля и отбрасывает неоднозначное/вне ЕГЭ.
  $reviewPrompt = "Предмет: {$topic['subject_name']}. Тема: {$topic['name']}. Уровень: {$topic['level']}.\n"
    . "Вопрос: {$candidateText}\nВарианты: "
    . json_encode(array_values($candidate['options']), JSON_UNESCAPED_UNICODE)
    . "\nРеши независимо. Убедись, что ВСЕ данные для ответа находятся прямо в тексте вопроса и ему не нужен отсутствующий график, рисунок или другой визуал. "
    . "Для математики обязательно проверь область допустимых значений, крайние и особые случаи. ";
  $review = ai_complete('', [
    ['role' => 'system', 'content' => 'Ты — строгий независимый редактор заданий ЕГЭ. Не доверяй предполагаемому ответу автора: реши вопрос самостоятельно. Проверь, что он входит в школьную программу ЕГЭ, однозначен, имеет ровно один верный вариант и полностью решается по данным, которые видны в тексте. Если упомянут график, рисунок, диаграмма, схема, чертёж, карта, фото или отсутствующая таблица — вопрос невалиден. Верни СТРОГО JSON без markdown: {"valid":true,"correct_text":"точный текст одного варианта без номера и буквы","explanation":"краткое проверенное решение"}. Не возвращай индекс: дословно скопируй текст верного варианта из списка. Если вопрос некорректен, неоднозначен, требует отсутствующего визуала или материала вне ЕГЭ, верни {"valid":false,"correct_text":null,"explanation":"причина"}.'],
    ['role' => 'user', 'content' => $reviewPrompt],
  ], ['temperature' => 0, 'max_tokens' => 400]);
  if (!$review['ok']) fail('ИИ создал вопрос, но не смог проверить ответ: ' . ($review['error'] ?? ''), 502);
  $reviewRaw = preg_replace('/^```(json)?|```$/m', '', trim($review['content']));
  $checked = json_decode(trim($reviewRaw), true);
  $checkedValid = is_array($checked) && (($checked['valid'] ?? false) === true || ($checked['valid'] ?? '') === 'true');
  $checkedText = is_array($checked) ? trim((string)($checked['correct_text'] ?? '')) : '';
  $checkedCorrect = -1;
  if ($checkedValid && $checkedText !== '') {
    $matched = [];
    foreach (array_values($candidate['options']) as $optionIndex => $optionText) {
      if ($normalize((string)$optionText) === $normalize($checkedText)) $matched[] = $optionIndex;
    }
    if (count($matched) === 1) $checkedCorrect = (int)$matched[0];
  }
  // Не «чинить» ответ одной модели ответом другой: принимаем вопрос только
  // когда два независимых решения сошлись на одном и том же варианте.
  if ($checkedCorrect < 0 || $checkedCorrect !== $generatedCorrect) continue;
  $candidate['correct'] = $generatedCorrect;
  $candidate['explanation'] = trim((string)($checked['explanation'] ?? $candidate['explanation'] ?? ''));
  $q = $candidate;
  $questionText = $candidateText;
  break;
}
if ($q === null) fail('ИИ не смог создать новый неповторяющийся вопрос, попробуй ещё раз', 502);

$options = array_values(array_map('strval', $q['options']));
$correct = (int)$q['correct'];
if ($correct < 0 || $correct >= count($options)) $correct = 0;

// Сохраняем с правильным ответом.
$payload = json_encode([
  'text' => $questionText, 'options' => $options, 'correct' => $correct,
  'explanation' => (string)($q['explanation'] ?? ''), 'topic' => $topic['name'],
], JSON_UNESCAPED_UNICODE);
db()->prepare("INSERT INTO trainer_qa_generated (user_id, payload) VALUES (?,?)")->execute([$uid, $payload]);
$genId = (int)db()->lastInsertId();
db()->prepare("INSERT INTO trainer_qa_history (user_id, subject_id, level, topic_id, question_text) VALUES (?,?,?,?,?)")
  ->execute([$uid, $subjectId, (int)$topic['level'], (int)$topic['id'], $questionText]);

ok([
  'gen_id'   => $genId,
  'topic'    => $topic['name'],
  'level'    => (int)$topic['level'],
  'question' => ['text' => $questionText, 'options' => $options],
]);
