<?php
// GET /review_queue.php?subject=&user_id= — очередь работ на ручной проверке (Этап 5).
// Только сдачи со статусом 'review' (есть развёрнутые ответы). Для админа.
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();

$subject = trim($_GET['subject'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);

// Сдачи на ручной проверке — и ДЗ, и пробники. Тип/название/предмет берём через LEFT JOIN.
$sql =
  "SELECT sub.id, sub.score, sub.max_score, sub.created_at, sub.homework_id, sub.mock_id,
          u.id AS user_id, u.name AS user_name, u.email AS user_email,
          hw.title AS hw_title, hw.level AS hw_level, hs.name AS hw_subject,
          mk.title AS mk_title, ms.name AS mk_subject,
          (SELECT COUNT(*) FROM answers a JOIN questions q ON q.id = a.question_id
           WHERE a.submission_id = sub.id AND q.type = 'textarea') AS open_count
   FROM submissions sub
   JOIN users u ON u.id = sub.user_id
   LEFT JOIN homeworks hw ON hw.id = sub.homework_id
   LEFT JOIN lessons l ON l.id = hw.lesson_id
   LEFT JOIN blocks b ON b.id = l.block_id
   LEFT JOIN courses c ON c.id = b.course_id
   LEFT JOIN subjects hs ON hs.id = c.subject_id
   LEFT JOIN mock_exams mk ON mk.id = sub.mock_id
   LEFT JOIN courses mc ON mc.id = mk.course_id
   LEFT JOIN subjects ms ON ms.id = mc.subject_id
   WHERE sub.status = 'review'";
$args = [];
if ($subject !== '') { $sql .= " AND (hs.name = ? OR ms.name = ?)"; $args[] = $subject; $args[] = $subject; }
if ($userId > 0)     { $sql .= " AND u.id = ?";   $args[] = $userId; }
$sql .= " ORDER BY sub.created_at, sub.id";

$stmt = db()->prepare($sql);
$stmt->execute($args);
$items = [];
foreach ($stmt as $r) {
  $isMock = $r['mock_id'] !== null;
  $items[] = [
    'id'             => (int)$r['id'],
    'user_id'        => (int)$r['user_id'],
    'user_name'      => $r['user_name'],
    'user_email'     => $r['user_email'],
    'kind'           => $isMock ? 'mock' : 'homework',
    'homework_title' => $isMock ? $r['mk_title'] : $r['hw_title'],
    'level'          => $isMock ? 'normal' : $r['hw_level'],
    'subject'        => $isMock ? $r['mk_subject'] : $r['hw_subject'],
    'score'          => (int)$r['score'],
    'max_score'      => (int)$r['max_score'],
    'open_count'     => (int)$r['open_count'],
    'created_at'     => $r['created_at'],
  ];
}

ok(['items' => $items]);
