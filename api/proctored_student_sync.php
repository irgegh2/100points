<?php
// POST /proctored_student_sync.php — живые ответы, курсор и состояние страницы ученика.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proctored_helpers.php';
$uid = require_user();
$in = body();
$id = (int)($in['assignment_id'] ?? 0);
$a = p_assignment_for_user($uid, $id);
if (!$a || $a['format'] !== 'proctored') fail('Сессия не назначена', 403);
if (($a['status'] ?? '') === 'finished') fail('Сессия завершена', 409);

$answers = is_array($in['answers'] ?? null) ? $in['answers'] : [];
$current = max(0, (int)($in['current_question'] ?? 0));
$x = max(0, min(10000, (int)($in['cursor_x'] ?? 0)));
$y = max(0, min(10000, (int)($in['cursor_y'] ?? 0)));
$vw = max(0, min(10000, (int)($in['viewport_w'] ?? 0)));
$vh = max(0, min(10000, (int)($in['viewport_h'] ?? 0)));
$visibility = in_array(($in['visibility'] ?? ''), ['visible','hidden'], true) ? $in['visibility'] : 'visible';
$focus = in_array(($in['focus_state'] ?? ''), ['focused','blurred'], true) ? $in['focus_state'] : 'focused';
$camera = in_array(($in['camera_state'] ?? ''), ['on','off','blocked','ended'], true) ? $in['camera_state'] : 'on';
$peer = mb_substr((string)($in['peer_state'] ?? 'new'), 0, 30);
$now = date('Y-m-d H:i:s');
$json = json_encode($answers, JSON_UNESCAPED_UNICODE);
if ($json === false || strlen($json) > 500000) fail('Ответы слишком большие', 413);
$old = p_presence($id);

if ($old) {
  db()->prepare("UPDATE proctored_mock_presence SET current_question=?, cursor_x=?, cursor_y=?, viewport_w=?, viewport_h=?, visibility=?, focus_state=?, camera_state=?, peer_state=?, answers=?, last_seen=?, updated_at=? WHERE assignment_id=?")
      ->execute([$current,$x,$y,$vw,$vh,$visibility,$focus,$camera,$peer,$json,$now,$now,$id]);
} else {
  db()->prepare("INSERT INTO proctored_mock_presence (assignment_id,current_question,cursor_x,cursor_y,viewport_w,viewport_h,visibility,focus_state,camera_state,peer_state,answers,last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$id,$current,$x,$y,$vw,$vh,$visibility,$focus,$camera,$peer,$json,$now]);
}
if ($old && $old['visibility'] !== $visibility) p_event($id, $visibility === 'hidden' ? 'page_hidden' : 'page_visible', $visibility === 'hidden' ? 'Вкладка или окно скрыты' : 'Ученик вернулся на страницу');
if ($old && $old['focus_state'] !== $focus) p_event($id, $focus === 'blurred' ? 'window_blur' : 'window_focus', $focus === 'blurred' ? 'Окно потеряло фокус' : 'Окно снова активно');
if ($old && $old['camera_state'] !== $camera) p_event($id, 'camera_' . $camera, 'Состояние камеры изменилось');

ok(['messages' => p_messages($id, (int)($in['after_message_id'] ?? 0)), 'server_time' => $now]);
