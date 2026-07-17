<?php
// GET /proctored_session_get.php?assignment_id= — данные назначенной сессии ученика.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();
$id = (int)($_GET['assignment_id'] ?? 0);
$a = p_assignment_for_user($uid, $id);
if (!$a || $a['format'] !== 'proctored') fail('Сессия не назначена', 403);
$sub = db()->prepare("SELECT status, score, max_score FROM submissions WHERE mock_id = ? AND user_id = ? LIMIT 1");
$sub->execute([(int)$a['mock_id'], $uid]);
$submission = $sub->fetch();
$presence = p_presence($id);
$draft = $presence && is_array($presence['answers']) ? $presence['answers'] : load_draft($uid, 'mock', (int)$a['mock_id']);
$canSeeQuestions = !empty($a['camera_consent_at']) && !empty($a['started_at']) && strtotime((string)$a['starts_at']) <= time() && p_time_state($a) === 'live';
ok([
  'assignment' => p_public_assignment($a), 'questions' => $canSeeQuestions ? p_questions((int)$a['mock_id']) : [],
  'messages' => p_messages($id), 'answers' => $draft ?: new stdClass(),
  'submission' => $submission ? ['status' => $submission['status'], 'score' => (int)$submission['score'], 'max_score' => (int)$submission['max_score']] : null,
]);
