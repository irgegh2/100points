<?php

function p_assignment_for_user(int $uid, int $assignmentId = 0, int $mockId = 0): ?array {
  $where = $assignmentId > 0 ? 'a.id = ?' : 'a.mock_id = ?';
  $id = $assignmentId > 0 ? $assignmentId : $mockId;
  $s = db()->prepare(
    "SELECT a.*, m.title, m.month, m.format, m.access_mode, m.course_id,
            c.title AS course, su.name AS subject, su.color, u.name AS user_name, u.email AS user_email
     FROM mock_assignments a
     JOIN mock_exams m ON m.id = a.mock_id
     JOIN courses c ON c.id = m.course_id
     JOIN subjects su ON su.id = c.subject_id
     JOIN users u ON u.id = a.user_id
     WHERE $where AND a.user_id = ? LIMIT 1"
  );
  $s->execute([$id, $uid]);
  return $s->fetch() ?: null;
}

function p_assignment(int $assignmentId): ?array {
  $s = db()->prepare(
    "SELECT a.*, m.title, m.month, m.format, m.access_mode, m.course_id,
            c.title AS course, su.name AS subject, su.color, u.name AS user_name, u.email AS user_email
     FROM mock_assignments a
     JOIN mock_exams m ON m.id = a.mock_id
     JOIN courses c ON c.id = m.course_id
     JOIN subjects su ON su.id = c.subject_id
     JOIN users u ON u.id = a.user_id
     WHERE a.id = ? LIMIT 1"
  );
  $s->execute([$assignmentId]);
  return $s->fetch() ?: null;
}

function p_time_state(array $a): string {
  if (($a['status'] ?? '') === 'finished' || !empty($a['finished_at'])) return 'finished';
  if (($a['format'] ?? '') !== 'proctored') return 'available';
  $start = strtotime((string)($a['starts_at'] ?? ''));
  if (!$start) return 'unscheduled';
  $now = time();
  $end = $start + max(15, (int)($a['duration'] ?? 180)) * 60;
  if ($now < $start - 900) return 'scheduled';
  if ($now < $start) return 'ready';
  if ($now <= $end + 900) return 'live';
  return 'ended';
}

function p_public_assignment(array $a): array {
  return [
    'id' => (int)$a['id'], 'mock_id' => (int)$a['mock_id'], 'user_id' => (int)$a['user_id'],
    'title' => $a['title'], 'month' => $a['month'], 'format' => $a['format'], 'access_mode' => $a['access_mode'],
    'subject' => $a['subject'], 'course' => $a['course'], 'color' => $a['color'],
    'user_name' => $a['user_name'], 'user_email' => $a['user_email'],
    'starts_at' => $a['starts_at'], 'duration' => (int)$a['duration'], 'status' => $a['status'],
    'camera_consent_at' => $a['camera_consent_at'], 'started_at' => $a['started_at'], 'finished_at' => $a['finished_at'],
    'time_state' => p_time_state($a),
  ];
}

function p_questions(int $mockId): array {
  $s = db()->prepare("SELECT id, type, text, options, points, optional, hard, COALESCE(file_limit,1) AS file_limit FROM questions WHERE mock_id = ? ORDER BY position, id");
  $s->execute([$mockId]);
  $out = [];
  foreach ($s as $q) $out[] = [
    'id' => (int)$q['id'], 'type' => $q['type'], 'text' => $q['text'],
    'options' => $q['options'] !== null ? json_decode($q['options'], true) : null,
    'points' => (int)$q['points'], 'optional' => (int)$q['optional'] === 1, 'hard' => (int)$q['hard'] === 1,
    'file_limit' => max(1, min(10, (int)$q['file_limit'])),
  ];
  return $out;
}

function p_messages(int $assignmentId, int $afterId = 0): array {
  $s = db()->prepare("SELECT id, sender, content, created_at FROM proctored_mock_messages WHERE assignment_id = ? AND id > ? ORDER BY id");
  $s->execute([$assignmentId, $afterId]);
  return array_map(fn($m) => ['id' => (int)$m['id'], 'sender' => $m['sender'], 'content' => $m['content'], 'created_at' => $m['created_at']], $s->fetchAll());
}

function p_presence(int $assignmentId): ?array {
  $s = db()->prepare("SELECT * FROM proctored_mock_presence WHERE assignment_id = ? LIMIT 1");
  $s->execute([$assignmentId]);
  $row = $s->fetch();
  if (!$row) return null;
  $row['id'] = (int)$row['id'];
  $row['assignment_id'] = (int)$row['assignment_id'];
  foreach (['current_question','cursor_x','cursor_y','viewport_w','viewport_h'] as $f) $row[$f] = (int)$row[$f];
  $row['online'] = !empty($row['last_seen']) && strtotime((string)$row['last_seen']) >= time() - 7;
  $row['answers'] = $row['answers'] ? (json_decode($row['answers'], true) ?: new stdClass()) : new stdClass();
  return $row;
}

function p_event(int $assignmentId, string $type, string $detail = ''): void {
  db()->prepare("INSERT INTO proctored_mock_events (assignment_id, event_type, detail) VALUES (?,?,?)")
      ->execute([$assignmentId, mb_substr($type, 0, 40), mb_substr($detail, 0, 255)]);
}
