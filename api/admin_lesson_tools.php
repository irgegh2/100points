<?php
// Инструменты урока в админке:
//   GET  — дерево всех курсов/месяцев/блоков/уроков для выбора назначения;
//   POST — duplicate | move | copy_fields.
// Копируется только структура контента. Ответы, сдачи и прогресс учеников
// никогда не переносятся и не дублируются.
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$pdo = db();

function lt_lesson(PDO $pdo, int $id): array {
  $st = $pdo->prepare(
    "SELECT l.*, b.course_id, b.month_id, b.n AS block_n, b.title AS block_title,
            m.name AS month_name, c.title AS course_title
     FROM lessons l
     JOIN blocks b ON b.id = l.block_id
     JOIN months m ON m.id = b.month_id
     JOIN courses c ON c.id = b.course_id
     WHERE l.id = ?"
  );
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) fail('Урок не найден', 404);
  foreach (['id','block_id','course_id','month_id','block_n','position'] as $key) {
    if (array_key_exists($key, $row)) $row[$key] = (int)$row[$key];
  }
  return $row;
}

function lt_block(PDO $pdo, int $id): array {
  $st = $pdo->prepare(
    "SELECT b.id, b.course_id, b.month_id, b.n, b.title,
            m.name AS month_name, c.title AS course_title
     FROM blocks b
     JOIN months m ON m.id = b.month_id
     JOIN courses c ON c.id = b.course_id
     WHERE b.id = ?"
  );
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) fail('Раздел назначения не найден', 404);
  foreach (['id','course_id','month_id','n'] as $key) $row[$key] = (int)$row[$key];
  return $row;
}

function lt_next_position(PDO $pdo, string $table, string $parent, int $parentId): int {
  $allowed = [
    'lessons:block_id', 'materials:lesson_id', 'timecodes:lesson_id',
    'homeworks:lesson_id', 'questions:homework_id',
    'question_attachments:question_id', 'lesson_prereqs:lesson_id',
  ];
  if (!in_array($table . ':' . $parent, $allowed, true)) throw new RuntimeException('Недопустимая связь');
  $st = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM $table WHERE $parent = ?");
  $st->execute([$parentId]);
  return (int)$st->fetchColumn();
}

function lt_insert(PDO $pdo, string $table, array $data): int {
  $allowedTables = ['lessons','materials','timecodes','homeworks','questions','question_attachments','lesson_prereqs'];
  if (!in_array($table, $allowedTables, true)) throw new RuntimeException('Недопустимая таблица');
  foreach (array_keys($data) as $column) {
    if (!preg_match('/^[a-z_]+$/', $column)) throw new RuntimeException('Недопустимое поле');
  }
  $columns = array_keys($data);
  $sql = "INSERT INTO $table (" . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
  $pdo->prepare($sql)->execute(array_values($data));
  return (int)$pdo->lastInsertId();
}

function lt_clone_materials(PDO $pdo, int $sourceId, int $targetId): int {
  $st = $pdo->prepare('SELECT name,size,url,kind FROM materials WHERE lesson_id=? ORDER BY position,id');
  $st->execute([$sourceId]);
  $position = lt_next_position($pdo, 'materials', 'lesson_id', $targetId);
  $count = 0;
  foreach ($st as $row) {
    lt_insert($pdo, 'materials', [
      'lesson_id' => $targetId, 'name' => $row['name'], 'size' => $row['size'],
      'url' => $row['url'], 'kind' => $row['kind'], 'position' => $position++,
    ]);
    $count++;
  }
  return $count;
}

function lt_clone_timecodes(PDO $pdo, int $sourceId, int $targetId): int {
  $st = $pdo->prepare('SELECT t,label FROM timecodes WHERE lesson_id=? ORDER BY position,id');
  $st->execute([$sourceId]);
  $position = lt_next_position($pdo, 'timecodes', 'lesson_id', $targetId);
  $count = 0;
  foreach ($st as $row) {
    lt_insert($pdo, 'timecodes', [
      'lesson_id' => $targetId, 't' => $row['t'], 'label' => $row['label'], 'position' => $position++,
    ]);
    $count++;
  }
  return $count;
}

function lt_clone_question(PDO $pdo, array $question, int $targetHomeworkId, int $position): int {
  $newId = lt_insert($pdo, 'questions', [
    'homework_id' => $targetHomeworkId,
    'mock_id' => null,
    'subtopic_id' => $question['subtopic_id'],
    'difficulty' => $question['difficulty'],
    'type' => $question['type'],
    'text' => $question['text'],
    'options' => $question['options'],
    'correct' => $question['correct'],
    'solution' => $question['solution'],
    'hint' => $question['hint'],
    'points' => $question['points'],
    'optional' => $question['optional'],
    'hard' => $question['hard'],
    'case_sensitive' => $question['case_sensitive'],
    'grade_mode' => $question['grade_mode'],
    'file_limit' => $question['file_limit'],
    'position' => $position,
  ]);

  $at = $pdo->prepare('SELECT kind,name,size,url FROM question_attachments WHERE question_id=? ORDER BY position,id');
  $at->execute([(int)$question['id']]);
  $attachmentPosition = 0;
  foreach ($at as $row) {
    lt_insert($pdo, 'question_attachments', [
      'question_id' => $newId, 'kind' => $row['kind'], 'name' => $row['name'],
      'size' => $row['size'], 'url' => $row['url'], 'position' => $attachmentPosition++,
    ]);
  }
  return $newId;
}

