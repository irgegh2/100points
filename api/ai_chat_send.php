<?php
// POST /ai_chat_send.php  { chat_id, content }
// Добавляет сообщение ученика, зовёт ИИ со всей историей (вкл. системный промпт), возвращает ответ.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
$uid = require_user();

$in = body();
$chatId = (int)($in['chat_id'] ?? 0);
$content = trim((string)($in['content'] ?? ''));
if ($chatId <= 0) fail('Не указан чат', 400);
if ($content === '') fail('Пустое сообщение', 400);

$c = db()->prepare("SELECT id, user_id FROM ai_chats WHERE id = ? LIMIT 1");
$c->execute([$chatId]);
$chat = $c->fetch();
if (!$chat || (int)$chat['user_id'] !== $uid) fail('Чат не найден', 404);

// Сохраняем сообщение ученика.
db()->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'user', ?)")->execute([$chatId, $content]);

// Собираем историю (system + диалог), ограничим последними ~40 сообщениями.
$h = db()->prepare("SELECT role, content FROM ai_messages WHERE chat_id = ? ORDER BY id");
$h->execute([$chatId]);
$all = $h->fetchAll();
$system = null; $dialog = [];
foreach ($all as $m) {
  if ($m['role'] === 'system') { $system = $m['content']; continue; }
  $dialog[] = ['role' => $m['role'], 'content' => $m['content']];
}
$dialog = array_slice($dialog, -40);
$messages = $system !== null ? array_merge([['role' => 'system', 'content' => $system]], $dialog) : $dialog;

$ai = ai_complete('', $messages, ['temperature' => 0.6]);
if (!$ai['ok']) fail('ИИ не ответил: ' . ($ai['error'] ?? ''), 502);

$reply = $ai['content'];
db()->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'assistant', ?)")->execute([$chatId, $reply]);

ok(['message' => ['role' => 'assistant', 'content' => $reply]]);
