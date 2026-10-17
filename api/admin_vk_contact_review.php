<?php
// POST /admin_vk_contact_review.php { user_id, status: approved|rejected, reason? }
require __DIR__ . '/bootstrap.php';
$adminId = require_admin();
$data = body();
$userId = (int)($data['user_id'] ?? 0);
$status = trim((string)($data['status'] ?? ''));
$reason = trim((string)($data['reason'] ?? ''));

if ($userId <= 0) fail('Не указан ученик', 400);
if (!in_array($status, ['approved', 'rejected'], true)) fail('Неизвестный статус проверки', 400);
if ($status === 'rejected' && $reason === '') $reason = 'Проверь ссылку и отправь профиль ещё раз.';
if (mb_strlen($reason) > 255) fail('Причина слишком длинная', 400);

$stmt = db()->prepare('SELECT vk_url, status FROM user_vk_contacts WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$contact = $stmt->fetch();
if (!$contact) fail('Ученик ещё не отправил ссылку VK', 404);

$now = gmdate('Y-m-d H:i:s');
db()->prepare(
  'UPDATE user_vk_contacts SET status = ?, rejection_reason = ?, reviewed_at = ?, reviewed_by = ? WHERE user_id = ?'
)->execute([$status, $status === 'rejected' ? $reason : null, $now, $adminId, $userId]);

if ((string)$contact['status'] !== $status) {
  if ($status === 'approved') {
    notify($userId, 'mentor', 'Ссылка VK подтверждена. Персональный менеджер сможет связаться с тобой в сентябре.', 'home');
  } else {
    notify($userId, 'mentor', 'Ссылка VK отклонена. Открой кабинет и отправь правильную ссылку ещё раз.', 'home');
  }
}

ok([
  'status' => $status,
  'url' => (string)$contact['vk_url'],
  'rejection_reason' => $status === 'rejected' ? $reason : '',
  'reviewed_at' => $now,
]);
