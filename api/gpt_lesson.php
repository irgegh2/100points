<?php
require __DIR__ . '/gpt_helpers.php';
gpt_auth(['GET']);
$id=gpt_integer($_GET['id'] ?? 0, 'id', 1, PHP_INT_MAX);
$lesson=gpt_lesson_context($id);
$st=db()->prepare('SELECT id,level,title,deadline,position,(SELECT COUNT(*) FROM questions q WHERE q.homework_id=h.id) AS question_count FROM homeworks h WHERE lesson_id=? ORDER BY position,id');
$st->execute([$id]); $homeworks=$st->fetchAll();
$st=db()->prepare('SELECT id,name,size,url,kind,position FROM materials WHERE lesson_id=? ORDER BY position,id');
$st->execute([$id]); $materials=$st->fetchAll();
$st=db()->prepare('SELECT id,t,label,position FROM timecodes WHERE lesson_id=? ORDER BY position,id');
$st->execute([$id]);
ok(['lesson'=>$lesson,'homeworks'=>$homeworks,'materials'=>$materials,'timecodes'=>$st->fetchAll()]);
