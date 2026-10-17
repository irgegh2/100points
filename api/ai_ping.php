<?php
// GET /ai_ping.php — проверка, что сервер дотягивается до ИИ-провайдера (только админ).
// Временный диагностический эндпоинт.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ai.php';
require_admin();
release_session();

if (!ai_enabled()) ok(['ok' => false, 'reason' => 'нет ключа в secrets']);
$r = ai_complete('', [
  ['role' => 'user', 'content' => 'Ответь одним словом: 2+2=?'],
]);
ok(['curl' => function_exists('curl_init'), 'result' => $r]);
