<?php
// GET /courses_list.php?type=<тип>&subject=<slug>
// Курсы, реально открытые текущему ученику. Фильтры необязательные:
//   type    — тип курса («Вводные»/«Основные»/«Летняя школа»/«Профориентация»); «Все»/пусто = без фильтра;
//   subject — slug предмета (math/rus/…); пусто = все предметы.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$type    = trim($_GET['type'] ?? '');
$subject = trim($_GET['subject'] ?? '');
$today   = date('Y-m-d');

$sql = "SELECT c.id, c.title, c.type, c.color, c.price,
               s.name AS subject, s.icon, s.slug AS subject_slug,
               (SELECT COUNT(*) FROM blocks b WHERE b.course_id = c.id) AS blocks
        FROM courses c
        JOIN subjects s ON s.id = c.subject_id
        JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)";
$where = [];
$args = [$uid, $today];
if ($type !== '' && $type !== 'Все') { $where[] = 'c.type = ?';   $args[] = $type; }
if ($subject !== '')                 { $where[] = 's.slug = ?';   $args[] = $subject; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY c.position, c.id';

$stmt = db()->prepare($sql);
$stmt->execute($args);

$courses = [];
foreach ($stmt as $c) {
  $courses[] = [
    'id'      => (int)$c['id'],
    'subject' => $c['subject'],
    'icon'    => $c['icon'],
    'title'   => $c['title'],
    'type'    => $c['type'],
    'color'   => $c['color'],
    'blocks'  => (int)$c['blocks'],
    'price'   => $c['price'] !== null ? (int)$c['price'] : null,
  ];
}

ok(['courses' => $courses]);
