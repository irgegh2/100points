<?php
// POST /ai_chat_stream.php  { chat_id, content }
// Как ai_chat_send, но ответ ИИ отдаётся ПОТОКОМ (живой эфир): клиент видит текст по мере генерации.
// Тело ответа — простой текст (куски дельт). В конце полный ответ сохраняется в БД.
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
if (!ai_enabled()) fail('ИИ не настроен', 503);

// Сохраняем сообщение ученика.
db()->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'user', ?)")->execute([$chatId, $content]);

// История (system + диалог, последние ~40).
$h = db()->prepare("SELECT role, content FROM ai_messages WHERE chat_id = ? ORDER BY id");
$h->execute([$chatId]);
$system = null; $dialog = [];
foreach ($h->fetchAll() as $m) {
  if ($m['role'] === 'system') { $system = $m['content']; continue; }
  $dialog[] = ['role' => $m['role'], 'content' => $m['content']];
}
$dialog = array_slice($dialog, -40);
$messages = $system !== null ? array_merge([['role' => 'system', 'content' => $system]], $dialog) : $dialog;

// Отдаём поток. Глушим буферизацию, чтобы куски уходили сразу.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // отключить буферизацию nginx/LiteSpeed
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

$r = ai_stream('', $messages, function ($delta) {
  echo $delta;
  flush();
}, ['temperature' => 0.6]);

// Сохраняем полный ответ (или ошибку как текст).
$reply = $r['ok'] ? $r['content'] : '';
if (!$r['ok'] && $reply === '') { $reply = '⚠️ ' . ($r['error'] ?? 'ИИ не ответил'); echo "\n" . $reply; }
db()->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'assistant', ?)")->execute([$chatId, $reply]);
exit;
