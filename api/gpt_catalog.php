<?php
require __DIR__ . '/gpt_helpers.php';
gpt_auth(['GET']);
$pdo = db();
$where = []; $values = [];
foreach (['subject_id'=>'s.id','course_id'=>'c.id','period_id'=>'m.id'] as $param=>$column) {
  if (!isset($_GET[$param])) continue;
  $where[] = $column . '=?'; $values[] = gpt_integer($_GET[$param], $param, 1, PHP_INT_MAX);
}
foreach (['date_from'=>'>=', 'date_to'=>'<='] as $param=>$operator) {
  if (!isset($_GET[$param])) continue;
  $value = (string)$_GET[$param];
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  if (!$date || $date->format('Y-m-d') !== $value) fail('Нужна дата YYYY-MM-DD', 400);
  $where[] = 'SUBSTR(l.air_at,1,10)' . $operator . '?'; $values[]=$value;
}
if (isset($_GET['day'])) {
  $where[] = 'SUBSTR(l.air_at,9,2)=?';
  $values[] = sprintf('%02d', gpt_integer($_GET['day'], 'day', 1, 31));
}
if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
  $query = gpt_string($_GET['q'], 'q', 200, true);
  $where[] = '(l.title LIKE ? OR c.title LIKE ? OR s.name LIKE ?)';
  array_push($values, '%'.$query.'%', '%'.$query.'%', '%'.$query.'%');
}
$after = gpt_integer($_GET['after_id'] ?? 0, 'after_id', 0, PHP_INT_MAX);
$limit = gpt_integer($_GET['limit'] ?? 50, 'limit', 1, 100);
$where[] = 'l.id>?'; $values[]=$after;
$sql = "SELECT l.id,l.parent_lesson_id,l.title,l.type,l.air,l.air_at,l.opens_at,l.duration,
  b.id AS section_id,b.title AS section_title,m.id AS period_id,m.name AS period_name,
  c.id AS course_id,c.title AS course_title,s.id AS subject_id,s.name AS subject_name,
  (SELECT COUNT(*) FROM homeworks h WHERE h.lesson_id=l.id) AS homework_count
  FROM lessons l JOIN blocks b ON b.id=l.block_id LEFT JOIN months m ON m.id=b.month_id
  JOIN courses c ON c.id=b.course_id JOIN subjects s ON s.id=c.subject_id
  WHERE " . implode(' AND ', $where) . ' ORDER BY l.id LIMIT ' . ($limit+1);
$st=$pdo->prepare($sql); $st->execute($values); $lessons=$st->fetchAll();
$more=count($lessons)>$limit;
if ($more) array_pop($lessons);
ok(['today'=>date('Y-m-d'),'timezone'=>'Europe/Moscow',
  'subjects'=>$pdo->query('SELECT id,name,slug,position FROM subjects ORDER BY position,id')->fetchAll(),
  'courses'=>$pdo->query('SELECT id,subject_id,title,type,position FROM courses ORDER BY subject_id,position,id')->fetchAll(),
  'periods'=>$pdo->query('SELECT id,course_id,name,kind,starts_on,ends_on,position FROM months ORDER BY course_id,position,id')->fetchAll(),
  'sections'=>$pdo->query('SELECT id,course_id,month_id,n,title,position FROM blocks ORDER BY course_id,position,id')->fetchAll(),
  'lessons'=>$lessons,'next_after_id'=>$more ? (int)end($lessons)['id'] : null]);
