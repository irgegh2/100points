<?php
require_once __DIR__ . '/student_weekly_report.php';

function weekly_report_send(string $to, string $subject, string $html, string $senderEmail, string $senderName): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) return false;
  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $encodedName = '=?UTF-8?B?' . base64_encode($senderName) . '?=';
  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'From: ' . $encodedName . ' <' . $senderEmail . '>',
    'Reply-To: ' . $senderEmail,
    'X-Mailer: 100-points-platform',
  ];
  return @mail($to, $encodedSubject, $html, implode("\r\n", $headers), '-f' . $senderEmail);
}

function run_due_weekly_reports(PDO $pdo): array {
  $weekday = (int)date('N');
  $week = date('o-\WW');
  $settings = $pdo->prepare(
    "SELECT r.user_id, r.recipients, r.sender_email, r.sender_name, r.last_sent_week, u.name
     FROM student_report_settings r JOIN users u ON u.id = r.user_id
     WHERE r.enabled = 1 AND r.weekday = ?"
  );
  $settings->execute([$weekday]);
  $sent = 0; $failed = 0; $skipped = 0;
  foreach ($settings as $row) {
    if ((string)$row['last_sent_week'] === $week) { $skipped++; continue; }
    $uid = (int)$row['user_id'];
    $recipients = json_decode((string)$row['recipients'], true);
    if (!is_array($recipients)) $recipients = [];
    $recipients = array_values(array_filter($recipients, fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
    $senderEmail = filter_var($row['sender_email'], FILTER_VALIDATE_EMAIL) ? $row['sender_email'] : 'info@100-points.ru';
    $senderName = trim((string)$row['sender_name']) ?: '100-балльный репетитор';
    $errors = [];
    try {
      $data = student_weekly_report_data($pdo, $uid);
      $html = student_weekly_report_html($data);
      $subject = 'Учебный отчёт: ' . ($row['name'] ?: 'ученик') . ' · ' . $data['period']['label'] . ' МСК';
      foreach ($recipients as $email) {
        if (!weekly_report_send($email, $subject, $html, $senderEmail, $senderName)) $errors[] = $email;
      }
      $status = !$recipients || $errors ? 'failed' : 'sent';
      if ($status === 'sent') $sent++; else $failed++;
      $pdo->prepare(
        "INSERT INTO student_report_deliveries (user_id, recipients, period_start, period_end, status, error)
         VALUES (?,?,?,?,?,?)"
      )->execute([$uid, json_encode($recipients, JSON_UNESCAPED_UNICODE), $data['period']['since'], $data['period']['until'], $status,
                  $errors ? 'Не отправлено: ' . implode(', ', $errors) : (!$recipients ? 'Нет получателей' : null)]);
    } catch (Throwable $e) {
      $failed++;
      error_log('[weekly report] user ' . $uid . ': ' . $e->getMessage());
      $failedUntil = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
      $pdo->prepare(
        "INSERT INTO student_report_deliveries (user_id, recipients, period_start, period_end, status, error)
         VALUES (?,?,?,?,?,?)"
      )->execute([$uid, json_encode($recipients, JSON_UNESCAPED_UNICODE), $failedUntil->modify('-7 days')->format('Y-m-d H:i:s'),
                  $failedUntil->format('Y-m-d H:i:s'), 'failed', mb_substr($e->getMessage(), 0, 500)]);
    }
    // Один запуск в неделю: при ошибке результат виден в истории, следующая попытка — на следующей неделе.
    $pdo->prepare("UPDATE student_report_settings SET last_sent_week = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
        ->execute([$week, $uid]);
  }
  return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}
