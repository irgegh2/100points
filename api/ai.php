<?php
// Обёртка над ИИ-провайдером routerai.ru (OpenAI-совместимый chat/completions).
// Ключ — только на сервере (secrets.prod.php → routerai_key). Локально для тестов: env ROUTERAI_KEY.
// Все вызовы ИИ идут с бэка — ключ клиенту НИКОГДА не отдаём.

const AI_BASE = 'https://routerai.ru/api/v1';
const AI_DEFAULT_MODEL = 'deepseek/deepseek-v4-flash';

function ai_key(): string {
  $k = prod_secrets()['routerai_key'] ?? '';
  if ($k === '') $k = (string)(getenv('ROUTERAI_KEY') ?: '');
  return $k;
}

function ai_enabled(): bool { return ai_key() !== ''; }

// Вызов модели. $messages — [['role'=>'system|user|assistant','content'=>'...'], ...].
// Возвращает ['ok'=>bool, 'content'=>string] | ['ok'=>false,'error'=>string].
function ai_complete(string $model, array $messages, array $opts = []): array {
  $key = ai_key();
  if ($key === '') return ['ok' => false, 'error' => 'ИИ не настроен'];
  if ($model === '') $model = AI_DEFAULT_MODEL;

  $payload = array_merge(['model' => $model, 'messages' => $messages], $opts);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

  if (function_exists('curl_init')) {
    $ch = curl_init(AI_BASE . '/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_TIMEOUT => 90,
      CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    // curl_close() не нужен с PHP 8 (no-op, в 8.5 deprecated) — не вызываем.
    if ($resp === false) return ['ok' => false, 'error' => 'Сеть ИИ: ' . $err];
  } else {
    // Фолбэк без curl.
    $ctx = stream_context_create(['http' => [
      'method' => 'POST',
      'header' => "Authorization: Bearer $key\r\nContent-Type: application/json\r\n",
      'content' => $body, 'timeout' => 90, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents(AI_BASE . '/chat/completions', false, $ctx);
    $code = 200;
    if ($resp === false) return ['ok' => false, 'error' => 'Сеть ИИ недоступна'];
  }

  $d = json_decode($resp, true);
  $content = $d['choices'][0]['message']['content'] ?? null;
  if ($code >= 400 || $content === null) {
    error_log('[ai] ' . substr((string)$resp, 0, 500));
    return ['ok' => false, 'error' => 'ИИ вернул ошибку'];
  }
  return ['ok' => true, 'content' => (string)$content];
}

// Стриминг: модель отдаёт ответ кусками (SSE). $onDelta($text) вызывается на каждый кусок —
// сервер тут же отправляет его клиенту (живой эфир). Возвращает полный текст в 'content'.
function ai_stream(string $model, array $messages, callable $onDelta, array $opts = []): array {
  $key = ai_key();
  if ($key === '') return ['ok' => false, 'error' => 'ИИ не настроен'];
  if ($model === '') $model = AI_DEFAULT_MODEL;
  if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl недоступен'];

  $payload = array_merge(['model' => $model, 'messages' => $messages, 'stream' => true], $opts);
  $full = ''; $buf = '';
  $ch = curl_init(AI_BASE . '/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: text/event-stream'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$buf, &$full, $onDelta) {
      $buf .= $data;
      while (($nl = strpos($buf, "\n")) !== false) {
        $line = trim(substr($buf, 0, $nl));
        $buf = substr($buf, $nl + 1);
        if ($line === '' || strpos($line, 'data:') !== 0) continue;
        $json = trim(substr($line, 5));
        if ($json === '[DONE]') continue;
        $d = json_decode($json, true);
        $delta = $d['choices'][0]['delta']['content'] ?? '';
        if ($delta !== '') { $full .= $delta; $onDelta($delta); }
      }
      return strlen($data);
    },
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($res === false && $full === '') return ['ok' => false, 'error' => 'Сеть ИИ: ' . $err];
  if ($code >= 400 && $full === '') return ['ok' => false, 'error' => 'ИИ вернул ошибку ' . $code];
  return ['ok' => true, 'content' => $full];
}
