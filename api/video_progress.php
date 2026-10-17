<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/video_helpers.php';
$uid = require_user();
$in = body();
$id = (int)($in['video_id'] ?? 0);
video_require_user_row($uid,$id);
$position = max(0,min(864000,(int)round((float)($in['position_sec'] ?? 0))));
$duration = max(0,min(864000,(int)round((float)($in['duration_sec'] ?? 0))));
$completed = !empty($in['completed']) || ($duration > 0 && $position >= $duration * .95);
$now = gmdate('Y-m-d H:i:s');
$find = db()->prepare('SELECT id,completed FROM video_progress WHERE user_id=? AND video_id=? LIMIT 1');
$find->execute([$uid,$id]); $old = $find->fetch();
if ($old) db()->prepare('UPDATE video_progress SET position_sec=?,duration_sec=?,completed=?,updated_at=? WHERE id=?')
  ->execute([$position,$duration,$completed?1:0,$now,(int)$old['id']]);
else db()->prepare('INSERT INTO video_progress(user_id,video_id,position_sec,duration_sec,completed,updated_at) VALUES(?,?,?,?,?,?)')
  ->execute([$uid,$id,$position,$duration,$completed?1:0,$now]);
$event = trim((string)($in['event'] ?? 'progress'));
if (in_array($event,['play','pause','ended'],true)) {
  db()->prepare('INSERT INTO video_events(user_id,video_id,event,position_sec) VALUES(?,?,?,?)')->execute([$uid,$id,$event,$position]);
}
ok(['completed'=>$completed]);

