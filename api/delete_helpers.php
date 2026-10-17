<?php
// Безопасное каскадное удаление для админки.
// Учитывает реальные FK базы и логические связи старых таблиц, где FK не был добавлен.

function delete_ident(string $name): string {
  if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new RuntimeException('Недопустимое имя таблицы');
  return $name;
}

function delete_table_exists(PDO $pdo, string $driver, string $table): bool {
  static $cache = [];
  $key = $driver . ':' . $table;
  if (isset($cache[$key])) return $cache[$key];
  if ($driver === 'sqlite') {
    $s = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $s->execute([$table]);
  } else {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $s->execute([$table]);
  }
  return $cache[$key] = (bool)$s->fetchColumn();
}

function delete_primary_key(PDO $pdo, string $driver, string $table): ?string {
  static $cache = [];
  $key = $driver . ':' . $table;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $cols = [];
  if ($driver === 'sqlite') {
    foreach ($pdo->query('PRAGMA table_info(' . delete_ident($table) . ')') as $r) if ((int)$r['pk'] > 0) $cols[(int)$r['pk']] = $r['name'];
    ksort($cols);
  } else {
    $s = $pdo->prepare("SELECT column_name FROM information_schema.key_column_usage
                        WHERE table_schema=DATABASE() AND table_name=? AND constraint_name='PRIMARY'
                        ORDER BY ordinal_position");
    $s->execute([$table]);
    $cols = $s->fetchAll(PDO::FETCH_COLUMN);
  }
  return $cache[$key] = count($cols) === 1 ? (string)array_values($cols)[0] : null;
}

function delete_fk_children(PDO $pdo, string $driver, string $parent): array {
  static $cache = [];
  $key = $driver . ':' . $parent;
  if (isset($cache[$key])) return $cache[$key];
  $out = [];
  if ($driver === 'mysql') {
    // Алиасы обязательны: в MySQL 8 колонки information_schema возвращаются в ВЕРХНЕМ
    // регистре, и $r['table_name'] был null — обнаружение FK молча не находило детей.
    $s = $pdo->prepare("SELECT table_name AS child_table, column_name AS child_column
                        FROM information_schema.key_column_usage
                        WHERE referenced_table_schema=DATABASE() AND referenced_table_name=?");
    $s->execute([$parent]);
    foreach ($s as $r) {
      if ((string)$r['child_table'] !== '') $out[] = [(string)$r['child_table'], (string)$r['child_column']];
    }
  } else {
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
      foreach ($pdo->query('PRAGMA foreign_key_list(' . delete_ident((string)$table) . ')') as $r) {
        if ((string)$r['table'] === $parent) $out[] = [(string)$table, (string)$r['from']];
      }
    }
  }
  return $cache[$key] = $out;
}

function delete_logical_children(): array {
  return [
    'users' => [['profiles','user_id'],['profile_goals','user_id'],['subscriptions','user_id'],['enrollments','user_id'],['submissions','user_id'],['answer_drafts','user_id'],['bank_progress','user_id'],['trainer_attempts','user_id'],['trainer_qa_generated','user_id'],['trainer_qa_history','user_id'],['ai_chats','user_id'],['wallets','user_id'],['coin_log','user_id'],['user_achievements','user_id'],['deadline_penalties','user_id'],['purchases','user_id'],['notifications','user_id'],['chats','user_id'],['lesson_progress','user_id'],['deadline_extensions','user_id'],['streak_freezes','user_id'],['mock_assignments','user_id'],['answer_uploads','user_id'],['leads','user_id'],['drive_items','user_id'],['drive_quotas','user_id'],['user_vk_contacts','user_id']],
    'subjects' => [['courses','subject_id'],['topics','subject_id'],['bank_sources','subject_id'],['bank_variants','subject_id'],['trainer_tasks','subject_id'],['trainer_qa_topics','subject_id'],['chats','subject_id'],['library_items','subject_id']],
    'courses' => [['months','course_id'],['blocks','course_id'],['mock_exams','course_id'],['enrollments','course_id'],['events','course_id']],
    'months' => [['blocks','month_id']],
    'blocks' => [['lessons','block_id']],
    'lessons' => [['lessons','parent_lesson_id'],['materials','lesson_id'],['timecodes','lesson_id'],['homeworks','lesson_id'],['lesson_progress','lesson_id'],['lesson_prereqs','lesson_id'],['lesson_prereqs','required_lesson_id']],
    'homeworks' => [['questions','homework_id'],['submissions','homework_id'],['deadline_penalties','homework_id'],['deadline_extensions','homework_id']],
    'mock_exams' => [['questions','mock_id'],['submissions','mock_id'],['mock_assignments','mock_id']],
    'questions' => [['answers','question_id'],['variant_questions','question_id'],['bank_progress','question_id'],['answer_uploads','question_id'],['question_attachments','question_id']],
    'submissions' => [['answers','submission_id'],['answer_uploads','submission_id']],
    'mock_assignments' => [['proctored_mock_presence','assignment_id'],['proctored_mock_messages','assignment_id'],['proctored_mock_events','assignment_id'],['proctored_mock_signals','assignment_id']],
    'topics' => [['subtopics','topic_id']],
    'subtopics' => [['questions','subtopic_id']],
    'bank_sources' => [['bank_variants','source_id']],
    'bank_variants' => [['variant_questions','variant_id']],
    'trainer_tasks' => [['trainer_attempts','task_id']],
    'trainer_qa_topics' => [['trainer_qa_history','topic_id']],
    'ai_chats' => [['ai_messages','chat_id']],
    'help_categories' => [['help_qa','category_id']],
    'achievements' => [['user_achievements','achievement_id']],
    'plans' => [['subscriptions','plan_id']],
    'chats' => [['chat_messages','chat_id']],
  ];
}

function delete_row_cascade(PDO $pdo, string $driver, string $table, string $keyColumn, $keyValue, array &$visited): int {
  $table = delete_ident($table); $keyColumn = delete_ident($keyColumn);
  if (!delete_table_exists($pdo, $driver, $table)) return 0;
  $visitKey = $table . ':' . $keyColumn . ':' . (string)$keyValue;
  if (isset($visited[$visitKey])) return 0;
  $visited[$visitKey] = true;

  // Ролик принадлежит видеотеке, а не уроку: удаление/перенос урока лишь
  // отвязывает видео. Исходник и уже подготовленные качества остаются в S3.
  if ($table === 'lessons' && $keyColumn === 'id' && delete_table_exists($pdo, $driver, 'videos')) {
    $detach = $pdo->prepare('UPDATE videos SET lesson_id = NULL, updated_at = ? WHERE lesson_id = ?');
    $detach->execute([date('c'), $keyValue]);
  }

  $relations = delete_fk_children($pdo, $driver, $table);
  foreach (delete_logical_children()[$table] ?? [] as $rel) $relations[] = $rel;
  $unique = [];
  foreach ($relations as [$child, $fk]) $unique[$child . ':' . $fk] = [$child, $fk];

  $count = 0;
  foreach ($unique as [$child, $fk]) {
    if (!delete_table_exists($pdo, $driver, $child)) continue;
    $child = delete_ident($child); $fk = delete_ident($fk);
    $pk = delete_primary_key($pdo, $driver, $child);
    if ($pk !== null) {
      $s = $pdo->prepare("SELECT " . delete_ident($pk) . " FROM $child WHERE $fk = ?");
      $s->execute([$keyValue]);
      foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $childId) $count += delete_row_cascade($pdo, $driver, $child, $pk, $childId, $visited);
    } else {
      $s = $pdo->prepare("DELETE FROM $child WHERE $fk = ?");
      $s->execute([$keyValue]); $count += $s->rowCount();
    }
  }
  $del = $pdo->prepare("DELETE FROM $table WHERE $keyColumn = ?");
  $del->execute([$keyValue]);
  return $count + $del->rowCount();
}

function delete_root_row(PDO $pdo, string $table, int $id): int {
  $visited = [];
  return delete_row_cascade($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), $table, 'id', $id, $visited);
}
