<?php
require_once __DIR__ . '/video_helpers.php';

class PlatformDownloadError extends RuntimeException {
  public int $status;
  public function __construct(string $message, int $status = 422) {
    parent::__construct($message); $this->status = $status;
  }
}

// URL берём исключительно из урока, а не из запроса ученика.
function pd_source(array $lesson): ?array {
  if (($lesson['video_source'] ?? '') !== 'link') return null;
  $url = trim((string)($lesson['video_url'] ?? ''));
  $embed = external_stream_embed($url);
  $provider = $embed['provider'] ?? '';
  if ($provider === 'youtube') {
    if (!preg_match('~/embed/([a-zA-Z0-9_-]{11})~', $embed['embed_url'], $m)) return null;
    return ['provider'=>'youtube', 'url'=>'https://www.youtube.com/watch?v='.$m[1]];
  }
  if ($provider === 'rutube') {
    $parts = parse_url($embed['embed_url']);
    if (!preg_match('~^/play/embed/([a-zA-Z0-9_-]{16,})/?$~', $parts['path'] ?? '', $m)) return null;
    parse_str($parts['query'] ?? '', $query);
    $params = [];
    if (isset($query['p']) && is_string($query['p']) && preg_match('/^[a-zA-Z0-9_-]+$/', $query['p'])) $params['p']=$query['p'];
    return ['provider'=>'rutube', 'url'=>'https://rutube.ru/video/'.$m[1].'/'.($params ? '?'.http_build_query($params) : '')];
  }
  if ($provider === 'vk') {
    $parts = parse_url($embed['embed_url']);
    if (($parts['path'] ?? '') !== '/video_ext.php') return null; // Прямые VK Live не скачиваем.
    parse_str($parts['query'] ?? '', $query);
    if (!preg_match('/^-?\d+$/', (string)($query['oid'] ?? '')) || !preg_match('/^\d+$/', (string)($query['id'] ?? ''))) return null;
    // Секретная ссылка VK может требовать hash, который уже сохранён преподавателем.
    if (!empty($query['hash'])) {
      return ['provider'=>'vk', 'url'=>'https://vk.com/video_ext.php?'.http_build_query(array_intersect_key($query, array_flip(['oid','id','hash'])))];
    }
    return ['provider'=>'vk', 'url'=>'https://vkvideo.ru/video'.$query['oid'].'_'.$query['id']];
  }
  return null;
}

function pd_lesson(int $uid, int $id): array {
  $st = db()->prepare("SELECT l.id,l.title,l.video_source,l.video_url,l.parent_lesson_id,l.opens_at,
      p.opens_at AS parent_opens_at,b.course_id
    FROM lessons l LEFT JOIN lessons p ON p.id=l.parent_lesson_id
    JOIN blocks b ON b.id=l.block_id WHERE l.id=? LIMIT 1");
  $st->execute([$id]); $lesson=$st->fetch();
  if (!$lesson || !user_has_course_access($uid, (int)$lesson['course_id'])) throw new PlatformDownloadError('Нет доступа к этому уроку', 403);
  $opens=max(trim((string)$lesson['opens_at']),trim((string)$lesson['parent_opens_at']));
  if ($opens!=='' && $opens>date('Y-m-d H:i')) throw new PlatformDownloadError('Урок ещё не открыт', 403);
  $ids=[$id]; if ($lesson['parent_lesson_id']) $ids[]=(int)$lesson['parent_lesson_id'];
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $st=db()->prepare("SELECT 1 FROM lesson_prereqs p JOIN lessons r ON r.id=p.required_lesson_id
    LEFT JOIN lesson_progress lp ON lp.lesson_id=p.required_lesson_id AND lp.user_id=?
    WHERE p.lesson_id IN ($ph) AND (lp.id IS NULL OR EXISTS (
      SELECT 1 FROM homeworks h WHERE h.lesson_id=r.id AND (h.level='normal' OR h.level IS NULL OR h.level='')
      AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.homework_id=h.id AND s.user_id=?))) LIMIT 1");
  $st->execute(array_merge([$uid],$ids,[$uid]));
  if ($st->fetchColumn()) throw new PlatformDownloadError('Сначала пройди предыдущие уроки',403);
  $source=pd_source($lesson);
  if (!$source) throw new PlatformDownloadError('Для этого источника скачивание пока не поддерживается');
  $lesson['download_source']=$source;
  $lesson['source_hash']=hash('sha256',$source['url']);
  return $lesson;
}

function pd_root(): string {
  $root=video_account_root().'/platform-downloads';
  if (!is_dir($root) && !@mkdir($root,0700,true) && !is_dir($root)) throw new PlatformDownloadError('Скачивание временно недоступно',503);
  return $root;
}

function pd_lock(string $name) {
  $fp=@fopen(pd_root().'/'.$name.'.lock','c');
  if (!$fp || !flock($fp,LOCK_EX|LOCK_NB)) {
    if ($fp) fclose($fp);
    throw new PlatformDownloadError('Другая загрузка уже выполняется. Попробуй чуть позже.',429);
  }
  return $fp;
}

function pd_slot(string $kind) {
  for ($slot=0;$slot<2;$slot++) {
    try { return pd_lock($kind.'-'.$slot); } catch (PlatformDownloadError $ignored) {}
  }
  throw new PlatformDownloadError('Сейчас готовятся другие видео. Попробуй через минуту.',429);
}

function pd_ca_file(): string {
  foreach ([video_account_root().'/bin/video-cacert.pem',(string)ini_get('curl.cainfo'),(string)ini_get('openssl.cafile'),'/etc/pki/tls/certs/ca-bundle.crt','/etc/ssl/certs/ca-certificates.crt','/etc/ssl/cert.pem'] as $candidate) {
    if ($candidate!=='' && is_readable($candidate)) return $candidate;
  }
  throw new PlatformDownloadError('Не удалось настроить безопасное соединение с платформой',503);
}

function pd_process_env(): array {
  // OpenSSL использует этот путь и для вложенных HLS-сегментов, которым
  // FFmpeg не всегда передаёт ca_file основного плейлиста.
  return array_merge(getenv() ?: [],['SSL_CERT_FILE'=>pd_ca_file()]);
}

function pd_run(array $args, int $timeout=65): array {
  $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,pd_process_env());
  if (!is_resource($process)) throw new PlatformDownloadError('Не удалось запустить подготовку видео',503);
  fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
  $out='';$err='';$start=microtime(true);$exit=-1;
  try {
    while (true) {
      $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
      if (strlen($out)>16*1024*1024 || strlen($err)>1024*1024) throw new PlatformDownloadError('Платформа вернула слишком большой ответ');
      $status=proc_get_status($process);
      if (!$status['running']) { $exit=$status['exitcode'];break; }
      if (microtime(true)-$start>$timeout) throw new PlatformDownloadError('Платформа долго не отвечает. Попробуй позже.',504);
      usleep(30000);
    }
    $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
  } finally {
    $status=proc_get_status($process);
    if ($status['running']) proc_terminate($process,9);
    fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);
    if ($exit<0) $exit=$closed;
  }
  return ['code'=>$exit,'stdout'=>$out,'stderr'=>$err];
}

