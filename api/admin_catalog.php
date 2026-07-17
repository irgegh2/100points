<?php
// POST /admin_catalog.php  { entity, action, data }
// Единый CRUD каталога для админки (Этап 14, по сущностям Этапа 2).
//   entity ∈ subjects|courses|months|blocks|lessons|materials|timecodes
//   action ∈ list|create|update|delete
// Безопасность: только админ; пишем ТОЛЬКО колонки из белого списка $SCHEMA
// (имя сущности = имя таблицы, проверяется по ключам $SCHEMA — SQL-инъекция в имя таблицы исключена).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session(); // ни одно действие каталога не пишет в сессию — снимаем лок для параллельных запросов

// Белый список: какие поля можно писать, по какому полю фильтровать list,
// и какие дочерние таблицы удалять каскадом (childTable, foreign key).
$SCHEMA = [
  'subjects'  => ['fields' => ['name','slug','color','icon','show_features','position'],
                  'parent' => null,         'children' => [['courses','subject_id']]],
  'courses'   => ['fields' => ['subject_id','title','type','color','cover','price','position'],
                  'parent' => 'subject_id', 'children' => [['months','course_id']]],
  'months'    => ['fields' => ['course_id','name','position'],
                  'parent' => 'course_id',  'children' => [['blocks','month_id']]],
  'blocks'    => ['fields' => ['course_id','month_id','n','title','position'],
                  'parent' => 'month_id',   'children' => [['lessons','block_id']]],
  'lessons'   => ['fields' => ['block_id','type','title','meta','kinescope_id','air','air_at','duration','locked_hint','position'],
                  'parent' => 'block_id',   'children' => [['materials','lesson_id'],['timecodes','lesson_id']]],
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
  'questions' => ['fields' => ['homework_id','mock_id','subtopic_id','difficulty','type','text','options','correct','solution','points','optional','hard','case_sensitive','grade_mode','file_limit','position'],
                  'parent' => 'homework_id','children' => []],
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
  // Задачи ИИ-тренажёра (Этап 8). level — сложность, answer — эталон, model — какая модель разбирает.
  'trainer_tasks' => ['fields' => ['subject_id','level','text','answer','solution','model','position'],
                  'parent' => 'subject_id','children' => []],
  // Темы-пулы для режима «Вопрос-ответ» (ИИ генерирует вопросы по теме).
  'trainer_qa_topics' => ['fields' => ['subject_id','level','name','examples','model','position'],
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
$INT_FIELDS = ['subject_id','course_id','month_id','block_id','lesson_id','homework_id','mock_id','assignment_id','user_id','subtopic_id','topic_id','source_id','variant_id','question_id','category_id','price','position','duration','n','number','error_rate','difficulty','points','optional','hard','hard_access','case_sensitive','file_limit','level','show_features','once_only','active','reward','target'];

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

// Рекурсивное каскадное удаление поддерева (entity = имя таблицы из $SCHEMA).
function delete_entity(string $entity, int $id, array $schema) {
  foreach ($schema[$entity]['children'] as [$childTable, $fk]) {
    $ids = db()->prepare("SELECT id FROM $childTable WHERE $fk = ?");
    $ids->execute([$id]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) delete_entity($childTable, (int)$cid, $schema);
  }
  db()->prepare("DELETE FROM $entity WHERE id = ?")->execute([$id]);
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
  delete_entity($entity, $id, $SCHEMA);
  ok(['deleted' => $id]);
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
