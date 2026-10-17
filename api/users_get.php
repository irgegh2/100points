<?php
// GET /users_get.php?id=<id> — полный профиль ученика для админки.
// Возвращает аккаунт + профиль (аватар, дата рождения) + цели по предметам.
require __DIR__ . '/bootstrap.php';
require_admin();
release_session(); // read-only — снимаем лок сессии сразу

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Не указан ученик', 400);

$ustmt = db()->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ? LIMIT 1");
$ustmt->execute([$id]);
$user = $ustmt->fetch();
if (!$user) fail('Ученик не найден', 404);

$pstmt = db()->prepare("SELECT avatar, birthdate FROM profiles WHERE user_id = ? LIMIT 1");
$pstmt->execute([$id]);
$p = $pstmt->fetch();

$gstmt = db()->prepare("SELECT subject, target_score, hours_per_week FROM profile_goals WHERE user_id = ?");
$gstmt->execute([$id]);
$goals = [];
foreach ($gstmt as $g) {
  $goals[] = [
    'subject'        => $g['subject'],
    'target_score'   => $g['target_score']   !== null ? (int)$g['target_score']   : null,
    'hours_per_week' => $g['hours_per_week'] !== null ? (int)$g['hours_per_week'] : null,
  ];
}

$vkstmt = db()->prepare(
  "SELECT vk_url, status, rejection_reason, submitted_at, reviewed_at
   FROM user_vk_contacts WHERE user_id = ? LIMIT 1"
);
$vkstmt->execute([$id]);
$vk = $vkstmt->fetch();

ok([
  'user' => [
    'id'         => (int)$user['id'],
    'name'       => $user['name'],
    'email'      => $user['email'],
    'phone'      => $user['phone'],
    'role'       => $user['role'],
    'created_at' => $user['created_at'],
  ],
  'profile' => [
    'avatar'    => $p['avatar']    ?? '',
    'birthdate' => $p['birthdate'] ?? '',
    'goals'     => $goals,
  ],
  'vk_contact' => [
    'url' => $vk['vk_url'] ?? '',
    'status' => $vk['status'] ?? 'missing',
    'rejection_reason' => $vk['rejection_reason'] ?? '',
    'submitted_at' => $vk['submitted_at'] ?? null,
    'reviewed_at' => $vk['reviewed_at'] ?? null,
  ],
]);
