<?php
// POST /admin_student_report_save.php { user_id, enabled, recipients[], weekday, sender_email, sender_name }
require __DIR__ . '/bootstrap.php';
require_admin();

$in = body();
$uid = (int)($in['user_id'] ?? 0);
if ($uid <= 0) fail('Не указан ученик', 400);
$exists = db()->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
$exists->execute([$uid]);
if (!$exists->fetchColumn()) fail('Ученик не найден', 404);

$recipients = [];
foreach ((array)($in['recipients'] ?? []) as $email) {
  $email = mb_strtolower(trim((string)$email));
  if ($email === '') continue;
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail("Некорректная почта: $email", 400);
  if (!in_array($email, $recipients, true)) $recipients[] = $email;
}
if (count($recipients) > 5) fail('Можно указать не больше 5 адресов', 400);
$enabled = !empty($in['enabled']);
if ($enabled && !$recipients) fail('Укажи хотя бы одну почту для отчёта', 400);
$weekday = max(1, min(7, (int)($in['weekday'] ?? 1)));
$senderEmail = mb_strtolower(trim((string)($in['sender_email'] ?? 'info@100-points.ru')));
if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) fail('Некорректная почта отправителя', 400);
$senderName = trim((string)($in['sender_name'] ?? '100-балльный репетитор'));
if ($senderName === '') $senderName = '100-балльный репетитор';
$senderName = mb_substr($senderName, 0, 120);
$json = json_encode($recipients, JSON_UNESCAPED_UNICODE);
$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'mysql') {
  $pdo->prepare(
    "INSERT INTO student_report_settings (user_id, enabled, recipients, weekday, sender_email, sender_name)
     VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), recipients=VALUES(recipients),
       weekday=VALUES(weekday), sender_email=VALUES(sender_email), sender_name=VALUES(sender_name), updated_at=CURRENT_TIMESTAMP"
  )->execute([$uid, $enabled ? 1 : 0, $json, $weekday, $senderEmail, $senderName]);
} else {
  $pdo->prepare(
    "INSERT INTO student_report_settings (user_id, enabled, recipients, weekday, sender_email, sender_name)
     VALUES (?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled, recipients=excluded.recipients,
       weekday=excluded.weekday, sender_email=excluded.sender_email, sender_name=excluded.sender_name, updated_at=datetime('now')"
  )->execute([$uid, $enabled ? 1 : 0, $json, $weekday, $senderEmail, $senderName]);
}
require __DIR__ . '/student_analytics.php';
ok(['setting' => sa_report_setting($pdo, $uid)]);
