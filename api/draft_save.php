<?php
// POST /draft_save.php  { kind: 'homework'|'mock', ref_id, answers: { question_id: value, ... } }
// Автосохранение незаконченных ответов (до отправки). Одна строка на (ученик, тип, объект) — перезаписываем.
require __DIR__ . '/bootstrap.php';
$uid = require_user();

$in = body();
$kind = trim($in['kind'] ?? '');
$refId = (int)($in['ref_id'] ?? 0);
$answers = $in['answers'] ?? null;
if (!in_array($kind, ['homework', 'mock'], true)) fail('Неверный тип', 400);
if ($refId <= 0) fail('Не указан объект', 400);

$json = json_encode($answers, JSON_UNESCAPED_UNICODE);

// upsert по (user_id, kind, ref_id)
$ex = db()->prepare("SELECT id FROM answer_drafts WHERE user_id = ? AND kind = ? AND ref_id = ? LIMIT 1");
$ex->execute([$uid, $kind, $refId]);
if ($row = $ex->fetch()) {
  db()->prepare("UPDATE answer_drafts SET answers = ?, updated_at = " . (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'CURRENT_TIMESTAMP' : "datetime('now')") . " WHERE id = ?")
      ->execute([$json, (int)$row['id']]);
} else {
  db()->prepare("INSERT INTO answer_drafts (user_id, kind, ref_id, answers) VALUES (?,?,?,?)")
      ->execute([$uid, $kind, $refId, $json]);
}

ok(['saved' => true]);
