<?php
// POST /admin_catalog.php  { entity, action, data }
// Единый CRUD каталога для админки (Этап 14, по сущностям Этапа 2).
//   entity ∈ subjects|courses|months|blocks|lessons|materials|timecodes
//   action ∈ list|create|update|delete
// Безопасность: только админ; пишем ТОЛЬКО колонки из белого списка $SCHEMA
// (имя сущности = имя таблицы, проверяется по ключам $SCHEMA — SQL-инъекция в имя таблицы исключена).
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/delete_helpers.php';
require_admin();
release_session(); // ни одно действие каталога не пишет в сессию — снимаем лок для параллельных запросов

// Белый список: какие поля можно писать, по какому полю фильтровать list,
// и какие дочерние таблицы удалять каскадом (childTable, foreign key).
$SCHEMA = [
  'subjects'  => ['fields' => ['name','slug','color','icon','show_features','position'],
                  'parent' => null,         'children' => [['courses','subject_id']]],
  'courses'   => ['fields' => ['subject_id','title','type','color','cover','price','teacher_channel_url','position'],
                  'parent' => 'subject_id', 'children' => [['months','course_id']]],
  'months'    => ['fields' => ['course_id','name','kind','starts_on','ends_on','gallery_label','position'],
                  'parent' => 'course_id',  'children' => [['blocks','month_id'],['month_photos','month_id']]],
  // Фото-расписание месяца (создание — через admin_month_photo_upload.php).
  'month_photos' => ['fields' => ['month_id','url','position'],
                  'parent' => 'month_id',   'children' => []],
  'blocks'    => ['fields' => ['course_id','month_id','n','title','position'],
                  'parent' => 'month_id',   'children' => [['lessons','block_id']]],
  'lessons'   => ['fields' => ['block_id','parent_lesson_id','type','title','meta','kinescope_id','video_source','video_id','video_url','air','air_at','duration','locked_hint','description','opens_at','restream_url','restream_duration','position'],
                  'parent' => 'block_id',   'children' => [['lessons','parent_lesson_id'],['materials','lesson_id'],['timecodes','lesson_id'],['lesson_prereqs','lesson_id'],['lesson_prereqs','required_lesson_id']]],
  // Блокировка урока: какие уроки нужно пройти, чтобы он открылся.
  'lesson_prereqs' => ['fields' => ['lesson_id','required_lesson_id','position'],
                  'parent' => 'lesson_id',  'children' => []],
  'materials' => ['fields' => ['lesson_id','name','size','url','kind','position'],
                  'parent' => 'lesson_id',  'children' => []],
  'timecodes' => ['fields' => ['lesson_id','t','label','position'],
                  'parent' => 'lesson_id',  'children' => []],
  // Тарифы (Этап 3). features — JSON-строка массива фич (фронт кодирует/декодирует).
  'plans'     => ['fields' => ['name','tagline','price','features','badge','hard_access','position'],
                  'parent' => null,         'children' => []],
  // ДЗ и вопросы (Этап 4). options/correct — JSON-строки (фронт кодирует).
  'homeworks' => ['fields' => ['lesson_id','level','title','deadline','position'],
                  'parent' => 'lesson_id',  'children' => [['questions','homework_id']]],
  'questions' => ['fields' => ['homework_id','mock_id','subtopic_id','difficulty','type','text','options','correct','solution','hint','points','optional','hard','case_sensitive','grade_mode','file_limit','position'],
                  'parent' => 'homework_id','children' => [['question_attachments','question_id']]],
  // Вложения вопроса: image | audio | video (Kinescope) | file.
  // Файлы создаются через upload-эндпоинт, Kinescope-видео — здесь по ссылке.
  'question_attachments' => ['fields' => ['question_id','kind','name','size','url','position'],
                  'parent' => 'question_id','children' => []],
  // Пробники (Этап 6). Привязаны к курсу. month — метка месяца («Март», «Март 2», «Март Про»).
  'mock_exams'=> ['fields' => ['course_id','month','title','format','access_mode','position'],
                  'parent' => 'course_id','children' => [['questions','mock_id'],['mock_assignments','mock_id']]],
  'mock_assignments' => ['fields' => ['mock_id','user_id','starts_at','duration','status'],
                  'parent' => 'mock_id','children' => [['proctored_mock_presence','assignment_id'],['proctored_mock_messages','assignment_id'],['proctored_mock_events','assignment_id'],['proctored_mock_signals','assignment_id']]],
  'proctored_mock_presence' => ['fields' => ['assignment_id'], 'parent' => 'assignment_id', 'children' => []],
  'proctored_mock_messages' => ['fields' => ['assignment_id'], 'parent' => 'assignment_id', 'children' => []],
  'proctored_mock_events' => ['fields' => ['assignment_id'], 'parent' => 'assignment_id', 'children' => []],
  'proctored_mock_signals' => ['fields' => ['assignment_id'], 'parent' => 'assignment_id', 'children' => []],
  // Банк заданий (Этап 7): темы (номера заданий) → подтемы → вопросы; источники → варианты.
  'topics'    => ['fields' => ['subject_id','number','name','part','error_rate','position'],
                  'parent' => 'subject_id','children' => [['subtopics','topic_id']]],
  'subtopics' => ['fields' => ['topic_id','name','position'],
                  'parent' => 'topic_id','children' => [['questions','subtopic_id']]],
  'bank_sources'  => ['fields' => ['subject_id','name','position'],
                  'parent' => 'subject_id','children' => [['bank_variants','source_id']]],
  'bank_variants' => ['fields' => ['source_id','subject_id','name','position'],
                  'parent' => 'source_id','children' => [['variant_questions','variant_id']]],
  // Состав варианта-пробника: связи вариант↔вопрос банка (с порядком).
  'variant_questions' => ['fields' => ['variant_id','question_id','position'],
                  'parent' => 'variant_id','children' => []],
  // Задачи ИИ-тренажёра. Модель единая для всей платформы и меняется в настройках.
  'trainer_tasks' => ['fields' => ['subject_id','level','text','answer','solution','position'],
                  'parent' => 'subject_id','children' => []],
  // Темы-пулы для режима «Вопрос-ответ» (ИИ генерирует вопросы по теме).
  'trainer_qa_topics' => ['fields' => ['subject_id','level','name','examples','position'],
                  'parent' => 'subject_id','children' => []],
  // База знаний техподдержки (для ИИ-чата «Помощь по платформе»).
  'help_categories' => ['fields' => ['name','position'],
                  'parent' => null, 'children' => [['help_qa','category_id']]],
  'help_qa' => ['fields' => ['category_id','question','answer','position'],
                  'parent' => 'category_id','children' => []],
  // Товары магазина (Этап 10). once_only — покупается один раз; active — в продаже.
  'shop_items' => ['fields' => ['name','description','category','price','once_only','badge','active','position'],
                  'parent' => null, 'children' => []],
  // События расписания (Этап 11). Привязаны к курсу (гейтинг по зачислению).
  'events' => ['fields' => ['course_id','type','title','starts_at','duration','link','position'],
                  'parent' => 'course_id', 'children' => []],
  // Каталог достижений (Этап 9). code — уникальный ключ-триггер (менять осторожно!).
  'achievements' => ['fields' => ['code','title','description','icon','reward','target','position'],
                  'parent' => null, 'children' => [['user_achievements','achievement_id']]],
  // Библиотека (Этап 15). subject_id NULL = общий материал.
  'library_items' => ['fields' => ['subject_id','category','title','url','size','kind','position'],
                  'parent' => 'subject_id', 'children' => []],
];

