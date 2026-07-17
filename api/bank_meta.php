<?php
// GET /bank_meta.php?subject_id=<id> — данные для раздела «Банк заданий».
// Всегда: список доступных предметов. Если subject_id задан и доступен — темы (с % ошибок и
// кол-вом вопросов), подтемы (с кол-вом), источники и варианты для режима «Пробник».
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$subjects = user_accessible_subjects($uid);
$out = ['subjects' => $subjects];

$subjectId = (int)($_GET['subject_id'] ?? 0);
$allowed = array_column($subjects, 'id');
if ($subjectId > 0 && in_array($subjectId, $allowed, true)) {
  // Темы (номера заданий) с кол-вом вопросов и сколько уже решено (отвечал) этим учеником.
  $tstmt = db()->prepare(
    "SELECT t.id, t.number, t.name, t.part, t.error_rate,
            (SELECT COUNT(*) FROM questions q JOIN subtopics st ON st.id = q.subtopic_id WHERE st.topic_id = t.id) AS cnt,
            (SELECT COUNT(*) FROM questions q JOIN subtopics st ON st.id = q.subtopic_id
                 JOIN bank_progress bp ON bp.question_id = q.id AND bp.user_id = ? WHERE st.topic_id = t.id) AS solved
     FROM topics t WHERE t.subject_id = ? ORDER BY t.position, t.number, t.id"
  );
  $tstmt->execute([$uid, $subjectId]);
  $topics = [];
  $topicIndex = [];
  foreach ($tstmt as $t) {
    $topics[] = [
      'id' => (int)$t['id'], 'number' => $t['number'] !== null ? (int)$t['number'] : null,
      'name' => $t['name'], 'part' => $t['part'], 'error_rate' => $t['error_rate'] !== null ? (int)$t['error_rate'] : null,
      'count' => (int)$t['cnt'], 'solved' => (int)$t['solved'], 'subtopics' => [],
    ];
    $topicIndex[(int)$t['id']] = count($topics) - 1;
  }
  // Подтемы с кол-вом вопросов и сколько решено.
  if ($topicIndex) {
    $params = array_merge([$uid], array_keys($topicIndex));
    $sstmt = db()->prepare(
      "SELECT st.id, st.topic_id, st.name,
              (SELECT COUNT(*) FROM questions q WHERE q.subtopic_id = st.id) AS cnt,
              (SELECT COUNT(*) FROM questions q JOIN bank_progress bp ON bp.question_id = q.id AND bp.user_id = ? WHERE q.subtopic_id = st.id) AS solved
       FROM subtopics st WHERE st.topic_id IN (" . implode(',', array_fill(0, count($topicIndex), '?')) . ")
       ORDER BY st.position, st.id"
    );
    $sstmt->execute($params);
    foreach ($sstmt as $st) {
      $ti = $topicIndex[(int)$st['topic_id']] ?? null;
      if ($ti === null) continue;
      $topics[$ti]['subtopics'][] = ['id' => (int)$st['id'], 'name' => $st['name'], 'count' => (int)$st['cnt'], 'solved' => (int)$st['solved']];
    }
  }
  $out['topics'] = $topics;

  // Источники + варианты (для «Пробник»).
  $srcStmt = db()->prepare("SELECT id, name FROM bank_sources WHERE subject_id = ? ORDER BY position, id");
  $srcStmt->execute([$subjectId]);
  $sources = []; $srcIndex = [];
  foreach ($srcStmt as $s) { $sources[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'variants' => []]; $srcIndex[(int)$s['id']] = count($sources) - 1; }
  if ($srcIndex) {
    $vstmt = db()->prepare("SELECT id, source_id, name FROM bank_variants WHERE subject_id = ? ORDER BY position, id");
    $vstmt->execute([$subjectId]);
    foreach ($vstmt as $v) {
      $si = $srcIndex[(int)$v['source_id']] ?? null;
      if ($si === null) continue;
      $sources[$si]['variants'][] = ['id' => (int)$v['id'], 'name' => $v['name']];
    }
  }
  $out['sources'] = $sources;
}

ok($out);
