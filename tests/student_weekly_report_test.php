<?php
require __DIR__ . '/../api/student_weekly_report.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
  'users (id INTEGER PRIMARY KEY, name TEXT)',
  'subjects (id INTEGER PRIMARY KEY, name TEXT)',
  'courses (id INTEGER PRIMARY KEY, subject_id INTEGER, title TEXT)',
  'blocks (id INTEGER PRIMARY KEY, course_id INTEGER)',
  'lessons (id INTEGER PRIMARY KEY, block_id INTEGER, title TEXT)',
  'homeworks (id INTEGER PRIMARY KEY, lesson_id INTEGER, title TEXT, deadline TEXT)',
  'mock_exams (id INTEGER PRIMARY KEY, course_id INTEGER, title TEXT)',
  'submissions (id INTEGER PRIMARY KEY, user_id INTEGER, homework_id INTEGER, mock_id INTEGER, status TEXT, score INTEGER, max_score INTEGER, created_at TEXT)',
  'lesson_progress (id INTEGER PRIMARY KEY, user_id INTEGER, lesson_id INTEGER, done_at TEXT)',
  'trainer_attempts (id INTEGER PRIMARY KEY, user_id INTEGER, correct INTEGER, created_at TEXT)',
  'coin_log (id INTEGER PRIMARY KEY, user_id INTEGER, ref_type TEXT, created_at TEXT)',
  'purchases (id INTEGER PRIMARY KEY, user_id INTEGER, item_name TEXT, status TEXT)',
  'deadline_extensions (id INTEGER PRIMARY KEY, user_id INTEGER, homework_id INTEGER, extra_days INTEGER)',
  'enrollments (id INTEGER PRIMARY KEY, user_id INTEGER, course_id INTEGER, created_at TEXT, expires_at TEXT)',
] as $table) $pdo->exec('CREATE TABLE ' . $table);

$pdo->exec("INSERT INTO users VALUES (1,'Тестовый <ученик>')");
$pdo->exec("INSERT INTO subjects VALUES (1,'Английский')");
$pdo->exec("INSERT INTO courses VALUES (1,1,'Курс')");
$pdo->exec("INSERT INTO blocks VALUES (1,1)");
$pdo->exec("INSERT INTO lessons VALUES (1,1,'Урок')");
$pdo->exec("INSERT INTO enrollments VALUES (1,1,1,'2026-09-01 00:00:00',NULL)");
$pdo->exec("INSERT INTO homeworks VALUES
  (1,1,'Сданное ДЗ','2026-09-09 12:00:00'),
  (2,1,'Новая просрочка','2026-09-10 12:00:00'),
  (3,1,'Старая просрочка','2026-09-07 12:00:00'),
  (4,1,'ДЗ с продлением','2026-09-08'),
  (5,1,'ДЗ завтра','2026-09-16'),
  (6,1,'Сдано поздно','2026-09-12 12:00:00'),
  (7,1,'Вышло из периода после продления','2026-09-14 12:00:00')");
$pdo->exec("INSERT INTO deadline_extensions VALUES (1,1,7,1)");
$pdo->exec("INSERT INTO mock_exams VALUES (1,1,'Пробник')");
$pdo->exec("INSERT INTO submissions VALUES
  (1,1,1,NULL,'graded',8,10,'2026-09-08 07:00:00'),
  (2,1,NULL,1,'review',0,10,'2026-09-09 08:00:00'),
  (3,1,6,NULL,'graded',5,10,'2026-09-12 10:00:00'),
  (4,1,1,NULL,'graded',9,10,'2026-09-15 07:00:00')");
$pdo->exec("INSERT INTO lesson_progress VALUES (1,1,1,'2026-09-10 08:00:00')");
$pdo->exec("INSERT INTO trainer_attempts VALUES (1,1,1,'2026-09-10 08:00:00'),(2,1,0,'2026-09-11 08:00:00')");
$pdo->exec("INSERT INTO coin_log VALUES (1,1,'streak','2026-09-14 08:00:00')");

function same($actual, $expected, string $label): void {
  if ($actual !== $expected) throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// Полуоткрытый интервал: 08.09 07:00 UTC включён, 15.09 07:00 исключён.
$until = new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Moscow'));
$report = student_weekly_report_data($pdo, 1, $until);
same($report['period']['since'], '2026-09-08 10:00:00', 'period start');
same($report['summary']['homeworks'], 2, 'weekly homeworks');
same($report['summary']['mocks'], 1, 'weekly mocks');
same($report['summary']['missed'], 2, 'only fresh missed deadlines');
same($report['summary']['late'], 1, 'only fresh late deadlines');
same($report['summary']['average_score'], 65, 'graded works average');
same($report['summary']['pending_review'], 1, 'pending review');
same($report['summary']['lessons'], 1, 'weekly lessons');
same($report['trainer']['attempted'], 2, 'weekly trainer attempts');
same($report['summary']['active_days'], 6, 'unique active dates');
same(array_column($report['missed'], 'title'), ['ДЗ с продлением', 'Новая просрочка'], 'deadline order');
same(array_column($report['late'], 'title'), ['Сдано поздно'], 'late work');

$html = student_weekly_report_html($report);
if (str_contains($html, 'Старая просрочка') || str_contains($html, 'ДЗ завтра') || str_contains($html, 'Вышло из периода')) {
  throw new RuntimeException('Old, future or extended deadlines leaked into the report');
}
if (!str_contains($html, 'Тестовый &lt;ученик&gt;')) throw new RuntimeException('Student name is not HTML escaped');
echo "student weekly report: OK\n";