function lt_clone_homeworks(PDO $pdo, int $sourceId, int $targetId): array {
  $homeworks = $pdo->prepare('SELECT id,level,title,deadline FROM homeworks WHERE lesson_id=? ORDER BY position,id');
  $homeworks->execute([$sourceId]);
  $homeworkPosition = lt_next_position($pdo, 'homeworks', 'lesson_id', $targetId);
  $questionCount = 0;
  $homeworkCount = 0;
  foreach ($homeworks as $homework) {
    $newHomeworkId = lt_insert($pdo, 'homeworks', [
      'lesson_id' => $targetId, 'level' => $homework['level'], 'title' => $homework['title'],
      'deadline' => $homework['deadline'], 'position' => $homeworkPosition++,
    ]);
    $questions = $pdo->prepare(
      'SELECT id,subtopic_id,difficulty,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit
       FROM questions WHERE homework_id=? ORDER BY position,id'
    );
    $questions->execute([(int)$homework['id']]);
    $questionPosition = 0;
    foreach ($questions as $question) {
      lt_clone_question($pdo, $question, $newHomeworkId, $questionPosition++);
      $questionCount++;
    }
    $homeworkCount++;
  }
  return ['homeworks' => $homeworkCount, 'questions' => $questionCount];
}

function lt_clone_prereqs(PDO $pdo, int $sourceId, int $targetId, bool $replace = false): int {
  if ($replace) $pdo->prepare('DELETE FROM lesson_prereqs WHERE lesson_id=?')->execute([$targetId]);
  $st = $pdo->prepare('SELECT required_lesson_id FROM lesson_prereqs WHERE lesson_id=? ORDER BY position,id');
  $st->execute([$sourceId]);
  $position = lt_next_position($pdo, 'lesson_prereqs', 'lesson_id', $targetId);
  $count = 0;
  foreach ($st as $row) {
    $requiredId = (int)$row['required_lesson_id'];
    if ($requiredId === $targetId) continue;
    lt_insert($pdo, 'lesson_prereqs', [
      'lesson_id' => $targetId, 'required_lesson_id' => $requiredId, 'position' => $position++,
    ]);
    $count++;
  }
  return $count;
}

