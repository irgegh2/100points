<?php
// POST /ai_chat_create.php  { kind:'free'|'subject'|'platform'|'task', subject?, task_id? }
// Создаёт ИИ-чат. Системный промпт зависит от типа:
//   free     — общий помощник (учёба + жизнь, совет, поддержка, в рамках закона);
//   subject  — фокус на предмете (но от других вопросов не увиливает, мягко возвращает к предмету);
//   task     — разбор конкретной задачи тренажёра (контекст задачи);
//   platform — помощь по платформе (отвечает через базу знаний, RAG — см. ai_platform_ask.php).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
$uid = require_user();

$in = body();
$kind = in_array($in['kind'] ?? '', ['free', 'subject', 'platform', 'task'], true) ? $in['kind'] : 'free';
$taskId = (int)($in['task_id'] ?? 0);
$subject = trim((string)($in['subject'] ?? ''));

$model = AI_DEFAULT_MODEL;

// Общая «легенда» (кто ты + не раскрывать модель) — добавляется ко всем типам.
$persona = 'Ты — дружелюбный ИИ-помощник образовательной платформы «100балльный репетитор». Пиши по-русски, '
  . 'объясняй понятно и по-доброму.'
  . "\n\nВАЖНО про себя: НИКОГДА не раскрывай, на какой нейросети/модели или платформе ты работаешь. "
  . 'Не упоминай DeepSeek, OpenAI, Qwen, Google, Anthropic и любые названия моделей и компаний-разработчиков. '
  . 'На вопросы вроде «какая ты модель», «кто тебя создал», «на чём ты работаешь» отвечай коротко: '
  . '«Я — ИИ-помощник платформы «100балльный репетитор»» — и возвращай разговор к делу. Технические детали о себе не обсуждай.';

if ($kind === 'task') {
  if ($taskId <= 0) fail('Не указана задача', 400);
  $st = db()->prepare("SELECT t.subject_id, t.text, t.answer, t.solution, t.model FROM trainer_tasks t WHERE t.id = ? LIMIT 1");
  $st->execute([$taskId]);
  $task = $st->fetch();
  if (!$task) fail('Задача не найдена', 404);
  $allowed = array_column(user_accessible_subjects($uid), 'id');
  if (!in_array((int)$task['subject_id'], $allowed, true)) fail('Нет доступа', 403);
  if ($task['model']) $model = $task['model'];
  $title = 'Разбор задачи';
  $greeting = 'Давай разберём эту задачу вместе! Что именно вызывает затруднение — с чего начать или где застрял?';
  $system = $persona . "\n\nЭтот чат — для разбора КОНКРЕТНОЙ задачи. Вот она:\nЗАДАЧА: {$task['text']}\nПРАВИЛЬНЫЙ ОТВЕТ: {$task['answer']}"
    . ($task['solution'] ? "\nРЕШЕНИЕ: {$task['solution']}" : '')
    . "\nРаботай в основном по этой задаче и предмету: отвечай на вопросы ученика по ней, подсказывай, объясняй шаги. "
    . 'Можно немного уходить в сторону, если это помогает понять задачу, но возвращайся к ней. Не вываливай всё решение сразу, если не просят.';
} elseif ($kind === 'subject') {
  if ($subject === '') $subject = 'выбранный предмет';
  $title = "Чат по предмету «{$subject}»";
  $greeting = "Чат по предмету «{$subject}». Объясню тему, разберу задачу, помогу подготовиться. С чего начнём?";
  $system = $persona . "\n\nЭтот чат привязан к предмету «{$subject}». Старайся отвечать в рамках этого предмета и подготовки к экзамену по нему. "
    . 'Если ученик спросит про что-то другое — НЕ увиливай, ответь по существу, но мягко предложи вернуться к предмету «' . $subject . '».';
} elseif ($kind === 'platform') {
  $title = 'Помощь по платформе';
  $greeting = 'Привет! Помогу разобраться с платформой: домашки, пробники, доступ к курсам, подписка, технические вопросы. Что случилось?';
  // Для platform системный промпт минимальный — основное знание подаётся per-message в ai_platform_ask.php.
  $system = $persona . "\n\nЭтот чат — техническая поддержка платформы «100балльный репетитор». Отвечай только по работе платформы, "
    . 'опираясь на справочную информацию, которую тебе дадут. Не выдумывай факты о платформе.';
} else { // free
  $title = 'Свободный чат';
  $greeting = 'Привет! Я твой ИИ-помощник. Спрашивай что угодно — помогу с учёбой, дам совет, поддержу. С чего начнём?';
  $system = $persona . "\n\nЭто свободный чат. Помогай ученику с чем угодно в рамках закона и приличия: учёба, советы по жизни, "
    . 'мотивация, поддержка, можешь побыть в роли доброго наставника/психолога. Будь тёплым и полезным. Не давай опасных, '
    . 'незаконных или вредных советов.';
}

$pdo = db();
$pdo->prepare("INSERT INTO ai_chats (user_id, title, kind, task_id, subject, model) VALUES (?,?,?,?,?,?)")
    ->execute([$uid, $title, $kind, $taskId ?: null, $kind === 'subject' ? $subject : null, $model]);
$chatId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'system', ?)")->execute([$chatId, $system]);
$pdo->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'assistant', ?)")->execute([$chatId, $greeting]);

ok([
  'chat' => ['id' => $chatId, 'title' => $title, 'kind' => $kind],
  'messages' => [['role' => 'assistant', 'content' => $greeting]],
]);
