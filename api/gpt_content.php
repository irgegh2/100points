<?php
require __DIR__ . '/gpt_helpers.php';
gpt_auth(['GET']);
// Полный постраничный доступ к учебным таблицам по явному белому списку.
// Пользователи, ответы учеников, покупки, сообщения, сессии и settings отсутствуют.
$tables = [
  'subjects'=>['id','name','slug','color','position'],
  'courses'=>['id','subject_id','title','type','position'],
  'months'=>['id','course_id','name','kind','starts_on','ends_on','position'],
  'blocks'=>['id','course_id','month_id','n','title','position'],
  'lessons'=>['id','block_id','parent_lesson_id','type','title','meta','air','air_at','opens_at','duration','description','position'],
  'materials'=>['id','lesson_id','name','size','url','kind','position'],
  'timecodes'=>['id','lesson_id','t','label','position'],
  'homeworks'=>['id','lesson_id','level','title','deadline','position'],
  'questions'=>['id','homework_id','mock_id','subtopic_id','type','text','options','correct','solution','hint','points','difficulty','optional','hard','case_sensitive','grade_mode','file_limit','position'],
  'question_attachments'=>['id','question_id','kind','name','size','url','position'],
  'month_photos'=>['id','month_id','url','position'],
  'topics'=>['id','subject_id','number','name','part','error_rate','position'],
  'subtopics'=>['id','topic_id','name','position'],
  'mock_exams'=>['id','course_id','month','title','format','access_mode','position'],
  'bank_sources'=>['id','subject_id','name','position'],
  'bank_variants'=>['id','source_id','subject_id','name','position'],
  'variant_questions'=>['id','variant_id','question_id','position'],
  'trainer_tasks'=>['id','subject_id','level','text','answer','solution','position'],
  'trainer_qa_topics'=>['id','subject_id','level','name','examples','position'],
  'help_categories'=>['id','name','position'],
  'help_qa'=>['id','category_id','question','answer','position'],
  'events'=>['id','course_id','type','title','starts_at','duration','link','position'],
];
$entity=(string)($_GET['entity'] ?? '');
if ($entity==='') ok(['entities'=>$tables,'timezone'=>'Europe/Moscow','today'=>date('Y-m-d')]);
if (!isset($tables[$entity])) fail('Неизвестная учебная сущность', 400);
$after=gpt_integer($_GET['after_id'] ?? 0, 'after_id', 0, PHP_INT_MAX);
$limit=gpt_integer($_GET['limit'] ?? 25, 'limit', 1, 100);
$st=db()->prepare('SELECT '.implode(',', $tables[$entity]).' FROM '.$entity.' WHERE id>? ORDER BY id LIMIT '.($limit+1));
$st->execute([$after]); $items=$st->fetchAll();
$more=count($items)>$limit;
if ($more) array_pop($items);
foreach ($items as &$item) {
  foreach (['options','correct'] as $field) {
    if (isset($item[$field])) $item[$field]=json_decode($item[$field], true);
  }
}
unset($item);
ok(['entity'=>$entity,'items'=>$items,'next_after_id'=>$more ? (int)end($items)['id'] : null]);
