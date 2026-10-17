<?php
// GET /notif_list.php — уведомления ученика (новые сверху) + число непрочитанных.
// Непрочитанные хранятся вечно; прочитанные удаляются через 7 дней. Заодно — ленивое «скоро вебинар».
require __DIR__ . '/bootstrap.php';
$uid = require_user();

// В режиме просмотра администратором GET-запрос не должен незаметно менять аккаунт.
if (!is_readonly_impersonation()) try {
  // 1) Чистка прочитанных старше 7 дней.
  db()->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1 AND read_at IS NOT NULL AND read_at < ?")
      ->execute([$uid, date('Y-m-d H:i:s', strtotime('-7 days'))]);

  // 2) Ленивое уведомление о вебинарах в ближайшие 90 минут, без дублей.
  $now = date('Y-m-d H:i');
  $soon = date('Y-m-d H:i', strtotime('+90 minutes'));
  $ev = db()->prepare(
    "SELECT e.id, e.title, e.starts_at, s.name AS subj
     FROM events e
     JOIN courses c ON c.id = e.course_id
     JOIN subjects s ON s.id = c.subject_id
     JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
       AND (en.expires_at IS NULL OR en.expires_at = '' OR en.expires_at >= ?)
     WHERE e.starts_at >= ? AND e.starts_at <= ?"
  );
  $ev->execute([$uid, date('Y-m-d'), $now, $soon]);
  foreach ($ev as $r) {
    $type = 'event:' . $r['id'];
    $ex = db()->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND type = ? LIMIT 1");
    $ex->execute([$uid, $type]);
    if (!$ex->fetch()) notify($uid, $type, "Скоро вебинар «{$r['title']}» по предмету {$r['subj']} — в " . substr($r['starts_at'], 11, 5), 'schedule');
  }
} catch (Throwable $e) { /* пусто */ }

$st = db()->prepare("SELECT id, type, text, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100");
$st->execute([$uid]);
$items = [];
$unread = 0;
foreach ($st as $r) {
  if (!(int)$r['is_read']) $unread++;
  $items[] = [
    'id' => (int)$r['id'], 'type' => $r['type'], 'text' => $r['text'],
    'link' => $r['link'], 'read' => (bool)$r['is_read'], 'created_at' => $r['created_at'],
  ];
}
ok(['items' => $items, 'unread' => $unread]);
