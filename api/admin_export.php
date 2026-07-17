<?php
// GET /admin_export.php?type=leads|users|purchases|results — выгрузка в CSV (открывается в Excel).
// CSV с UTF-8 BOM и разделителем ';' (родной для русской локали Excel).
require __DIR__ . '/bootstrap.php';
require_admin();

$type = (string)($_GET['type'] ?? '');

function emit_csv(string $filename, array $headers, iterable $rows): void {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  echo "\xEF\xBB\xBF"; // BOM — чтобы Excel распознал UTF-8
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers, ';');
  foreach ($rows as $r) fputcsv($out, $r, ';');
  fclose($out);
  exit;
}

$KIND_RU = ['lead' => 'Заявка с сайта', 'subscription' => 'Запрос тарифа', 'support' => 'Вопрос (ИИ-помощь)'];
$today = date('Y-m-d');

if ($type === 'leads') {
  $status = (string)($_GET['status'] ?? '');
  $kind = (string)($_GET['kind'] ?? '');
  $sql = "SELECT l.*, u.name AS user_name, u.email AS user_email FROM leads l LEFT JOIN users u ON u.id = l.user_id";
  $w = []; $a = [];
  if (in_array($status, ['new', 'done', 'rejected'], true)) { $w[] = 'l.status = ?'; $a[] = $status; }
  if (in_array($kind, ['lead', 'subscription', 'support'], true)) { $w[] = 'l.kind = ?'; $a[] = $kind; }
  if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
  $sql .= ' ORDER BY l.id DESC';
  $st = db()->prepare($sql); $st->execute($a);
  $rows = [];
  foreach ($st as $r) $rows[] = [
    $r['id'], $KIND_RU[$r['kind']] ?? $r['kind'], $r['name'] ?: ($r['user_name'] ?? ''),
    $r['phone'], $r['grade'], $r['plan'], $r['message'], $r['source'],
    ['new' => 'Новая', 'done' => 'Обработана', 'rejected' => 'Отклонена'][$r['status']] ?? $r['status'],
    $r['note'], $r['user_email'] ?? '', $r['created_at'],
  ];
  emit_csv("leads-$today.csv", ['ID', 'Тип', 'Имя', 'Телефон', 'Класс', 'Тариф', 'Сообщение', 'Источник', 'Статус', 'Комментарий', 'Email', 'Создано'], $rows);
}

if ($type === 'users') {
  $st = db()->query("SELECT id, name, email, phone, role, created_at FROM users ORDER BY id");
  $rows = [];
  foreach ($st as $r) $rows[] = [$r['id'], $r['name'], $r['email'], $r['phone'], $r['role'], $r['created_at']];
  emit_csv("students-$today.csv", ['ID', 'Имя', 'Email', 'Телефон', 'Роль', 'Регистрация'], $rows);
}

if ($type === 'purchases') {
  $st = db()->query("SELECT p.id, u.name AS user_name, p.item_name, p.price, p.status, p.note, p.created_at
                     FROM purchases p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC");
  $rows = [];
  foreach ($st as $r) $rows[] = [$r['id'], $r['user_name'], $r['item_name'], $r['price'], $r['status'], $r['note'], $r['created_at']];
  emit_csv("purchases-$today.csv", ['ID', 'Ученик', 'Товар', 'Цена', 'Статус', 'Комментарий', 'Создано'], $rows);
}

if ($type === 'results') {
  $st = db()->query(
    "SELECT s.id, u.name AS user_name, u.email,
            CASE WHEN s.homework_id IS NOT NULL THEN 'Домашка' ELSE 'Пробник' END AS kind,
            COALESCE(h.title, m.title) AS title,
            s.score, s.max_score, s.status, s.created_at, s.graded_at
     FROM submissions s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN homeworks h ON h.id = s.homework_id
     LEFT JOIN mock_exams m ON m.id = s.mock_id
     ORDER BY s.id DESC"
  );
  $STATUS_RU = ['graded' => 'Проверено', 'review' => 'На проверке'];
  $rows = [];
  foreach ($st as $r) {
    $pct = (int)$r['max_score'] > 0 ? round(100 * (int)$r['score'] / (int)$r['max_score']) . '%' : '';
    $rows[] = [$r['id'], $r['user_name'], $r['email'], $r['kind'], $r['title'],
      $r['score'], $r['max_score'], $pct, $STATUS_RU[$r['status']] ?? $r['status'], $r['created_at'], $r['graded_at']];
  }
  emit_csv("results-$today.csv", ['ID', 'Ученик', 'Email', 'Тип', 'Работа', 'Балл', 'Макс', '%', 'Статус', 'Сдано', 'Проверено'], $rows);
}

fail('Неизвестный тип выгрузки', 400);