function lt_catalog(PDO $pdo): void {
  $courses = $pdo->query('SELECT id,title FROM courses ORDER BY position,id')->fetchAll();
  $months = $pdo->query('SELECT id,course_id,name,kind FROM months ORDER BY position,id')->fetchAll();
  $blocks = $pdo->query('SELECT id,course_id,month_id,n,title FROM blocks ORDER BY position,id')->fetchAll();
  $lessons = $pdo->query('SELECT id,block_id,title,type FROM lessons ORDER BY position,id')->fetchAll();

  $lessonsByBlock = [];
  foreach ($lessons as $lesson) {
    $lesson['id'] = (int)$lesson['id']; $lesson['block_id'] = (int)$lesson['block_id'];
    $lessonsByBlock[$lesson['block_id']][] = $lesson;
  }
  $blocksByMonth = [];
  foreach ($blocks as $block) {
    foreach (['id','course_id','month_id','n'] as $key) $block[$key] = (int)$block[$key];
    $block['lessons'] = $lessonsByBlock[$block['id']] ?? [];
    $blocksByMonth[$block['month_id']][] = $block;
  }
  $monthsByCourse = [];
  foreach ($months as $month) {
    $month['id'] = (int)$month['id']; $month['course_id'] = (int)$month['course_id'];
    $month['blocks'] = $blocksByMonth[$month['id']] ?? [];
    $monthsByCourse[$month['course_id']][] = $month;
  }
  foreach ($courses as &$course) {
    $course['id'] = (int)$course['id'];
    $course['months'] = $monthsByCourse[$course['id']] ?? [];
  }
  unset($course);
  ok(['courses' => $courses]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') lt_catalog($pdo);

$in = body();
$action = (string)($in['action'] ?? '');
$sourceId = (int)($in['source_lesson_id'] ?? 0);
if ($sourceId <= 0) fail('Не выбран исходный урок', 400);
$source = lt_lesson($pdo, $sourceId);

try {
  $pdo->beginTransaction();

  if ($action === 'duplicate') {
    $targetBlock = lt_block($pdo, (int)($in['target_block_id'] ?? 0));
    $title = trim((string)($in['title'] ?? '')) ?: ((string)$source['title'] . ' — копия');
    $newId = lt_insert($pdo, 'lessons', [
      'block_id' => $targetBlock['id'], 'type' => $source['type'], 'title' => $title,
      'meta' => $source['meta'], 'kinescope_id' => $source['kinescope_id'], 'air' => $source['air'],
      'air_at' => $source['air_at'], 'duration' => $source['duration'], 'locked_hint' => $source['locked_hint'],
      'description' => $source['description'], 'opens_at' => $source['opens_at'],
      'position' => lt_next_position($pdo, 'lessons', 'block_id', $targetBlock['id']),
    ]);
    $stats = [
      'materials' => lt_clone_materials($pdo, $sourceId, $newId),
      'timecodes' => lt_clone_timecodes($pdo, $sourceId, $newId),
    ];
    $stats += lt_clone_homeworks($pdo, $sourceId, $newId);
    $warning = null;
    if ((int)$source['course_id'] === (int)$targetBlock['course_id']) {
      $stats['prereqs'] = lt_clone_prereqs($pdo, $sourceId, $newId);
    } else {
      $stats['prereqs'] = 0;
      $warning = 'Условия открытия не скопированы: копия находится в другом курсе.';
    }
    $pdo->commit();
    ok(['lesson_id' => $newId, 'title' => $title, 'stats' => $stats, 'warning' => $warning]);
  }

  if ($action === 'move') {
    $targetBlock = lt_block($pdo, (int)($in['target_block_id'] ?? 0));
    if ((int)$source['block_id'] === (int)$targetBlock['id']) fail('Урок уже находится в этом разделе', 400);
    $crossCourse = (int)$source['course_id'] !== (int)$targetBlock['course_id'];
    $removedPrereqs = 0;
    if ($crossCourse) {
      $count = $pdo->prepare('SELECT COUNT(*) FROM lesson_prereqs WHERE lesson_id=? OR required_lesson_id=?');
      $count->execute([$sourceId, $sourceId]);
      $removedPrereqs = (int)$count->fetchColumn();
      $pdo->prepare('DELETE FROM lesson_prereqs WHERE lesson_id=? OR required_lesson_id=?')->execute([$sourceId, $sourceId]);
    }
    $update = $pdo->prepare('UPDATE lessons SET block_id=?, position=? WHERE id=?');
    $update->execute([$targetBlock['id'], lt_next_position($pdo, 'lessons', 'block_id', $targetBlock['id']), $sourceId]);
    $pdo->commit();
    ok([
      'lesson_id' => $sourceId, 'cross_course' => $crossCourse,
      'removed_prereqs' => $removedPrereqs,
      'warning' => $crossCourse && $removedPrereqs > 0 ? 'Связи открытия со старым курсом сняты.' : null,
    ]);
  }

  if ($action === 'copy_fields') {
    $targetId = (int)($in['target_lesson_id'] ?? 0);
    if ($targetId <= 0 || $targetId === $sourceId) fail('Выберите другой урок назначения', 400);
    $target = lt_lesson($pdo, $targetId);
    $requested = is_array($in['fields'] ?? null) ? $in['fields'] : [];
    $scalarAllowed = ['title','type','meta','description','kinescope_id','duration','air','air_at','opens_at'];
    $scalars = array_values(array_intersect($scalarAllowed, $requested));
    if (!$requested) fail('Выберите хотя бы одно поле', 400);
    if ($scalars) {
      $set = implode(', ', array_map(fn($field) => "$field=?", $scalars));
      $values = array_map(fn($field) => $source[$field], $scalars);
      $values[] = $targetId;
      $pdo->prepare("UPDATE lessons SET $set WHERE id=?")->execute($values);
    }
    $stats = [];
    if (in_array('materials', $requested, true)) $stats['materials'] = lt_clone_materials($pdo, $sourceId, $targetId);
    if (in_array('timecodes', $requested, true)) $stats['timecodes'] = lt_clone_timecodes($pdo, $sourceId, $targetId);
    if (in_array('homeworks', $requested, true)) $stats += lt_clone_homeworks($pdo, $sourceId, $targetId);
    $warning = null;
    if (in_array('prereqs', $requested, true)) {
      if ((int)$source['course_id'] !== (int)$target['course_id']) {
        $warning = 'Условия открытия не перенесены: уроки находятся в разных курсах.';
      } else {
        $stats['prereqs'] = lt_clone_prereqs($pdo, $sourceId, $targetId, true);
      }
    }
    $pdo->commit();
    ok(['lesson_id' => $targetId, 'fields' => $scalars, 'stats' => $stats, 'warning' => $warning]);
  }

  fail('Неизвестное действие', 400);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}
