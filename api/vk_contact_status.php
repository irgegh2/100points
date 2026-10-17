<?php
// GET /vk_contact_status.php — состояние обязательного контакта VK текущего ученика.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$stmt = db()->prepare(
  "SELECT vk_url, status, rejection_reason, submitted_at, reviewed_at
   FROM user_vk_contacts WHERE user_id = ? LIMIT 1"
);
$stmt->execute([$uid]);
$row = $stmt->fetch();

ok([
  'status' => $row ? (string)$row['status'] : 'missing',
  'url' => $row ? (string)$row['vk_url'] : '',
  'rejection_reason' => $row ? (string)($row['rejection_reason'] ?? '') : '',
  'submitted_at' => $row['submitted_at'] ?? null,
  'reviewed_at' => $row['reviewed_at'] ?? null,
]);
