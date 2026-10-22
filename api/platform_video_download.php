<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/platform_download_helpers.php';
$uid=require_user();release_session();
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') fail('Нужен GET-запрос',405);
$process=null;$pipes=[];$slot=null;$userLock=null;
$cleanup=static function () use (&$process,&$pipes,&$slot,&$userLock): void {
  if (is_resource($process)) {
    if (proc_get_status($process)['running']) proc_terminate($process,9);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($process);$process=null;
  }
  if (is_resource($slot)) fclose($slot);
  if (is_resource($userLock)) fclose($userLock);
};
register_shutdown_function($cleanup);
try {
  $id=(int)($_GET['lesson_id'] ?? 0);$expires=(int)($_GET['expires'] ?? 0);
  $signature=(string)($_GET['signature'] ?? '');
  if ($id<=0 || $expires<time() || $expires>time()+610 || !preg_match('/^[a-f0-9]{64}$/',$signature)) throw new PlatformDownloadError('Ссылка истекла. Нажми «Скачать видео» в уроке ещё раз.',410);
  $lesson=pd_lesson($uid,$id);
  if (!hash_equals(pd_signature($uid,$lesson,$expires),$signature)) throw new PlatformDownloadError('Нет доступа к этой загрузке',403);
  $meta=pd_cached($lesson,610);
  if (!$meta) throw new PlatformDownloadError('Нужно заново подготовить скачивание в уроке',410);
  $userLock=pd_lock('user-stream-'.$uid);$slot=pd_slot('stream');
  $args=pd_ffmpeg_args($meta);
  $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,pd_process_env());
  if (!is_resource($process)) throw new PlatformDownloadError('Не удалось запустить скачивание',503);
  fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
  $start=microtime(true);$first='';$error='';
  while ($first==='') {
    $first=stream_get_contents($pipes[1],65536);
    $error.=stream_get_contents($pipes[2]);
    if ($first!=='') break;
    if (!proc_get_status($process)['running'] || microtime(true)-$start>35) throw new PlatformDownloadError('Платформа не отдала видео. Вернись в урок и попробуй позже.',502);
    usleep(50000);
  }
  set_time_limit(0);ignore_user_abort(true);
  while (ob_get_level()>0) ob_end_clean();
  header('Content-Type: video/mp4');
  $name=preg_replace('/[\\\\\/\x00-\x1F\x7F]/u','_', $lesson['title']).'.mp4';
  header('Content-Disposition: attachment; filename="lesson-'.$id.'.mp4"; filename*=UTF-8\'\''.rawurlencode($name));
  header('Cache-Control: private, no-store');header('Accept-Ranges: none');
  header('X-Content-Type-Options: nosniff');header('X-Accel-Buffering: no');
  @ini_set('zlib.output_compression','0');
  echo $first;flush();
  while (true) {
    $chunk=stream_get_contents($pipes[1],256*1024);
    $error.=stream_get_contents($pipes[2]);if(strlen($error)>8192)$error=substr($error,-8192);
    if ($chunk!=='') { echo $chunk;flush(); }
    if (connection_aborted() || microtime(true)-$start>7200) break;
    $status=proc_get_status($process);
    if (!$status['running'] && feof($pipes[1])) break;
    if ($chunk==='') usleep(30000);
  }
  $cleanup();exit;
} catch (PlatformDownloadError $error) {
  $cleanup();
  // При отказе браузер остаётся на понятной странице с возвратом в урок,
  // а не показывает JSON вместо кабинета.
  http_response_code($error->status);header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
  $back='https://lk.100-points.ru/';
  if (isset($lesson['course_id'],$lesson['id'])) $back.='#courses/'.(int)$lesson['course_id'].'/'.(int)$lesson['id'];
  $message=htmlspecialchars($error->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Скачивание видео</title><style>body{font:16px/1.6 system-ui,sans-serif;background:#f5f6f8;color:#15171c;margin:0;padding:24px}main{max-width:520px;margin:12vh auto;background:white;border-radius:20px;padding:28px}h1{font-size:23px}a{display:inline-block;background:#ff7a00;color:white;text-decoration:none;border-radius:12px;padding:10px 18px}</style><main><h1>Не удалось скачать видео</h1><p>'.$message.'</p><a href="'.htmlspecialchars($back,ENT_QUOTES,'UTF-8').'">Вернуться в урок</a></main></html>';
  exit;
}
