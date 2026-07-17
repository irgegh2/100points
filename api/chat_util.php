<?php
// Утилита для чатов с наставником (Этап 12).

// Чат на пару (ученик, предмет) — создаём при первом обращении + приветствие куратора.
function get_or_create_chat(int $uid, int $subjectId): int {
  $c = db()->prepare("SELECT id FROM chats WHERE user_id = ? AND subject_id = ? LIMIT 1");
  $c->execute([$uid, $subjectId]);
  if ($r = $c->fetch()) return (int)$r['id'];

  db()->prepare("INSERT INTO chats (user_id, subject_id, last_at) VALUES (?,?,?)")
      ->execute([$uid, $subjectId, date('Y-m-d H:i:s')]);
  $id = (int)db()->lastInsertId();
  $sn = db()->prepare("SELECT name FROM subjects WHERE id = ?");
  $sn->execute([$subjectId]);
  $sname = $sn->fetchColumn() ?: 'предмету';
  db()->prepare("INSERT INTO chat_messages (chat_id, from_role, text, is_read) VALUES (?, 'mentor', ?, 1)")
      ->execute([$id, "Привет! Это чат с куратором по предмету «{$sname}». Задавай любой вопрос — ответим в течение дня 🙂"]);
  return $id;
}

// HH:MM из 'YYYY-MM-DD HH:MM:SS'.
function msg_time(?string $ts): string {
  return $ts ? substr($ts, 11, 5) : '';
}
