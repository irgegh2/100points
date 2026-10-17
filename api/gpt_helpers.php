<?php
// Общие функции закрытого API для личного GPT. Не использует сессии учеников.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/helpers.php';
ini_set('display_errors', '0');
install_error_handlers();
header('Cache-Control: no-store');

function gpt_auth(array $methods): void {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if (!in_array($method, $methods, true)) {
    header('Allow: ' . implode(', ', $methods));
    fail('Метод не поддерживается', 405);
  }
  $hash = (string)(prod_secrets()['gpt_actions_key_hash'] ?? getenv('GPT_ACTIONS_KEY_HASH') ?: '');
  $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
      if (strtolower($name) === 'authorization') $auth = (string)$value;
    }
  }
  if (!preg_match('/^[a-f0-9]{64}$/', $hash)) fail('Интеграция не настроена', 503);
  if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,128})$/', $auth, $match)
      || !hash_equals($hash, hash('sha256', $match[1]))) {
    header('WWW-Authenticate: Bearer');
    fail('Нужен ключ интеграции', 401);
  }
}

function gpt_json(): array {
  if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    fail('Нужен Content-Type: application/json', 415);
  }
  $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
  if (strlen($raw) > 1024 * 1024) fail('Запрос больше 1 МБ', 413);
  $data = json_decode($raw, true);
  if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) fail('Некорректный JSON', 400);
  return $data;
}

function gpt_string($value, string $field, int $max, bool $required = false): string {
  if (!is_string($value)) fail('Нужна строка: ' . $field, 400);
  $value = trim($value);
  if (($required && $value === '') || strlen($value) > $max) fail('Неверная длина: ' . $field, 400);
  return $value;
}

function gpt_integer($value, string $field, int $min, int $max): int {
  if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) {
    fail('Неверное целое число: ' . $field, 400);
  }
  return (int)$value;
}