// Целочисленные поля — приводим к int (или null), чтобы в БД не падал мусор.
// ВАЖНО: 'level' здесь быть НЕ должно — у homeworks это строка ('normal'|'medium'|'hard'),
// int-каст превращал её в 0 при любом сохранении ДЗ (числовой level тренажёра БД приведёт сама).
$INT_FIELDS = ['subject_id','course_id','month_id','block_id','lesson_id','parent_lesson_id','video_id','homework_id','mock_id','assignment_id','user_id','subtopic_id','topic_id','source_id','variant_id','question_id','category_id','price','position','duration','restream_duration','n','number','error_rate','difficulty','points','optional','hard','hard_access','case_sensitive','file_limit','show_features','once_only','active','reward','target'];

$in = body();
$entity = (string)($in['entity'] ?? '');
$action = (string)($in['action'] ?? '');
$data   = is_array($in['data'] ?? null) ? $in['data'] : [];

if (!isset($SCHEMA[$entity])) fail('Неизвестная сущность', 400);
$spec = $SCHEMA[$entity];

// Готовит пару [columns, values] из data по белому списку (только переданные ключи).
function pick_fields(array $data, array $allowed, array $intFields): array {
  $cols = []; $vals = [];
  foreach ($allowed as $f) {
    if (!array_key_exists($f, $data)) continue;
    $v = $data[$f];
    if (in_array($f, $intFields, true)) $v = ($v === '' || $v === null) ? null : (int)$v;
    elseif (is_string($v))              $v = trim($v);
    $cols[] = $f; $vals[] = $v;
  }
  return [$cols, $vals];
}

