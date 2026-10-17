<?php
// POST /ai_platform_ask.php  { chat_id, content }
// «Помощь по платформе» — ответ через базу знаний (RAG в 3 шага):
//   1) по вопросу + списку КАТЕГОРИЙ ИИ выбирает релевантные категории (JSON);
//   2) по вопросу + НАЗВАНИЯМ вопросов из этих категорий ИИ выбирает нужные вопросы (JSON);
//   3) по вопросу + ОТВЕТАМ на выбранные вопросы ИИ формулирует финальный ответ от своего лица.
// Если ничего не нашлось — честно говорит, что вопрос передан специалисту (для успокоения).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
$uid = require_user();

$in = body();
$chatId = (int)($in['chat_id'] ?? 0);
$content = trim((string)($in['content'] ?? ''));
if ($chatId <= 0) fail('Не указан чат', 400);
if ($content === '') fail('Пустое сообщение', 400);

$c = db()->prepare("SELECT id, user_id, kind FROM ai_chats WHERE id = ? LIMIT 1");
$c->execute([$chatId]);
$chat = $c->fetch();
if (!$chat || (int)$chat['user_id'] !== $uid) fail('Чат не найден', 404);
$model = ai_active_model();

db()->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'user', ?)")->execute([$chatId, $content]);

// Достаём JSON из ответа модели (срезаем возможные ```).
function ai_json($s) {
  $s = preg_replace('/^```(json)?|```$/m', '', trim($s));
  $d = json_decode(trim($s), true);
  return is_array($d) ? $d : null;
}

$notFound = 'Хм, точного ответа на этот вопрос в справке платформы я не нашёл. Я уже передал твой вопрос специалисту — '
          . 'он скоро на него ответит. А пока могу помочь с чем-то ещё по платформе или по учёбе 🙂';

// ── Шаг 1: выбор категорий ──
$cats = db()->query("SELECT id, name FROM help_categories ORDER BY position, id")->fetchAll();
$reply = $notFound;
if ($cats) {
  $catList = implode("\n", array_map(fn($x) => '- ' . $x['name'], $cats));
  $r1 = ai_complete($model, [
    ['role' => 'system', 'content' => 'Ты — маршрутизатор техподдержки платформы. По вопросу ученика выбери из списка категории, которые МОГУТ содержать ответ. Верни СТРОГО JSON без markdown: {"categories":["Название",...]} — только названия из списка, максимум 3. Если ничего не подходит — {"categories":[]}.'],
    ['role' => 'user', 'content' => "Вопрос ученика: {$content}\n\nКатегории:\n{$catList}"],
  ], ['temperature' => 0]);
  $picked = $r1['ok'] ? (ai_json($r1['content'])['categories'] ?? []) : [];
  $catIds = [];
  foreach ($cats as $cc) if (in_array($cc['name'], (array)$picked, true)) $catIds[] = (int)$cc['id'];

  if ($catIds) {
    // ── Шаг 2: выбор вопросов из выбранных категорий ──
    $ph = implode(',', array_fill(0, count($catIds), '?'));
    $qs = db()->prepare("SELECT id, question FROM help_qa WHERE category_id IN ($ph) ORDER BY id");
    $qs->execute($catIds);
    $qrows = $qs->fetchAll();
    if ($qrows) {
      $qList = implode("\n", array_map(fn($x) => "[{$x['id']}] {$x['question']}", $qrows));
      $r2 = ai_complete($model, [
        ['role' => 'system', 'content' => 'Из списка вопросов выбери те, ответы на которые помогут ответить ученику. Верни СТРОГО JSON: {"ids":[1,2]} — id из списка, или {"ids":[]}.'],
        ['role' => 'user', 'content' => "Вопрос ученика: {$content}\n\nВопросы:\n{$qList}"],
      ], ['temperature' => 0]);
      $ids = $r2['ok'] ? array_map('intval', (array)(ai_json($r2['content'])['ids'] ?? [])) : [];
      $ids = array_values(array_intersect($ids, array_map(fn($x) => (int)$x['id'], $qrows)));

      if ($ids) {
        // ── Шаг 3: финальный ответ по найденным ответам ──
        $ph2 = implode(',', array_fill(0, count($ids), '?'));
        $ans = db()->prepare("SELECT question, answer FROM help_qa WHERE id IN ($ph2)");
        $ans->execute($ids);
        $ref = '';
        foreach ($ans as $a) $ref .= "Вопрос: {$a['question']}\nОтвет: {$a['answer']}\n\n";
        $r3 = ai_complete($model, [
          ['role' => 'system', 'content' => 'Ты — дружелюбная поддержка платформы «100балльный репетитор». Тебе дана справка (ответы из базы). Ответь ученику ОТ СВОЕГО ЛИЦА: склей нужное, переформулируй своими словами, лишнее опусти, можно добавить пояснение. НЕ копируй дословно. Если справки недостаточно — честно скажи, что точного ответа нет и вопрос передан специалисту. Не упоминай, что у тебя есть «база» или «справка». Оформляй ответ читаемо: разделяй абзацы пустой строкой, а перечисления пиши Markdown-списком — каждый пункт с новой строки через «- ». Не делай длинный список внутри одного абзаца.'],
          ['role' => 'user', 'content' => "Вопрос ученика: {$content}\n\nСправка из базы:\n{$ref}"],
        ], ['temperature' => 0.4]);
        if ($r3['ok'] && trim($r3['content']) !== '') $reply = $r3['content'];
      }
    }
  }
}

db()->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'assistant', ?)")->execute([$chatId, $reply]);

// Ничего не нашлось → бот обещал передать вопрос специалисту. Делаем это по-настоящему:
// фиксируем вопрос как заявку (kind=support), её видит админ в разделе «Заявки».
if ($reply === $notFound) {
  try {
    $un = db()->prepare("SELECT name, phone FROM users WHERE id = ? LIMIT 1");
    $un->execute([$uid]);
    $u = $un->fetch() ?: ['name' => '', 'phone' => ''];
    db()->prepare("INSERT INTO leads (kind, name, phone, user_id, message, source, status) VALUES ('support', ?, ?, ?, ?, 'ai-help', 'new')")
        ->execute([$u['name'] ?? '', $u['phone'] ?? '', $uid, mb_substr($content, 0, 1000)]);
  } catch (Throwable $e) { error_log('[platform-escalate] ' . $e->getMessage()); }
}

ok(['message' => ['role' => 'assistant', 'content' => $reply]]);
