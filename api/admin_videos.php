<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
require_admin();
release_session();

// До перехода на показ оригиналов завершённые загрузки отправлялись в FFmpeg.
// Если обработка обложки/качеств упала, сам проверенный исходник всё равно цел.
// Возвращаем такие ролики в работу без повторной многогигабайтной загрузки.
$recover = db()->prepare("UPDATE videos
                          SET status='ready',process_percent=100,
                              process_stage=CASE WHEN poster_key IS NULL OR poster_key='' THEN 'Обложка в очереди' ELSE 'Готово · оригинал' END,
                              error=NULL,updated_at=?
                          WHERE status IN ('queued','failed') AND source_key IS NOT NULL AND source_key<>''");
$recover->execute([gmdate('Y-m-d H:i:s')]);
if ($recover->rowCount() > 0) video_worker_kick();

$where = ['1=1']; $args = [];
$courseId = max(0, (int)($_GET['course_id'] ?? 0));
$monthId = max(0, (int)($_GET['month_id'] ?? 0));
$lessonId = max(0, (int)($_GET['lesson_id'] ?? 0));
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
if ($courseId) { $where[] = 'c.id = ?'; $args[] = $courseId; }
if ($monthId) { $where[] = 'm.id = ?'; $args[] = $monthId; }
if ($lessonId) { $where[] = 'v.lesson_id = ?'; $args[] = $lessonId; }
if ($status !== '') { $where[] = 'v.status = ?'; $args[] = $status; }
if ($q !== '') { $where[] = '(v.title LIKE ? OR v.original_name LIKE ? OR l.title LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }

$sql = "SELECT v.*, l.title AS lesson_title, b.id AS block_id, b.title AS block_title,
               m.id AS month_id, m.name AS month_name, m.kind AS period_kind, c.id AS course_id, c.title AS course_title
        FROM videos v
        LEFT JOIN lessons l ON l.id = v.lesson_id
        LEFT JOIN blocks b ON b.id = l.block_id
        LEFT JOIN months m ON m.id = b.month_id
        LEFT JOIN courses c ON c.id = b.course_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY v.id DESC LIMIT 300';
$st = db()->prepare($sql); $st->execute($args);
$items = array_map('video_public_admin', $st->fetchAll());

$courses = db()->query('SELECT id,title FROM courses ORDER BY title,id')->fetchAll();
$months = db()->query('SELECT id,course_id,name,kind FROM months ORDER BY course_id,position,id')->fetchAll();
$lessons = db()->query(
  "SELECT l.id,l.title,l.video_source,l.video_id,l.kinescope_id,b.id AS block_id,b.n AS block_n,
          m.id AS month_id,m.name AS month_name,m.kind AS period_kind,c.id AS course_id,c.title AS course_title
   FROM lessons l JOIN blocks b ON b.id=l.block_id JOIN months m ON m.id=b.month_id JOIN courses c ON c.id=b.course_id
   ORDER BY c.title,m.position,b.position,l.position,l.id"
)->fetchAll();
ok(['items' => $items, 'courses' => $courses, 'months' => $months, 'lessons' => $lessons]);
