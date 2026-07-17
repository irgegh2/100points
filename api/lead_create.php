<?php
// POST /lead_create.php  { name, phone, grade, source } — публичная заявка с лендинга.
// Без авторизации. Заявка падает админу в раздел «Заявки» (leads).
require __DIR__ . '/bootstrap.php';

$in    = body();
$name  = trim((string)($in['name'] ?? ''));
$grade = trim((string)($in['grade'] ?? ''));
$source = trim((string)($in['source'] ?? ''));
$phone = clean_phone($in['phone'] ?? ''); // нормализует +7XXXXXXXXXX или валит с ошибкой

if ($phone === '') fail('Укажите телефон для связи', 400);

// Лёгкий троттлинг по сессии: не чаще одной заявки в 8 секунд (анти-спам форм).
$now = time();
if ($now - (int)($_SESSION['lead_at'] ?? 0) < 8) fail('Заявка уже отправляется. Подождите немного.', 429);
$_SESSION['lead_at'] = $now;

db()->prepare(
  "INSERT INTO leads (kind, name, phone, grade, source, status) VALUES ('lead', ?, ?, ?, ?, 'new')"
)->execute([
  mb_substr($name, 0, 160),
  $phone,
  mb_substr($grade, 0, 40),
  mb_substr($source, 0, 40),
]);

ok(['sent' => true]);
