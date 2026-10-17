<?php
require __DIR__ . '/gpt_helpers.php';
gpt_auth(['GET','POST']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  $homework=gpt_homework_data(gpt_integer($_GET['id'] ?? 0, 'id', 1, PHP_INT_MAX));
  ok(['homework'=>$homework,'lesson'=>gpt_lesson_context($homework['lesson_id'])]);
}
$in=gpt_json();
$lessonId=gpt_integer($in['lesson_id'] ?? 0, 'lesson_id', 1, PHP_INT_MAX);
$id=gpt_integer($in['homework_id'] ?? 0, 'homework_id', 0, PHP_INT_MAX);
$title=gpt_string($in['title'] ?? '', 'title', 200, true);
$level=(string)($in['level'] ?? 'normal');
if (!in_array($level, ['normal','medium','hard'], true)) fail('Уровень: normal, medium или hard', 400);
$deadline=gpt_string($in['deadline'] ?? '', 'deadline', 19);
if ($deadline !== '') {
  $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $deadline);
  if (!$date || $date->format('Y-m-d H:i:s') !== $deadline) fail('Дедлайн: YYYY-MM-DD HH:MM:SS, Москва', 400);
}
$questions=$in['questions'] ?? null;
if (!is_array($questions) || !array_is_list($questions) || !$questions || count($questions)>100) fail('Нужно 1–100 заданий', 400);
foreach ($questions as $q) if (!is_array($q)) fail('Неверное задание', 400);
$questions=array_map('gpt_question', $questions);
$lesson=gpt_lesson_context($lessonId);
$normalized=['title'=>$title,'level'=>$level,'deadline'=>$deadline,'questions'=>$questions];
if (!empty($in['dry_run'])) ok(['preview'=>$normalized,'lesson'=>$lesson,'saved'=>false]);
$pdo=db();
$pdo->beginTransaction();
try {
  // Сериализуем создание внутри урока; повтор запроса не создаёт дубликат.
  $lock=$pdo->prepare('SELECT id FROM lessons WHERE id=?' . (is_prod() ? ' FOR UPDATE' : ''));
  $lock->execute([$lessonId]);
  $old=null;
  if ($id>0) {
    $st=$pdo->prepare('SELECT id FROM homeworks WHERE id=?' . (is_prod() ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $old=gpt_homework_data($id);
    if ($old['lesson_id']!==$lessonId) fail('Домашка относится к другому уроку', 409);
  } else {
    $st=$pdo->prepare('SELECT id FROM homeworks WHERE lesson_id=? AND title=? ORDER BY id');
    $st->execute([$lessonId,$title]); $matches=$st->fetchAll();
    if (count($matches)>1) fail('У урока несколько домашних с таким названием. Укажи homework_id.', 409);
    if ($matches) {
      $id=(int)$matches[0]['id'];
      $st=$pdo->prepare('SELECT id FROM homeworks WHERE id=?' . (is_prod() ? ' FOR UPDATE' : ''));
      $st->execute([$id]);
      $old=gpt_homework_data($id);
    }
  }
  if ($old) {
    $oldQuestions=[];
    foreach ($old['questions'] as $q) {
      unset($q['id'],$q['position']);
      foreach ($q['attachments'] as &$attachment) unset($attachment['position']);
      unset($attachment);
      $oldQuestions[]=$q;
    }
    if ($old['title']===$title && $old['level']===$level && $old['deadline']===$deadline && $oldQuestions==$questions) {
      $pdo->commit(); ok(['saved'=>true,'unchanged'=>true,'homework'=>$old]);
    }
    if (empty($in['homework_id']) || !hash_equals($old['revision'], (string)($in['expected_revision'] ?? ''))) {
      fail('Домашка уже существует или изменилась. Прочитай её и передай homework_id и свежую expected_revision.', 409);
    }
    // В этой версии GPT заполняет только пустую домашку. Существующие вопросы
    // и их ID сохраняются, в том числе если ученик уже открыл форму ответа.
    if ($old['questions']) fail('В домашке уже есть задания. Создай отдельную домашку или измени её через админку.', 409);
    $st=$pdo->prepare('SELECT COUNT(*) FROM submissions WHERE homework_id=?'); $st->execute([$id]);
    if ((int)$st->fetchColumn()>0) fail('У этой домашки уже есть работы учеников. Создай отдельную домашку.', 409);
    $st=$pdo->prepare('SELECT COUNT(*) FROM answers a JOIN questions q ON q.id=a.question_id WHERE q.homework_id=?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn()>0) fail('У заданий уже есть ответы учеников. Создай отдельную домашку.', 409);
    $pdo->prepare('UPDATE homeworks SET title=?,level=?,deadline=? WHERE id=?')->execute([$title,$level,$deadline,$id]);
  } else {
    $st=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM homeworks WHERE lesson_id=?'); $st->execute([$lessonId]);
    $position=(int)$st->fetchColumn();
    $pdo->prepare('INSERT INTO homeworks (lesson_id,title,level,deadline,position) VALUES (?,?,?,?,?)')->execute([$lessonId,$title,$level,$deadline,$position]);
    $id=(int)$pdo->lastInsertId();
  }
  $insert=$pdo->prepare('INSERT INTO questions (homework_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $attach=$pdo->prepare('INSERT INTO question_attachments (question_id,kind,name,size,url,position) VALUES (?,?,?,?,?,?)');
  foreach ($questions as $position=>$q) {
    $json=fn($v)=>$v===null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $insert->execute([$id,$q['type'],$q['text'],$json($q['options']),$json($q['correct']),$q['solution'],$q['hint'],$q['points'],$q['optional'],$q['hard'],$q['case_sensitive'],$q['grade_mode'],$q['file_limit'],$position]);
    $questionId=(int)$pdo->lastInsertId();
    foreach ($q['attachments'] as $index=>$a) $attach->execute([$questionId,$a['kind'],$a['name'],$a['size'],$a['url'],$index]);
  }
  live_content_touch();
  $result=gpt_homework_data($id);
  $pdo->commit();
  error_log('gpt_homework saved homework_id='.$id.' lesson_id='.$lessonId.' questions='.count($questions));
  ok(['saved'=>true,'unchanged'=>false,'homework'=>$result], $old ? 200 : 201);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $error;
}
