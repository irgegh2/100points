<?php
// GET /profile_get.php — профиль текущего ученика (по сессии).
// Возвращает базовые поля пользователя + профиль (аватар, дата рождения) + цели по предметам.
require __DIR__ . '/bootstrap.php';

$uid = require_user();
release_session(); // read-only — снимаем лок сессии сразу

$ustmt = db()->prepare("SELECT id, name, email, phone, role FROM users WHERE id = ? LIMIT 1");
$ustmt->execute([$uid]);
$user = $ustmt->fetch();
if (!$user) fail('Не авторизован', 401);

$pstmt = db()->prepare("SELECT avatar, birthdate, nickname_color FROM profiles WHERE user_id = ? LIMIT 1");
$pstmt->execute([$uid]);
$p = $pstmt->fetch();

// Цели по предметам.
$gstmt = db()->prepare("SELECT subject, target_score, hours_per_week FROM profile_goals WHERE user_id = ?");
$gstmt->execute([$uid]);
$goals = [];
foreach ($gstmt as $g) {
  $goals[] = [
    'subject'        => $g['subject'],
    'target_score'   => $g['target_score']   !== null ? (int)$g['target_score']   : null,
    'hours_per_week' => $g['hours_per_week'] !== null ? (int)$g['hours_per_week'] : null,
  ];
}

// Предметы, по которым у ученика есть хотя бы один доступный курс (зачисление).
// Только по ним показываем ползунок целевого балла в профиле.
$today = date('Y-m-d');
$sstmt = db()->prepare(
  "SELECT DISTINCT s.name, s.color
   FROM enrollments e
   JOIN courses c ON c.id = e.course_id
   JOIN subjects s ON s.id = c.subject_id
   WHERE e.user_id = ? AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)
     AND COALESCE(s.show_features, 1) = 1
   ORDER BY s.position, s.name"
);
$sstmt->execute([$uid, $today]);
$subjects = [];
foreach ($sstmt as $s) $subjects[] = ['name' => $s['name'], 'color' => $s['color'] ?: '#3f9bff'];

ok([
  'user' => public_user($user),
  'profile' => [
    'avatar'    => $p['avatar']    ?? '',
    'birthdate' => $p['birthdate'] ?? '',
    'nickname_color' => $p['nickname_color'] ?? '',
    'goals'     => $goals,
  ],
  'subjects' => $subjects,
]);