// Поле kinescope_id принимает и ссылку, и embed-код целиком — храним только чистый id ролика.
if ($entity === 'lessons' && isset($data['kinescope_id']) && is_string($data['kinescope_id'])
    && preg_match('~kinescope\.io/(?:embed/)?([\w-]+)~', $data['kinescope_id'], $m)) {
  $data['kinescope_id'] = $m[1];
}

// Вложенные занятия допускаются только на один уровень и наследуют блок родителя.
// Проверка на сервере обязательна: интерфейс не должен быть единственным ограничителем.
if ($entity === 'lessons' && in_array($action, ['create', 'update'], true)
    && array_key_exists('parent_lesson_id', $data) && (int)($data['parent_lesson_id'] ?? 0) > 0) {
  $parentId = (int)$data['parent_lesson_id'];
  $parentSt = db()->prepare('SELECT id,block_id,parent_lesson_id FROM lessons WHERE id=? LIMIT 1');
  $parentSt->execute([$parentId]);
  $parent = $parentSt->fetch();
  if (!$parent || !empty($parent['parent_lesson_id'])) fail('Нельзя вкладывать занятие глубже одного уровня', 400);
  if ($action === 'update' && (int)($data['id'] ?? 0) === $parentId) fail('Урок не может быть вложен сам в себя', 400);
  $data['block_id'] = (int)$parent['block_id'];
  if ($action === 'create') {
    $countSt = db()->prepare('SELECT COUNT(*) FROM lessons WHERE parent_lesson_id=?');
    $countSt->execute([$parentId]);
    if ((int)$countSt->fetchColumn() >= 10) fail('В одном уроке может быть не больше 10 занятий', 400);
  }
}

// Внешние ссылки из админки выводятся ученику как href. Разрешаем только
// обычные http(s)-адреса, чтобы javascript:/data: не попали в интерфейс.
foreach (['teacher_channel_url', 'restream_url', 'video_url'] as $urlField) {
  if (!array_key_exists($urlField, $data)) continue;
  $url = trim((string)$data[$urlField]);
  if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
    fail('Нужна полная ссылка, начинающаяся с http:// или https://', 400);
  }
  $data[$urlField] = $url;
}
if (array_key_exists('restream_duration', $data)) {
  $minutes = (int)$data['restream_duration'];
  if ($minutes < 1 || $minutes > 1440) fail('Длительность Restream должна быть от 1 до 1440 минут', 400);
  $data['restream_duration'] = $minutes;
}

