<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/platform_download_helpers.php';
$uid=require_user();release_session();
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') fail('Нужен POST-запрос',405);
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')!==0) fail('Нужен JSON',415);
$id=(int)(body()['lesson_id'] ?? 0);
if ($id<=0) fail('Урок не указан',400);
set_time_limit(80);
try {
  $lesson=pd_lesson($uid,$id);
  $lock=pd_lock('user-prepare-'.$uid);
  try { $meta=pd_resolve($lesson); } finally { fclose($lock); }
  $expires=min(time()+600,(int)$meta['created_at']+600);
  ok(['download_url'=>'/api/platform_video_download.php?lesson_id='.$id.'&expires='.$expires.'&signature='.pd_signature($uid,$lesson,$expires),
    'height'=>$meta['height'],'estimated_bytes'=>$meta['estimated_bytes'],'expires_at'=>$expires]);
} catch (PlatformDownloadError $error) { fail($error->getMessage(),$error->status); }
