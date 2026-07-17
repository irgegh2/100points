<?php
// POST /admin_enroll.php  { user_id, course_id, action: 'grant'|'revoke', expires_at? }
// Админ выдаёт/снимает доступ ученика к конкретному курсу (зачисление).
require __DIR__ . '/bootstrap.php';
require_admin();

$data = body();
$uid      = (int)($data['user_id'] ?? 0);
$courseId = (int)($data['course_id'] ?? 0);
$action   = trim($data['action'] ?? 'grant');
$expires  = trim($data['expires_at'] ?? '');

if ($uid <= 0 || $courseId <= 0) fail('Не указан ученик или курс', 400);

if ($action === 'revoke') {
  db()->prepare("DELETE FROM enrollments WHERE user_id = ? AND course_id = ?")->execute([$uid, $courseId]);
  ok(['revoked' => $courseId]);
}

// grant: upsert (уникальность user_id+course_id). Сначала пробуем обновить, иначе вставляем.
$upd = db()->prepare("UPDATE enrollments SET source = 'manual', expires_at = ? WHERE user_id = ? AND course_id = ?");
$upd->execute([$expires ?: null, $uid, $courseId]);
if ($upd->rowCount() === 0) {
  // строки не было — вставляем (ловим гонку уникального ключа мягко)
  try {
    db()->prepare("INSERT INTO enrollments (user_id, course_id, source, expires_at) VALUES (?,?, 'manual', ?)")
        ->execute([$uid, $courseId, $expires ?: null]);
  } catch (Exception $e) {
    db()->prepare("UPDATE enrollments SET expires_at = ? WHERE user_id = ? AND course_id = ?")->execute([$expires ?: null, $uid, $courseId]);
  }
}

ok(['granted' => $courseId, 'expires_at' => $expires ?: null]);
