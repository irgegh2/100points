<?php
// GET /library_get.php?subject_id=&q= — материалы библиотеки ученику.
// Видны: материалы по доступным предметам (зачисление + show_features) + общие (subject_id IS NULL).
require __DIR__ . '/bootstrap.php';
$uid = require_user();
release_session();

$subjects = user_accessible_subjects($uid);
$allowed = array_map(fn($s) => (int)$s['id'], $subjects);

$subjectId = (int)($_GET['subject_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$rows = db()->query("SELECT id, subject_id, category, title, url, size, kind FROM library_items ORDER BY position, id")->fetchAll();
$items = [];
foreach ($rows as $r) {
  $sid = $r['subject_id'] !== null ? (int)$r['subject_id'] : null;
  // общий (NULL) — всем; по предмету — только если предмет доступен
  if ($sid !== null && !in_array($sid, $allowed, true)) continue;
  if ($subjectId > 0 && $sid !== $subjectId) continue;
  if ($q !== '' && mb_stripos($r['title'] . ' ' . $r['category'], $q) === false) continue;
  $items[] = [
    'id' => (int)$r['id'], 'category' => $r['category'], 'title' => $r['title'],
    'url' => $r['url'], 'size' => $r['size'], 'kind' => $r['kind'],
  ];
}

ok(['subjects' => $subjects, 'items' => $items]);