function gpt_lesson_context(int $id): array {
  $st = db()->prepare("SELECT l.*, b.title AS section_title, b.id AS section_id,
      m.id AS period_id, m.name AS period_name, m.starts_on, m.ends_on,
      c.id AS course_id, c.title AS course_title, s.id AS subject_id, s.name AS subject_name
    FROM lessons l JOIN blocks b ON b.id=l.block_id
    LEFT JOIN months m ON m.id=b.month_id
    JOIN courses c ON c.id=b.course_id JOIN subjects s ON s.id=c.subject_id WHERE l.id=?");
  $st->execute([$id]);
  $lesson = $st->fetch();
  if (!$lesson) fail('Урок не найден', 404);
  return $lesson;
}

function gpt_homework_data(int $id): array {
  $st = db()->prepare('SELECT id,lesson_id,level,title,deadline,position FROM homeworks WHERE id=?');
  $st->execute([$id]);
  $homework = $st->fetch();
  if (!$homework) fail('Домашка не найдена', 404);
  $st = db()->prepare('SELECT id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position FROM questions WHERE homework_id=? ORDER BY position,id');
  $st->execute([$id]);
  $questions = $st->fetchAll();
  $attachments = db()->prepare('SELECT kind,name,size,url,position FROM question_attachments WHERE question_id=? ORDER BY position,id');
  foreach ($questions as &$question) {
    foreach (['options', 'correct'] as $field) {
      $question[$field] = $question[$field] === null ? null : json_decode($question[$field], true);
    }
    foreach (['id','points','optional','hard','case_sensitive','file_limit','position'] as $field) $question[$field] = (int)$question[$field];
    $attachments->execute([$question['id']]);
    $question['attachments'] = $attachments->fetchAll();
  }
  unset($question);
  foreach (['id','lesson_id','position'] as $field) $homework[$field] = (int)$homework[$field];
  $homework['questions'] = $questions;
  // Ревизия включает условия, ответы, решения и вложения.
  $homework['revision'] = hash('sha256', json_encode($homework, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return $homework;
}

function gpt_attachment(array $item): array {
  $kind = (string)($item['kind'] ?? '');
  if (!in_array($kind, ['image','audio','file','video'], true)) fail('Неверный вид вложения', 400);
  $url = gpt_string($item['url'] ?? '', 'attachment.url', 400, true);
  $parts = parse_url($url);
  $host = strtolower((string)($parts['host'] ?? ''));
  $local = preg_match('~^/(?:img|uploads)/[^\\\\\x00-\x20]*$~D', $url) === 1;
  $http = ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']);
  $own = $http && in_array($host, ['100-points.ru','lk.100-points.ru','admin.100-points.ru'], true)
    && preg_match('~^/(?:img|uploads)/~', (string)($parts['path'] ?? ''));
  parse_str((string)($parts['query'] ?? ''), $params);
  $proxy = ($parts['path'] ?? '') === '/api/file.php'
    && ($host === '' || ($http && in_array($host, ['100-points.ru','lk.100-points.ru','admin.100-points.ru'], true)))
    && isset($params['path']) && is_string($params['path'])
    && preg_match('~^questions/[A-Za-z0-9_.-]+$~D', $params['path']);
  $video = $http && $host === 'kinescope.io' && preg_match('~^/embed/[\w-]+$~', (string)($parts['path'] ?? ''));
  // Только уже загруженные файлы сайта и Kinescope; произвольные URL не скачиваем.
  if (($kind === 'video' && !$video) || ($kind !== 'video' && !$local && !$own && !$proxy)) fail('Вложение должно ссылаться на файл сайта или видео Kinescope', 400);
  return ['kind'=>$kind, 'name'=>gpt_string($item['name'] ?? 'Файл', 'attachment.name', 200, true),
    'size'=>gpt_string($item['size'] ?? '', 'attachment.size', 40), 'url'=>$url];
}

function gpt_question(array $q): array {
  $type = (string)($q['type'] ?? '');
  if (!in_array($type, ['single','multi','text','textarea','file','audio'], true)) fail('Неверный тип задания', 400);
  $options = $q['options'] ?? null;
  $correct = $q['correct'] ?? null;
  if (in_array($type, ['single','multi'], true)) {
    if (!is_array($options) || !array_is_list($options) || count($options)<2 || count($options)>30) fail('Нужно 2–30 вариантов ответа', 400);
    $options = array_map(fn($v) => gpt_string($v, 'options', 10000, true), $options);
    if (!is_array($correct) || !array_is_list($correct) || !$correct) fail('Укажи правильные варианты', 400);
    $correct = array_values(array_unique(array_map(fn($v) => gpt_integer($v, 'correct', 0, count($options)-1), $correct)));
  } elseif ($type === 'text') {
    $options = null;
    if (!is_array($correct) || !array_is_list($correct) || !$correct || count($correct)>100) fail('Укажи допустимые короткие ответы', 400);
    $correct = array_map(fn($v) => gpt_string($v, 'correct', 2000, true), $correct);
  } else {
    $options = null; $correct = null;
  }
  $grade = (string)($q['grade_mode'] ?? 'full');
  if (!in_array($grade, ['full','partial'], true)) fail('Неверный режим оценивания', 400);
  $attachments = $q['attachments'] ?? [];
  if (!is_array($attachments) || !array_is_list($attachments) || count($attachments)>30) fail('Слишком много вложений', 400);
  $counts = [];
  foreach ($attachments as $item) {
    if (!is_array($item)) fail('Неверное вложение', 400);
    $kind = (string)($item['kind'] ?? '');
    $counts[$kind] = ($counts[$kind] ?? 0)+1;
    if ($counts[$kind]>10) fail('Не более 10 вложений каждого вида', 400);
  }
  return ['type'=>$type, 'text'=>gpt_string($q['text'] ?? '', 'text', 60000, true), 'options'=>$options,
    'correct'=>$correct, 'solution'=>gpt_string($q['solution'] ?? '', 'solution', 60000),
    'hint'=>gpt_string($q['hint'] ?? '', 'hint', 10000), 'points'=>gpt_integer($q['points'] ?? 1, 'points', 1, 100),
    'optional'=>!empty($q['optional']) ? 1 : 0, 'hard'=>!empty($q['hard']) ? 1 : 0,
    'case_sensitive'=>!empty($q['case_sensitive']) ? 1 : 0, 'grade_mode'=>$grade,
    'file_limit'=>gpt_integer($q['file_limit'] ?? 1, 'file_limit', 1, 10),
    'attachments'=>array_map('gpt_attachment', $attachments)];
}
