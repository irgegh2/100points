<?php
// Общие хелперы: CORS, сессия, чтение JSON, единый формат ответа, обработка ошибок.

function cors() {
  // В дев-режиме фронт на http://localhost:5173, API на http://localhost:8000.
  // Разрешаем нужные origin'ы с куками. На проде фронт и API на одном домене — CORS не мешает.
  $allowed = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
  ];
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (in_array($origin, $allowed, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

// Можно ли показывать детали ошибок наружу. Опирается на SAPI, а не на Host:
// cli/cli-server бывают только локально (php / php -S), клиент это не подделает.
// На реальном Apache/FPM/LiteSpeed вернёт false → детали ошибок скрыты.
function dev_visible() {
  return in_array(php_sapi_name(), ['cli', 'cli-server'], true);
}

// Запрос пришёл по HTTPS (для флага Secure у сессионной куки).
function is_https() {
  $h = $_SERVER['HTTPS'] ?? '';
  return ($h !== '' && strtolower($h) !== 'off')
      || (($_SERVER['SERVER_PORT'] ?? '') == '443')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// Превращает публичную ссылку на видео RUTUBE/VK Видео/YouTube/WebRestream/EduContent
// в URL официального встраиваемого плеера. Любые другие домены возвращают null и продолжают
// открываться обычной внешней ссылкой. Это одновременно держит iframe в
// строгом списке доверенных видеохостингов.
function external_stream_embed(string $value): ?array {
  $value = trim($value);
  if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return null;

  $parts = parse_url($value);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  $host = strtolower((string)($parts['host'] ?? ''));
  if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
  $host = preg_replace('/^(?:www\.|m\.)/', '', $host);
  $path = (string)($parts['path'] ?? '');
  parse_str((string)($parts['query'] ?? ''), $query);

  // WebRestream выдаёт подписанный URL готового плеера в формате /p/<JWT>.
  // Разрешаем только этот точный маршрут и JWT из трёх base64url-сегментов:
  // короткая ссылка /c/... ведёт в Telegram-бота и плеером не является.
  if ($host === 'webrestream.site') {
    if (!preg_match('~^/p/([a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+)/?$~', $path, $match)) return null;
    return [
      'provider' => 'webrestream',
      'embed_url' => 'https://webrestream.site/p/' . $match[1],
    ];
  }

  // EduContent отдаёт готовую страницу плеера по /watch/<UUID>. Отдельного
  // embed-маршрута у сервиса нет, а сама watch-страница разрешает iframe.
  // Партнёрский идентификатор переносим только в ожидаемом числовом формате.
  if ($host === 'educontent.cloud') {
    if (!preg_match('~^/watch/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/?$~i', $path, $match)) {
      return null;
    }
    $embed = 'https://educontent.cloud/watch/' . strtolower($match[1]);
    if (isset($query['partner']) && is_scalar($query['partner']) && preg_match('/^\d+$/', (string)$query['partner'])) {
      $embed .= '?partner=' . rawurlencode((string)$query['partner']);
    }
    return ['provider' => 'educontent', 'embed_url' => $embed];
  }

  if (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
    $videoId = null;
    if ($host === 'youtu.be' && preg_match('~^/([a-zA-Z0-9_-]{11})/?$~', $path, $match)) {
      $videoId = $match[1];
    } elseif ($host !== 'youtu.be') {
      if ($path === '/watch' && isset($query['v']) && is_string($query['v'])
          && preg_match('/^[a-zA-Z0-9_-]{11}$/', $query['v'])) {
        $videoId = $query['v'];
      } elseif (preg_match('~^/(?:live|embed|shorts)/([a-zA-Z0-9_-]{11})/?$~', $path, $match)) {
        $videoId = $match[1];
      }
    }
    if ($videoId === null) return null;
    return ['provider' => 'youtube', 'embed_url' => 'https://www.youtube.com/embed/' . $videoId];
  }

  if ($host === 'rutube.ru') {
    if (!preg_match('~/(?:play/embed|video(?:/private)?|live/video)/([a-zA-Z0-9_-]{16,})~', $path, $match)) return null;
    $embed = 'https://rutube.ru/play/embed/' . rawurlencode($match[1]);
    // Ключ p нужен для видео/трансляций «только по ссылке»; таймкод безопасно
    // переносим, если администратор вставил ссылку с нужного момента.
    $params = [];
    foreach (['p', 't'] as $key) {
      if (isset($query[$key]) && is_scalar($query[$key]) && preg_match('/^[a-zA-Z0-9_-]+$/', (string)$query[$key])) {
        $params[$key] = (string)$query[$key];
      }
    }
    if ($params) $embed .= '/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return ['provider' => 'rutube', 'embed_url' => $embed];
  }

  // VK Видео Live использует отдельный домен и отдельный embed-маршрут,
  // где идентификатором выступает короткое имя канала.
  if (in_array($host, ['live.vkvideo.ru', 'live.vkplay.ru'], true)) {
    $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (($segments[0] ?? '') === 'app' && ($segments[1] ?? '') === 'embed') $channel = $segments[2] ?? '';
    else $channel = $segments[0] ?? '';
    if ($channel === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $channel)) return null;
    return [
      'provider' => 'vk',
      'embed_url' => 'https://live.vkvideo.ru/app/embed/' . rawurlencode($channel),
    ];
  }

  $vkHosts = ['vk.com', 'vk.ru', 'vkvideo.ru'];
  if (in_array($host, $vkHosts, true)) {
    $ownerId = null;
    $videoId = null;

    if (preg_match('~/video_ext\.php$~', $path)
        && isset($query['oid'], $query['id'])
        && preg_match('/^-?\d+$/', (string)$query['oid'])
        && preg_match('/^\d+$/', (string)$query['id'])) {
      $ownerId = (string)$query['oid'];
      $videoId = (string)$query['id'];
    } else {
      // Поддерживает vk.com/video-1_2, vkvideo.ru/video-1_2 и ссылки, где
      // video-1_2 находится в параметре z/list либо во фрагменте после #.
      $decoded = rawurldecode($value);
      if (preg_match('/(?:video|live)(-?\d+)_(\d+)/i', $decoded, $match)) {
        $ownerId = $match[1];
        $videoId = $match[2];
      }
    }

    if ($ownerId === null || $videoId === null) return null;
    $params = ['oid' => $ownerId, 'id' => $videoId, 'hd' => '2'];
    // Закрытым и доступным по ссылке роликам VK может требоваться hash.
    if (isset($query['hash']) && is_scalar($query['hash']) && preg_match('/^[a-zA-Z0-9_-]+$/', (string)$query['hash'])) {
      $params['hash'] = (string)$query['hash'];
    }
    return [
      'provider' => 'vk',
      'embed_url' => 'https://vk.com/video_ext.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
    ];
  }

  return null;
}

function start_session() {
  $ttl = 60 * 60 * 24 * 90; // 90 дней
  // Чтобы сервер не удалял сессию по простою раньше срока куки.
  ini_set('session.gc_maxlifetime', (string)$ttl);
  session_set_cookie_params([
    'lifetime' => $ttl,
    'path' => '/',
    'secure' => is_https(), // на проде (HTTPS) куку нельзя слать по голому HTTP
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

function body() {
  static $loaded = false;
  static $data = [];
  if ($loaded) return $data;
  $loaded = true;
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  $data = is_array($decoded) ? $decoded : [];
  return $data;
}

// ── Единый формат ответа { ok, data, error } ────────────────
// Низкоуровневый вывод JSON (использовать ok()/fail(), а не его напрямую).
function json_out($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// Успех: { ok:true, data:{...} }. $data — полезная нагрузка (может быть null).
function ok($data = null, $code = 200) {
  json_out(['ok' => true, 'data' => $data], $code);
}

// Ошибка: { ok:false, error:"текст" }. Текст показывается пользователю.
function fail($error, $code = 400) {
  json_out(['ok' => false, 'error' => $error], $code);
}

// Глобальная обработка ошибок/исключений: любой необработанный сбой → { ok:false, error }.
// На проде детали скрываем (отдаём общий текст), в логи пишем всегда.
function install_error_handlers() {
  // Только серьёзные ошибки превращаем в исключения (→ единый 500-ответ).
  // Предупреждения/нотисы (в т.ч. «Undefined array key» в PHP 8) НЕ валят запрос —
  // их логируем/показываем в dev, иначе любой пропущенный ключ ввода давал бы 500.
  set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false; // подавленные (@) — пропускаем
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if ($errno & $fatal) {
      throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    return false; // предупреждение — отдать стандартному обработчику (лог + показ только в dev)
  });

  set_exception_handler(function ($e) {
    error_log('[api] ' . $e);
    if (!headers_sent()) {
      fail(dev_visible() ? ('Ошибка: ' . $e->getMessage()) : 'Внутренняя ошибка сервера', 500);
    }
  });

  // Фатальные ошибки (parse/недостаток памяти и т.п.) — последняя линия обороны.
  register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
      error_log('[api fatal] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
      if (!headers_sent()) {
        fail(dev_visible() ? $e['message'] : 'Внутренняя ошибка сервера', 500);
      }
    }
  });

  // Наружу ошибки печатаем только локально; на сервере — лишь в лог.
  error_reporting(E_ALL);
  ini_set('display_errors', dev_visible() ? '1' : '0');
  ini_set('log_errors', '1');
}

// Публичное представление пользователя (без хеша пароля).
function public_user($u) {
  return [
    'id' => (int)$u['id'],
    'name' => $u['name'],
    'email' => $u['email'],
    'phone' => $u['phone'],
    'role' => $u['role'],
  ];
}

// Проверка пароля с постоянным временем ответа. Если хеша нет (нет такого
// аккаунта) — всё равно гоняем bcrypt против заглушки, чтобы по времени ответа
// нельзя было определить, существует ли логин (анти-перечисление аккаунтов).
const DUMMY_PASSWORD_HASH = '$2y$12$fcYIQGSXMhWsqBLmflRMLuqPvaR.9gJHOrnn/Io.ef2T9Aau3bgqG';
function verify_password(string $password, ?string $hash): bool {
  return password_verify($password, ($hash !== null && $hash !== '') ? $hash : DUMMY_PASSWORD_HASH);
}

// Требует активную админ-сессию, иначе 401.
function require_admin($keepSession = false) {
  if (empty($_SESSION['admin_id'])) fail('Нужен вход в админку', 401);
  $id = (int)$_SESSION['admin_id'];
  // Сразу отпускаем файловую блокировку сессии — иначе параллельные запросы одного
  // пользователя сериализуются (каждый ждёт предыдущий). Это и есть «жду несколько секунд».
  if (!$keepSession) release_session();
  return $id;
}

// Требует активную сессию ученика, иначе 401. Возвращает id ученика.
function require_user($keepSession = false) {
  if (empty($_SESSION['uid'])) fail('Нужен вход', 401);
  if (!empty($_SESSION['impersonation']['expires_at']) && $_SESSION['impersonation']['expires_at'] < gmdate('Y-m-d H:i:s')) {
    unset($_SESSION['uid'], $_SESSION['impersonation']);
    fail('Время просмотра кабинета истекло', 401);
  }
  if (is_readonly_impersonation() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('Кабинет открыт администратором только для просмотра', 403);
  }
  block_if_maintenance(); // во время тех.работ ученические эндпоинты закрыты
  $uid = (int)$_SESSION['uid'];
  // Отпускаем блокировку сессии сразу после авторизации (см. require_admin) — иначе
  // кабинет, шлющий ~7 запросов разом, грузится секундами из-за их сериализации.
  if (!$keepSession) release_session();
  return $uid;
}

function is_readonly_impersonation(): bool {
  return !empty($_SESSION['impersonation']['read_only']);
}

// Есть ли у ученика доступ к курсу (Этап 3). Доступ даёт ТОЛЬКО явное зачисление
// на курс (enrollments) — его выдаёт админ вручную. Подписка сама по себе курсы НЕ
// открывает (это просто тариф). Даты — строкой YYYY-MM-DD (лексикографически = хронологически).
function user_has_course_access(int $uid, int $courseId): bool {
  if ($uid <= 0) return false;
  $today = date('Y-m-d');
  $e = db()->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ?
                      AND (expires_at IS NULL OR expires_at = '' OR expires_at >= ?) LIMIT 1");
  $e->execute([$uid, $courseId, $today]);
  return (bool)$e->fetch();
}

// Предметы, по которым у ученика есть хотя бы один доступный курс (зачисление). Для банка/профиля.
function user_accessible_subjects(int $uid): array {
  if ($uid <= 0) return [];
  $today = date('Y-m-d');
  $st = db()->prepare(
    "SELECT DISTINCT s.id, s.name, s.color
     FROM enrollments e
     JOIN courses c ON c.id = e.course_id
     JOIN subjects s ON s.id = c.subject_id
     WHERE e.user_id = ? AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
       AND COALESCE(s.show_features, 1) = 1
     ORDER BY s.position, s.name"
  );
  $st->execute([$uid, $today]);
  $out = [];
  foreach ($st as $r) $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color'] ?: '#3f9bff'];
  return $out;
}

// Открывает ли подписка ученика повышенный уровень ДЗ (план с hard_access, обычно Стандарт+).
function user_has_hard_access(int $uid): bool {
  if ($uid <= 0) return false;
  $today = date('Y-m-d');
  $st = db()->prepare("SELECT 1 FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                       WHERE s.user_id = ? AND s.status = 'active' AND p.hard_access = 1
                       AND (s.ends_at IS NULL OR s.ends_at = '' OR s.ends_at >= ?) LIMIT 1");
  $st->execute([$uid, $today]);
  return (bool)$st->fetch();
}

// Нормализация текстового ответа для сравнения: нижний регистр, обрезка, ё→е.
function norm_text($s) {
  return str_replace('ё', 'е', mb_strtolower(trim((string)$s)));
}

// Автопроверка одного вопроса. $correct/$value — уже декодированные из JSON.
// Возвращает [is_correct, earned]: для textarea/file/audio — [null, 0] (ручная проверка наставником).
//   single — у вопроса может быть НЕСКОЛЬКО допустимых вариантов (correct — массив индексов
//            или одно число); ответ верный, если выбран любой из допустимых.
//   multi  — режим $mode: 'full' (всё или ничего) | 'partial' (+1 за верный, −1 за неверный, не ниже 0).
//   text   — несколько допустимых строк; $caseSensitive — учитывать ли регистр.
function grade_question(string $type, $correct, $value, int $points, bool $caseSensitive = false, string $mode = 'full'): array {
  if (in_array($type, ['textarea', 'file', 'audio'], true)) return [null, 0];

  // Задания на установление соответствия в ЕГЭ вводятся одной цифровой
  // последовательностью. Балл считаем по официальной пороговой шкале, а не
  // по принципу «всё или ничего».
  if ($type === 'text' && $mode === 'sequence') {
    $answer = preg_replace('/\s+/u', '', (string)$value);
    $accepted = is_array($correct) ? $correct : [$correct];
    $best = 0; $length = 0;
    foreach ($accepted as $candidate) {
      $candidate = preg_replace('/\s+/u', '', (string)$candidate);
      $length = max($length, strlen($candidate));
      $right = 0;
      $limit = min(strlen($answer), strlen($candidate));
      for ($i = 0; $i < $limit; $i++) if ($answer[$i] === $candidate[$i]) $right++;
      $best = max($best, $right);
    }
    $earned = 0;
    if ($length === 6 && $points === 2) $earned = $best >= 5 ? 2 : ($best >= 3 ? 1 : 0);
    elseif ($length === 7 && $points === 3) $earned = $best >= 6 ? 3 : ($best >= 4 ? 2 : ($best >= 3 ? 1 : 0));
    elseif ($length === 7 && $points === 4) $earned = $best >= 6 ? 4 : ($best >= 5 ? 3 : ($best >= 4 ? 2 : ($best >= 3 ? 1 : 0)));
    elseif ($length === 6 && $points === 3) $earned = $best >= 5 ? 3 : ($best >= 4 ? 2 : ($best >= 3 ? 1 : 0));
    else $earned = ($best === $length && $length > 0) ? $points : 0;
    return [$earned === $points, $earned];
  }

  if ($type === 'single') {
    $set = is_array($correct) ? array_map('intval', $correct) : [(int)$correct];
    $ok = ($value !== null && $value !== '' && in_array((int)$value, $set, true));
    return [$ok, $ok ? $points : 0];
  }

  if ($type === 'multi') {
    $sel = is_array($value) ? array_map('intval', $value) : [];
    $cor = is_array($correct) ? array_map('intval', $correct) : [];
    $sel = array_values(array_unique($sel));
    if ($mode === 'partial') {
      $right = count(array_intersect($sel, $cor));   // верно отмеченные
      $wrong = count(array_diff($sel, $cor));         // лишние (неверные)
      $earned = max(0, min($points, $right - $wrong));
      $isFull = ($earned === $points && $right === count($cor) && $wrong === 0);
      return [$isFull, $earned];
    }
    sort($sel); $b = $cor; sort($b);
    $ok = ($sel === $b && count($b) > 0);
    return [$ok, $ok ? $points : 0];
  }

  // text
  $accepted = is_array($correct) ? $correct : [$correct];
  $cmp = $caseSensitive ? fn($s) => trim((string)$s) : fn($s) => norm_text($s);
  foreach ($accepted as $c) if ($cmp($c) === $cmp($value)) return [true, $points];
  return [false, 0];
}

// Проверяет файловый ответ и заменяет присланные клиентом метаданные каноническими из БД.
// Требуются и развёрнутая подпись, и от одного до file_limit файлов этого ученика/вопроса.
function canonical_file_answer(int $uid, int $questionId, $value, int $fileLimit): array {
  $limit = max(1, min(10, $fileLimit));
  if (!is_array($value)) fail('Добавь файлы и подпись для проверяющего', 400);
  $note = trim((string)($value['note'] ?? ''));
  if ($note === '') fail('Напиши развёрнутую подпись для проверяющего', 400);
  if (mb_strlen($note) > 10000) fail('Подпись слишком длинная (максимум 10 000 знаков)', 400);

  $incoming = is_array($value['files'] ?? null) ? $value['files'] : [];
  $ids = [];
  foreach ($incoming as $file) {
    $id = is_array($file) ? (int)($file['id'] ?? 0) : (int)$file;
    if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
  }
  if (count($ids) < 1 || count($ids) > $limit) fail("Прикрепи от 1 до $limit файлов", 400);

  $ph = implode(',', array_fill(0, count($ids), '?'));
  $st = db()->prepare("SELECT id, url, original_name, size, mime FROM answer_uploads WHERE user_id = ? AND question_id = ? AND submission_id IS NULL AND id IN ($ph)");
  $st->execute(array_merge([$uid, $questionId], $ids));
  $found = [];
  foreach ($st as $r) $found[(int)$r['id']] = [
    'id' => (int)$r['id'], 'url' => $r['url'], 'name' => $r['original_name'],
    'size' => (int)$r['size'], 'mime' => $r['mime'],
  ];
  if (count($found) !== count($ids)) fail('Один из файлов не найден или принадлежит другой работе', 400);
  return ['note' => $note, 'files' => array_map(fn($id) => $found[$id], $ids)];
}

// Голосовой ответ использует то же защищённое хранилище, но всегда содержит
// ровно одну аудиозапись и не требует текстовой подписи.
function canonical_audio_answer(int $uid, int $questionId, $value): array {
  if (!is_array($value)) fail('Запиши или загрузи устный ответ', 400);
  $incoming = is_array($value['files'] ?? null) ? $value['files'] : [];
  $ids = [];
  foreach ($incoming as $file) {
    $id = is_array($file) ? (int)($file['id'] ?? 0) : (int)$file;
    if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
  }
  if (count($ids) !== 1) fail('Для устного ответа нужна ровно одна аудиозапись', 400);

  $audioMimes = [
    'audio/mpeg','audio/mp4','video/mp4','audio/x-m4a','audio/ogg','application/ogg','audio/wav',
    'audio/x-wav','audio/webm','video/webm','audio/aac','audio/x-hx-aac-adts',
  ];
  $st = db()->prepare('SELECT id, url, original_name, size, mime FROM answer_uploads WHERE id = ? AND user_id = ? AND question_id = ? AND submission_id IS NULL LIMIT 1');
  $st->execute([$ids[0], $uid, $questionId]);
  $r = $st->fetch();
  if (!$r || !in_array((string)$r['mime'], $audioMimes, true)) fail('Аудиозапись не найдена или имеет неверный формат', 400);
  return ['files' => [[
    'id' => (int)$r['id'], 'url' => $r['url'], 'name' => $r['original_name'],
    'size' => (int)$r['size'], 'mime' => $r['mime'],
  ]]];
}

function bind_answer_files(int $uid, int $questionId, array $value, int $submissionId): void {
  $ids = array_values(array_filter(array_map(fn($f) => (int)($f['id'] ?? 0), $value['files'] ?? [])));
  if (!$ids) return;
  $ph = implode(',', array_fill(0, count($ids), '?'));
  db()->prepare("UPDATE answer_uploads SET submission_id = ? WHERE user_id = ? AND question_id = ? AND submission_id IS NULL AND id IN ($ph)")
      ->execute(array_merge([$submissionId, $uid, $questionId], $ids));
}

// Черновик ответов (автосохранение): загрузка и очистка.
function load_draft(int $uid, string $kind, int $refId) {
  $st = db()->prepare("SELECT answers FROM answer_drafts WHERE user_id = ? AND kind = ? AND ref_id = ? LIMIT 1");
  $st->execute([$uid, $kind, $refId]);
  $row = $st->fetch();
  if (!$row || $row['answers'] === null) return null;
  $d = json_decode($row['answers'], true);
  return is_array($d) ? $d : null;
}
function delete_draft(int $uid, string $kind, int $refId) {
  db()->prepare("DELETE FROM answer_drafts WHERE user_id = ? AND kind = ? AND ref_id = ?")->execute([$uid, $kind, $refId]);
}

// Освобождает блокировку сессии (session_write_close), не теряя уже прочитанные данные.
// PHP по умолчанию держит ЭКСКЛЮЗИВНЫЙ лок на файл сессии до конца скрипта, из-за чего
// параллельные запросы с одной кукой сериализуются (встают в очередь). Read-only
// эндпоинты после проверки доступа в сессию больше не пишут — вызываем это сразу,
// чтобы одновременные загрузки (напр. drill-down в админке) шли конкурентно.
// $_SESSION остаётся доступен для ЧТЕНИЯ (массив уже в памяти), писать в него нельзя.
function release_session() {
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

// ── Настройки приложения (Этап 16) ──
function setting_get($key, $default = null) {
  static $cache = [];
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $s = db()->prepare("SELECT v FROM app_settings WHERE k = ? LIMIT 1");
    $s->execute([$key]);
    $v = $s->fetchColumn();
  } catch (Throwable $e) { $v = false; }
  return $cache[$key] = ($v === false ? $default : $v);
}
function setting_set($key, $val) {
  $d = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($d === 'mysql') db()->prepare("INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$key, $val]);
  else db()->prepare("INSERT OR REPLACE INTO app_settings (k, v) VALUES (?, ?)")->execute([$key, $val]);
}

// Версия живых данных кабинета. Клиент запрашивает только эту короткую строку и
// перечитывает содержимое открытого раздела лишь когда версия действительно сменилась.
function live_content_version(): string {
  return (string)setting_get('live_content_version', '1');
}

function live_content_touch(): void {
  // Случайный хвост исключает совпадение двух сохранений в одну микросекунду.
  setting_set('live_content_version', sprintf('%.6F', microtime(true)) . '-' . bin2hex(random_bytes(4)));
}

// Все успешные изменения через админские POST-эндпоинты автоматически сообщают
// открытым кабинетам, что данные обновились. Авторизация и вход контентом не являются.
function register_admin_live_content_touch(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
  $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  // Часть исторических админских мутаций названа без префикса admin_.
  $adminMutations = ['users_create.php', 'users_update.php', 'users_delete.php', 'review_grade.php'];
  if (!str_starts_with($script, 'admin_') && !in_array($script, $adminMutations, true)) return;
  if (in_array($script, ['admin_login.php', 'admin_logout.php', 'admin_impersonate_start.php'], true)) return;

  // Каталог читает данные POST-запросом с action=list. Это не изменение:
  // не будим все открытые кабинеты при обычном просмотре админки.
  if ($script === 'admin_catalog.php' && ((body()['action'] ?? '') === 'list')) return;

  register_shutdown_function(function (): void {
    $code = http_response_code();
    if ($code === false) $code = 200;
    if ($code < 200 || $code >= 400 || empty($_SESSION['admin_id'])) return;
    try { live_content_touch(); }
    catch (Throwable $e) { error_log('[live content touch] ' . $e->getMessage()); }
  });
}
function is_maintenance() { return setting_get('maintenance', '0') === '1'; }
// Закрыть ученические эндпоинты во время тех.работ (админ — мимо). Зовётся из require_user/me.
function block_if_maintenance() {
  if (is_maintenance()) fail(setting_get('maintenance_text', 'Идут технические работы. Скоро вернёмся!'), 503);
}

// Создать уведомление ученику (Этап 13). link — ключ раздела ЛК (homework/shop/mentor/...).
// Никогда не ломает вызывающий код.
function notify(int $uid, string $type, string $text, ?string $link = null): void {
  try {
    db()->prepare("INSERT INTO notifications (user_id, type, text, link, is_read) VALUES (?,?,?,?,0)")
        ->execute([$uid, $type, mb_substr($text, 0, 400), $link]);
  } catch (Throwable $e) { error_log('[notify] ' . $e->getMessage()); }
}

// Нормализация телефона к каноничному виду +7XXXXXXXXXX (РФ).
// Принимает любую маску: '+7 (916) 111-22-33', '8 916 …', '9161112233'.
// Возвращает '' если это не похоже на номер (чтобы хранить NULL и не матчить пустое).
function normalize_phone($raw) {
  $d = preg_replace('/\D+/', '', (string)$raw); // только цифры
  if ($d === '') return '';
  if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1); // 8XXXX → 7XXXX
  elseif (strlen($d) === 10)              $d = '7' . $d;            // без кода страны
  if (strlen($d) !== 11 || $d[0] !== '7') return '';               // не РФ-номер
  return '+' . $d;
}

// Как normalize_phone, но если цифры введены, а номер не распознан (напр. неполный) —
// явная ошибка вместо тихого сохранения NULL. Использовать там, где телефон вводит человек.
function clean_phone($raw) {
  $n = normalize_phone($raw);
  if ($n === '' && preg_match('/\d/', (string)$raw)) {
    fail('Проверьте номер телефона — нужно 10 цифр после +7', 400);
  }
  return $n;
}
