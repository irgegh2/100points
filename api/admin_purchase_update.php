<?php
// POST /admin_purchase_update.php  { purchase_id, status:'pending'|'done'|'canceled', note? }
// Меняет статус заявки. Отмена возвращает кристаллы ученику; снятие отмены — списывает снова.
require __DIR__ . '/bootstrap.php';
require_admin();

$in = body();
$pid = (int)($in['purchase_id'] ?? 0);
$status = (string)($in['status'] ?? '');
$note = trim((string)($in['note'] ?? ''));
if ($pid <= 0) fail('Не указана заявка', 400);
if (!in_array($status, ['pending', 'done', 'canceled'], true)) fail('Неверный статус', 400);

$p = db()->prepare("SELECT user_id, price, status FROM purchases WHERE id = ? LIMIT 1");
$p->execute([$pid]);
$row = $p->fetch();
if (!$row) fail('Заявка не найдена', 404);
$su = (int)$row['user_id'];
$price = (int)$row['price'];

try {
  $pdo = db();
  $pdo->beginTransaction();
  // Отмена → возврат кристаллов (если ещё не была отменена).
  if ($status === 'canceled' && $row['status'] !== 'canceled') {
    $pdo->prepare("UPDATE wallets SET crystals = crystals + ? WHERE user_id = ?")->execute([$price, $su]);
    $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'crystals', ?, ?, 'refund', ?)")
        ->execute([$su, $price, 'Возврат за отменённую покупку', $pid]);
  }
  // Снятие отмены → списываем снова.
  if ($row['status'] === 'canceled' && $status !== 'canceled') {
    $pdo->prepare("UPDATE wallets SET crystals = crystals - ? WHERE user_id = ?")->execute([$price, $su]);
    $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'crystals', ?, ?, 'purchase', ?)")
        ->execute([$su, -$price, 'Повторное списание за покупку', $pid]);
  }
  $pdo->prepare("UPDATE purchases SET status = ?, note = ?, updated_at = ? WHERE id = ?")
      ->execute([$status, $note !== '' ? $note : null, date('Y-m-d H:i:s'), $pid]);
  $pdo->commit();
} catch (Exception $e) {
  if (db()->inTransaction()) db()->rollBack();
  fail('Не удалось обновить заявку', 500);
}

// Уведомление ученику о выдаче/отмене (Этап 13).
$nm = db()->prepare("SELECT item_name FROM purchases WHERE id = ?");
$nm->execute([$pid]);
$iname = $nm->fetchColumn() ?: 'товар';
if ($status === 'done') notify($su, 'shop', "«{$iname}» выдано! " . ($note !== '' ? $note : 'Проверь сообщения / детали у куратора.'), 'shop');
elseif ($status === 'canceled' && $row['status'] !== 'canceled') notify($su, 'shop', "Покупка «{$iname}» отменена, кристаллы возвращены.", 'shop');

ok(['status' => $status]);
