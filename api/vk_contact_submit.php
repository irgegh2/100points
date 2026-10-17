<?php
// POST /vk_contact_submit.php { url } — отправить/переотправить профиль VK на проверку.
require __DIR__ . '/bootstrap.php';
$uid = require_user();
$data = body();
$raw = trim((string)($data['url'] ?? ''));

if ($raw === '') fail('Вставь ссылку на свой профиль VK', 400);
if (!preg_match('~^https?://~i', $raw)) $raw = 'https://' . $raw;
if (!filter_var($raw, FILTER_VALIDATE_URL)) fail('Похоже, ссылка указана неверно', 400);

$parts = parse_url($raw);
$host = strtolower((string)($parts['host'] ?? ''));
$allowedHosts = ['vk.com', 'www.vk.com', 'm.vk.com', 'vk.ru', 'www.vk.ru', 'm.vk.ru'];
if (!in_array($host, $allowedHosts, true)) fail('Нужна ссылка именно на профиль VK', 400);

$path = '/' . trim((string)($parts['path'] ?? ''), '/');
if ($path === '/' || in_array(strtolower($path), ['/feed', '/im', '/login', '/join'], true)) {
  fail('Нужна прямая ссылка на твой профиль VK', 400);
}
$url = 'https://' . (str_ends_with($host, 'vk.ru') ? 'vk.ru' : 'vk.com') . $path;
if (strlen($url) > 255) fail('Ссылка слишком длинная', 400);

$pdo = db();
$exists = $pdo->prepare('SELECT user_id FROM user_vk_contacts WHERE user_id = ? LIMIT 1');
$exists->execute([$uid]);
$now = gmdate('Y-m-d H:i:s');
if ($exists->fetchColumn() !== false) {
  $pdo->prepare(
    "UPDATE user_vk_contacts
     SET vk_url = ?, status = 'pending', rejection_reason = NULL,
         submitted_at = ?, reviewed_at = NULL, reviewed_by = NULL
     WHERE user_id = ?"
  )->execute([$url, $now, $uid]);
} else {
  $pdo->prepare(
    "INSERT INTO user_vk_contacts
     (user_id, vk_url, status, rejection_reason, submitted_at, reviewed_at, reviewed_by)
     VALUES (?,?,'pending',NULL,?,NULL,NULL)"
  )->execute([$uid, $url, $now]);
}

// Синхронизирует статус во всех открытых вкладках этого и других кабинетов.
live_content_touch();
ok(['status' => 'pending', 'url' => $url, 'rejection_reason' => '', 'submitted_at' => $now]);