// Видеовложение принимает только Kinescope. Не позволяем админской форме
// превратить его в произвольный iframe или внешний трекер.
if ($entity === 'question_attachments' && in_array($action, ['create', 'update'], true)) {
  $kind = trim((string)($data['kind'] ?? ''));
  if ($action === 'create' && $kind !== 'video') {
    fail('Картинки, аудио и файлы добавляются через загрузку файла', 400);
  }
  if ($kind === 'video' || ($action === 'update' && array_key_exists('url', $data))) {
    $source = trim((string)($data['url'] ?? ''));
    if (!preg_match('~(?:player\.)?kinescope\.io/(?:embed/)?([\w-]+)~i', $source, $m)
        && !preg_match('/^[\w-]{8,}$/', $source, $m)) {
      fail('Нужна ссылка на видео Kinescope', 400);
    }
    $id = isset($m[1]) ? $m[1] : $m[0];
    $data['kind'] = 'video';
    $data['url'] = 'https://kinescope.io/embed/' . $id;
    $data['name'] = 'Видео к заданию';
    $data['size'] = 'Kinescope';
  }
}

if ($action === 'list') {
  // Фильтр по любому переданному id-полю из whitelist (parent, homework_id, mock_id и т.п.).
  $sql = "SELECT * FROM $entity";
  $where = []; $args = [];
  foreach ($spec['fields'] as $f) {
    if (!in_array($f, $INT_FIELDS, true)) continue;          // только числовые id-поля
    if (substr($f, -3) !== '_id') continue;                   // именно внешние ключи
    if (isset($data[$f]) && $data[$f] !== '') { $where[] = "$f = ?"; $args[] = (int)$data[$f]; }
  }
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= " ORDER BY position, id";
  $stmt = db()->prepare($sql);
  $stmt->execute($args);
  ok(['items' => $stmt->fetchAll()]);
}

if ($action === 'create') {
  [$cols, $vals] = pick_fields($data, $spec['fields'], $INT_FIELDS);
  if (!$cols) fail('Нет данных для создания', 400);
  $ph = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT INTO $entity (" . implode(',', $cols) . ") VALUES ($ph)";
  db()->prepare($sql)->execute($vals);
  $id = (int)db()->lastInsertId();
  $row = db()->prepare("SELECT * FROM $entity WHERE id = ?");
  $row->execute([$id]);
  ok(['item' => $row->fetch()], 201);
}

if ($action === 'update') {
  $id = (int)($data['id'] ?? 0);
  if ($id <= 0) fail('Не указан id', 400);
  [$cols, $vals] = pick_fields($data, $spec['fields'], $INT_FIELDS);
  if (!$cols) fail('Нет полей для обновления', 400);
  $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
  $vals[] = $id;
  db()->prepare("UPDATE $entity SET $set WHERE id = ?")->execute($vals);
  $row = db()->prepare("SELECT * FROM $entity WHERE id = ?");
  $row->execute([$id]);
  ok(['item' => $row->fetch()]);
}

if ($action === 'delete') {
  $id = (int)($data['id'] ?? 0);
  if ($id <= 0) fail('Не указан id', 400);
  try {
    $pdo = db(); $pdo->beginTransaction();
    $deleted = delete_root_row($pdo, $entity, $id);
    if ($deleted < 1) { $pdo->rollBack(); fail('Объект уже удалён или не найден', 404); }
    $pdo->commit();
    ok(['deleted' => $id, 'rows' => $deleted]);
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[admin delete] ' . $entity . '#' . $id . ': ' . $e->getMessage());
    fail('Удаление остановлено: связанные данные не удалось безопасно очистить', 409);
  }
}

if ($action === 'reorder') {
  // data.ids — массив id в НОВОМ порядке; проставляем position = индекс.
  $ids = is_array($data['ids'] ?? null) ? $data['ids'] : [];
  if (!$ids) fail('Пустой список для сортировки', 400);
  try {
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE $entity SET position = ? WHERE id = ?");
    foreach ($ids as $i => $rid) $st->execute([$i, (int)$rid]);
    $pdo->commit();
  } catch (Exception $e) {
    if (db()->inTransaction()) db()->rollBack();
    fail('Не удалось сохранить порядок', 500);
  }
  ok(['reordered' => count($ids)]);
}

fail('Неизвестное действие', 400);