function pd_public_remote(string $url): bool {
  $p=parse_url($url);
  if (!$p || ($p['scheme'] ?? '')!=='https' || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port']!==443)) return false;
  $host=strtolower($p['host'] ?? '');
  if ($host==='' || !str_contains($host,'.') || preg_match('/(?:^localhost$|\.(?:localhost|local|internal)$)/',$host)) return false;
  if (filter_var($host,FILTER_VALIDATE_IP)) return (bool)filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
  $addresses=@dns_get_record($host,DNS_A|DNS_AAAA);
  if (!$addresses) return false;
  foreach ($addresses as $record) {
    $ip=$record['ip'] ?? $record['ipv6'] ?? null;
    if ($ip!==null && !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
  }
  return true;
}

function pd_metadata_path(array $lesson): string { return pd_root().'/'.$lesson['source_hash'].'.json'; }

function pd_cached(array $lesson, int $maxAge=180): ?array {
  $path=pd_metadata_path($lesson);
  if (!is_file($path) || time()-(int)filemtime($path)>$maxAge) return null;
  $meta=json_decode((string)file_get_contents($path),true);
  return is_array($meta) && ($meta['source_hash'] ?? '')===$lesson['source_hash'] ? $meta : null;
}

function pd_resolve(array $lesson): array {
  $cached=pd_cached($lesson,300);if ($cached) return $cached;
  if (!function_exists('proc_open') || !is_executable(video_binary_path('yt-dlp')) || !video_runtime_ready()) throw new PlatformDownloadError('Сервис скачивания пока недоступен',503);
  $slot=pd_slot('prepare');
  $format='bestvideo[ext=mp4][vcodec^=avc1][height<=720]+bestaudio[ext=m4a]/best[ext=mp4][height<=720]/bestvideo[ext=mp4][height<=720]+bestaudio[ext=m4a]';
  try {
    $args=[video_binary_path('yt-dlp'),'--ignore-config','--no-plugin-dirs','--no-playlist','--no-cache-dir','--no-warnings',
      '--force-ipv4','--socket-timeout','12','--retries','1','--extractor-retries','1','--use-extractors','youtube,rutube,vk',
      '--js-runtimes','node:'.video_binary_path('video-node'),'--skip-download','--dump-single-json','-f',$format,
      '--',$lesson['download_source']['url']];
    $result=pd_run($args);
    if ($result['code']!==0) {
      $error=strtolower($result['stderr']);
      if (str_contains($error,'timed out') || str_contains($error,'network is unreachable')) {
        $name=['youtube'=>'YouTube','rutube'=>'Rutube','vk'=>'VK'][$lesson['download_source']['provider']];
        throw new PlatformDownloadError($name.' сейчас не отвечает серверу сайта. Попробуй позже.',504);
      }
      if (str_contains($error,'sign in') || str_contains($error,'login') || str_contains($error,'not a bot') || str_contains($error,'private video')) {
        throw new PlatformDownloadError('Платформа требует дополнительный вход или проверку. Сейчас скачать эту запись не получилось.');
      }
      if (str_contains($error,'not available') || str_contains($error,'unavailable') || str_contains($error,'not found')) {
        throw new PlatformDownloadError('Эта запись сейчас недоступна для скачивания на платформе.');
      }
      error_log('[platform download] resolve failed provider='.$lesson['download_source']['provider'].' lesson='.(int)$lesson['id'].' code='.$result['code']);
      throw new PlatformDownloadError('Не удалось получить запись с платформы. Попробуй позже.');
    }
    $info=json_decode(trim($result['stdout']),true);
    if (!is_array($info) || isset($info['entries'])) throw new PlatformDownloadError('Нужна ссылка на отдельную запись');
    if (!empty($info['has_drm'])) throw new PlatformDownloadError('Эта запись защищена от скачивания');
    if (!empty($info['is_live']) || in_array($info['live_status'] ?? '',['is_live','is_upcoming','post_live'],true)) throw new PlatformDownloadError('Эфир ещё не завершён или запись пока обрабатывается');
    if ((float)($info['duration'] ?? 0)>8*3600) throw new PlatformDownloadError('Скачивание записей длиннее восьми часов пока не поддерживается');
    $formats=$info['requested_formats'] ?? [$info];
    if (count($formats)>2) throw new PlatformDownloadError('Формат этой записи пока не поддерживается');
    $streams=[];$bytes=0;$height=0;
    foreach ($formats as $format) {
      $url=(string)($format['url'] ?? '');
      if (!pd_public_remote($url)) throw new PlatformDownloadError('Не удалось получить безопасную ссылку на запись');
      $headers=[];
      foreach (array_merge($info['http_headers'] ?? [],$format['http_headers'] ?? []) as $name=>$value) {
        if (!in_array(strtolower((string)$name),['user-agent','referer','origin','cookie','accept'],true)) continue;
        if (!is_string($value) || strlen($value)>8192 || preg_match('/[\r\n\x00]/',$value)) continue;
        $headers[$name]=$value;
      }
      $streams[]=['url'=>$url,'headers'=>$headers];
      $bytes+=(int)($format['filesize'] ?? $format['filesize_approx'] ?? 0);
      $height=max($height,(int)($format['height'] ?? 0));
    }
    $meta=['source_hash'=>$lesson['source_hash'],'provider'=>$lesson['download_source']['provider'],'streams'=>$streams,
      'height'=>$height,'duration_sec'=>(int)($info['duration'] ?? 0),'estimated_bytes'=>$bytes,'created_at'=>time()];
    $temp=pd_metadata_path($lesson).'.'.bin2hex(random_bytes(4));
    if (file_put_contents($temp,json_encode($meta,JSON_UNESCAPED_SLASHES))===false) throw new PlatformDownloadError('Не удалось подготовить скачивание',503);
    chmod($temp,0600);rename($temp,pd_metadata_path($lesson));
    // Хранятся только короткие метаданные; полных копий видео на диске нет.
    foreach (glob(pd_root().'/*.json') ?: [] as $path) if (time()-(int)filemtime($path)>1800) @unlink($path);
    return $meta;
  } finally { fclose($slot); }
}

function pd_signature(int $uid, array $lesson, int $expires): string {
  $secret=(string)(prod_secrets()['migrate_key'] ?? '');
  if (strlen($secret)<16) throw new PlatformDownloadError('Сервис скачивания не настроен',503);
  return hash_hmac('sha256','platform-download:'.$uid.':'.$lesson['id'].':'.$lesson['source_hash'].':'.$expires,$secret);
}

function pd_ffmpeg_args(array $meta, ?int $sampleSeconds=null): array {
  $args=[video_binary_path('ffmpeg'),'-nostdin','-hide_banner','-loglevel','error'];
  $ca=pd_ca_file();
  foreach ($meta['streams'] as $stream) {
    if (!pd_public_remote($stream['url'])) throw new PlatformDownloadError('Ссылка на запись больше недоступна',410);
    array_push($args,'-protocol_whitelist','https,http,tcp,tls,crypto','-rw_timeout','20000000','-tls_verify','1','-ca_file',$ca);
    if (!empty($stream['headers'])) {
      $headers='';foreach ($stream['headers'] as $name=>$value) $headers.=$name.': '.$value."\r\n";
      array_push($args,'-headers',$headers);
    }
    array_push($args,'-i',$stream['url']);
  }
  array_push($args,'-map','0:v:0','-map',count($meta['streams'])===2 ? '1:a:0' : '0:a:0?');
  if ($sampleSeconds!==null) array_push($args,'-t',(string)$sampleSeconds);
  array_push($args,'-c','copy','-bsf:a','aac_adtstoasc','-movflags','+frag_keyframe+empty_moov+default_base_moof','-f','mp4','pipe:1');
  return $args;
}
