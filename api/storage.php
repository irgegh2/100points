<?php
// Единое хранилище пользовательских и админских загрузок.
// На проде использует приватный S3-бакет Рег.облака, локально — папку /uploads.
// Внешние библиотеки не нужны: подпись AWS Signature V4 формируется здесь.

function storage_config(): array {
  $s = prod_secrets();
  return [
    'endpoint' => rtrim((string)($s['s3_endpoint'] ?? ''), '/'),
    'bucket' => trim((string)($s['s3_bucket'] ?? '')),
    'access_key' => trim((string)($s['s3_access_key'] ?? '')),
    'secret_key' => (string)($s['s3_secret_key'] ?? ''),
    'region' => trim((string)($s['s3_region'] ?? 'us-east-1')) ?: 'us-east-1',
  ];
}

function storage_enabled(): bool {
  $c = storage_config();
  return $c['endpoint'] !== '' && $c['bucket'] !== ''
      && $c['access_key'] !== '' && $c['secret_key'] !== '';
}

function storage_normalize_key(string $key): string {
  $key = str_replace('\\', '/', trim($key));
  $key = ltrim($key, '/');
  if ($key === '' || str_contains($key, "\0")) throw new InvalidArgumentException('Пустой путь файла');
  $parts = [];
  foreach (explode('/', $key) as $part) {
    if ($part === '' || $part === '.') continue;
    if ($part === '..') throw new InvalidArgumentException('Недопустимый путь файла');
    $parts[] = $part;
  }
  if (!$parts) throw new InvalidArgumentException('Пустой путь файла');
  return implode('/', $parts);
}

function storage_encoded_path(string $key, bool $withBucket = true): string {
  $c = storage_config();
  if ($key === '') return $withBucket ? '/' . rawurlencode($c['bucket']) : '/';
  $parts = array_map('rawurlencode', explode('/', storage_normalize_key($key)));
  $path = implode('/', $parts);
  return '/' . ($withBucket ? rawurlencode($c['bucket']) . '/' : '') . $path;
}

function storage_signing_key(string $secret, string $date, string $region): string {
  $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
  $kRegion = hash_hmac('sha256', $region, $kDate, true);
  $kService = hash_hmac('sha256', 's3', $kRegion, true);
  return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function storage_request(string $method, string $key, string $body = '', array $query = [], array $headers = []): array {
  if (!storage_enabled()) throw new RuntimeException('S3 не настроен');
  if (!function_exists('curl_init')) throw new RuntimeException('На сервере недоступен PHP cURL');

  $c = storage_config();
  $host = (string)parse_url($c['endpoint'], PHP_URL_HOST);
  $now = gmdate('Ymd\\THis\\Z');
  $date = substr($now, 0, 8);
  $payloadHash = hash('sha256', $body);
  $canonicalUri = storage_encoded_path($key);

  $queryPairs = [];
  foreach ($query as $name => $value) {
    $queryPairs[rawurlencode((string)$name)] = rawurlencode((string)$value);
  }
  ksort($queryPairs, SORT_STRING);
  $canonicalQuery = implode('&', array_map(
    fn($name, $value) => $name . '=' . $value,
    array_keys($queryPairs), array_values($queryPairs)
  ));

  $signed = [
    'host' => $host,
    'x-amz-content-sha256' => $payloadHash,
    'x-amz-date' => $now,
  ];
  foreach ($headers as $name => $value) $signed[strtolower(trim((string)$name))] = trim((string)$value);
  ksort($signed, SORT_STRING);
  $canonicalHeaders = '';
  foreach ($signed as $name => $value) $canonicalHeaders .= $name . ':' . preg_replace('/\\s+/', ' ', $value) . "\n";
  $signedHeaders = implode(';', array_keys($signed));
  $canonicalRequest = strtoupper($method) . "\n" . $canonicalUri . "\n" . $canonicalQuery . "\n"
    . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
  $scope = $date . '/' . $c['region'] . '/s3/aws4_request';
  $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
  $signature = hash_hmac('sha256', $stringToSign, storage_signing_key($c['secret_key'], $date, $c['region']));
  $authorization = 'AWS4-HMAC-SHA256 Credential=' . $c['access_key'] . '/' . $scope
    . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

  $url = $c['endpoint'] . $canonicalUri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
  $httpHeaders = ['Authorization: ' . $authorization];
  foreach ($signed as $name => $value) {
    if ($name !== 'host') $httpHeaders[] = $name . ': ' . $value;
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => strtoupper($method),
    CURLOPT_HTTPHEADER => $httpHeaders,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 120,
  ]);
  if ($method !== 'GET' && $method !== 'HEAD') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
  $response = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $contentLength = (int)round((float)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD));
  if ($response === false || $status < 200 || $status >= 300) {
    error_log('[s3] ' . $method . ' ' . $key . ' failed: HTTP ' . $status . ' ' . $error . ' ' . substr((string)$response, 0, 500));
    throw new RuntimeException('Не удалось сохранить файл в облачном хранилище');
  }
  return ['status' => $status, 'body' => (string)$response, 'content_length' => max(0, $contentLength)];
}

function storage_put_file(string $source, string $key, string $mime = 'application/octet-stream'): void {
  // Большие файлы нельзя читать целиком в память: при 500 МБ PHP упирался бы
  // в memory_limit ещё до отправки. Хэш и тело считаем/передаём потоково.
  if (!storage_enabled()) throw new RuntimeException('S3 не настроен');
  if (!is_file($source) || !is_readable($source)) throw new RuntimeException('Не удалось прочитать загруженный файл');
  if (!function_exists('curl_init')) throw new RuntimeException('На сервере недоступен PHP cURL');

  $key = storage_normalize_key($key);
  $size = filesize($source);
  $payloadHash = hash_file('sha256', $source);
  if ($size === false || $payloadHash === false) throw new RuntimeException('Не удалось прочитать загруженный файл');

  $c = storage_config();
  $host = (string)parse_url($c['endpoint'], PHP_URL_HOST);
  $now = gmdate('Ymd\\THis\\Z');
  $date = substr($now, 0, 8);
  $canonicalUri = storage_encoded_path($key);
  $signed = [
    'content-type' => $mime,
    'host' => $host,
    'x-amz-content-sha256' => $payloadHash,
    'x-amz-date' => $now,
  ];
  ksort($signed, SORT_STRING);
  $canonicalHeaders = '';
  foreach ($signed as $name => $value) $canonicalHeaders .= $name . ':' . preg_replace('/\\s+/', ' ', $value) . "\n";
  $signedHeaders = implode(';', array_keys($signed));
  $canonicalRequest = "PUT\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
  $scope = $date . '/' . $c['region'] . '/s3/aws4_request';
  $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
  $signature = hash_hmac('sha256', $stringToSign, storage_signing_key($c['secret_key'], $date, $c['region']));
  $authorization = 'AWS4-HMAC-SHA256 Credential=' . $c['access_key'] . '/' . $scope
    . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

  $fp = fopen($source, 'rb');
  if (!$fp) throw new RuntimeException('Не удалось открыть загруженный файл');
  $ch = curl_init($c['endpoint'] . $canonicalUri);
  curl_setopt_array($ch, [
    CURLOPT_UPLOAD => true,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_INFILE => $fp,
    CURLOPT_INFILESIZE => $size,
    CURLOPT_HTTPHEADER => [
      'Authorization: ' . $authorization,
      'Content-Type: ' . $mime,
      'x-amz-content-sha256: ' . $payloadHash,
      'x-amz-date: ' . $now,
      'Expect:',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 0,
  ]);
  $response = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  fclose($fp);
  if ($response === false || $status < 200 || $status >= 300) {
    error_log('[s3 stream] PUT ' . $key . ' failed: HTTP ' . $status . ' ' . $error . ' ' . substr((string)$response, 0, 500));
    throw new RuntimeException('Не удалось сохранить файл в облачном хранилище');
  }
}

function storage_delete_key(string $key): void {
  storage_request('DELETE', storage_normalize_key($key));
}

// Удаляет все объекты внутри конкретного префикса. Нужен для составных файлов
// вроде HLS-видео, где один ролик состоит из плейлистов и множества сегментов.
function storage_delete_prefix(string $prefix): int {
  $prefix = rtrim(storage_normalize_key($prefix), '/') . '/';
  if (!storage_enabled()) return 0;
  $deleted = 0;
  $token = '';
  do {
    $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
    if ($token !== '') $query['continuation-token'] = $token;
    $result = storage_request('GET', '', '', $query);
    $xml = (string)($result['body'] ?? '');
    preg_match_all('/<Key>(.*?)<\/Key>/s', $xml, $matches);
    foreach ($matches[1] ?? [] as $encodedKey) {
      $key = html_entity_decode(strip_tags((string)$encodedKey), ENT_QUOTES | ENT_XML1, 'UTF-8');
      if ($key === '' || !str_starts_with($key, $prefix)) continue;
      storage_delete_key($key);
      $deleted++;
    }
    $truncated = preg_match('/<IsTruncated>\s*true\s*<\/IsTruncated>/i', $xml) === 1;
    $token = '';
    if ($truncated && preg_match('/<NextContinuationToken>(.*?)<\/NextContinuationToken>/s', $xml, $match)) {
      $token = html_entity_decode(strip_tags((string)$match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
  } while ($truncated && $token !== '');
  return $deleted;
}

function storage_presigned_get(string $key, int $expires = 300, ?string $downloadName = null): string {
  if (!storage_enabled()) throw new RuntimeException('S3 не настроен');
  $c = storage_config();
  $host = (string)parse_url($c['endpoint'], PHP_URL_HOST);
  $now = gmdate('Ymd\\THis\\Z');
  $date = substr($now, 0, 8);
  $scope = $date . '/' . $c['region'] . '/s3/aws4_request';
  $query = [
    'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
    'X-Amz-Credential' => $c['access_key'] . '/' . $scope,
    'X-Amz-Date' => $now,
    'X-Amz-Expires' => (string)max(1, min(21600, $expires)),
    'X-Amz-SignedHeaders' => 'host',
  ];
  if ($downloadName !== null && trim($downloadName) !== '') {
    $safe = str_replace(["\r", "\n", '"'], ['', '', "'"], trim($downloadName));
    $query['response-content-disposition'] = "attachment; filename*=UTF-8''" . rawurlencode($safe);
  }
  $pairs = [];
  foreach ($query as $name => $value) $pairs[rawurlencode($name)] = rawurlencode($value);
  ksort($pairs, SORT_STRING);
  $canonicalQuery = implode('&', array_map(
    fn($name, $value) => $name . '=' . $value,
    array_keys($pairs), array_values($pairs)
  ));
  $canonicalUri = storage_encoded_path($key);
  $canonicalRequest = "GET\n" . $canonicalUri . "\n" . $canonicalQuery . "\nhost:" . $host
    . "\n\nhost\nUNSIGNED-PAYLOAD";
  $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
  $signature = hash_hmac('sha256', $stringToSign, storage_signing_key($c['secret_key'], $date, $c['region']));
  return $c['endpoint'] . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
}

// Браузер грузит крупные файлы непосредственно в приватный S3, минуя лимиты
// тела запроса shared-хостинга. Доступ действует только для одного случайного ключа.
function storage_presigned_put(string $key, int $expires = 21600): string {
  if (!storage_enabled()) throw new RuntimeException('S3 не настроен');
  $c = storage_config();
  $host = (string)parse_url($c['endpoint'], PHP_URL_HOST);
  $now = gmdate('Ymd\THis\Z');
  $date = substr($now, 0, 8);
  $scope = $date . '/' . $c['region'] . '/s3/aws4_request';
  $query = [
    'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
    'X-Amz-Credential' => $c['access_key'] . '/' . $scope,
    'X-Amz-Date' => $now,
    'X-Amz-Expires' => (string)max(60, min(604800, $expires)),
    'X-Amz-SignedHeaders' => 'host',
  ];
  $pairs = [];
  foreach ($query as $name => $value) $pairs[rawurlencode($name)] = rawurlencode($value);
  ksort($pairs, SORT_STRING);
  $canonicalQuery = implode('&', array_map(
    fn($name, $value) => $name . '=' . $value,
    array_keys($pairs), array_values($pairs)
  ));
  $canonicalUri = storage_encoded_path(storage_normalize_key($key));
  $canonicalRequest = "PUT\n" . $canonicalUri . "\n" . $canonicalQuery . "\nhost:" . $host
    . "\n\nhost\nUNSIGNED-PAYLOAD";
  $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
  $signature = hash_hmac('sha256', $stringToSign, storage_signing_key($c['secret_key'], $date, $c['region']));
  return $c['endpoint'] . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
}

function storage_head_key(string $key): int {
  $result = storage_request('HEAD', storage_normalize_key($key));
  return (int)($result['content_length'] ?? 0);
}

function storage_enable_browser_uploads(): void {
  $xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
    . '<CORSRule>'
    . '<AllowedOrigin>https://lk.100-points.ru</AllowedOrigin>'
    . '<AllowedOrigin>https://admin.100-points.ru</AllowedOrigin>'
    . '<AllowedOrigin>https://100-points.ru</AllowedOrigin>'
    . '<AllowedMethod>PUT</AllowedMethod><AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod>'
    . '<AllowedHeader>*</AllowedHeader><ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>3600</MaxAgeSeconds>'
    . '</CORSRule></CORSConfiguration>';
  storage_request('PUT', '', $xml, ['cors' => ''], ['content-type' => 'application/xml']);
}

function storage_enable_versioning(): void {
  $xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
    . '<Status>Enabled</Status></VersioningConfiguration>';
  storage_request('PUT', '', $xml, ['versioning' => ''], ['content-type' => 'application/xml']);
}

function storage_upload_url(string $key): string {
  $key = storage_normalize_key($key);
  if (storage_enabled()) return '/api/file.php?path=' . rawurlencode($key);
  return uploads_url($key);
}

function storage_save_uploaded_file(string $tmp, string $key, string $mime): void {
  $key = storage_normalize_key($key);
  if (storage_enabled()) storage_put_file($tmp, $key, $mime);

  // Крупные файлы уже надёжно лежат в S3. Не дублируем их на 13-ГБ диске
  // хостинга, иначе несколько видео/архивов быстро забьют сайт. Временный файл
  // PHP будет удалён автоматически после завершения запроса.
  $localMirrorLimit = 25 * 1024 * 1024;
  if (storage_enabled() && (filesize($tmp) ?: 0) > $localMirrorLimit) return;

  // Пока сохраняем вторую копию на текущем хостинге для безопасного отката.
  $dest = uploads_root() . '/' . $key;
  $dir = dirname($dest);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    if (!storage_enabled()) throw new RuntimeException('Не удалось создать каталог загрузок');
    error_log('[storage] S3 saved, local mirror directory failed: ' . $dir);
    return;
  }
  if (!move_uploaded_file($tmp, $dest)) {
    if (!storage_enabled()) throw new RuntimeException('Не удалось сохранить файл');
    error_log('[storage] S3 saved, local mirror write failed: ' . $dest);
    return;
  }
  @chmod($dest, 0644);
}

function storage_key_from_url(string $url): ?string {
  $query = parse_url($url, PHP_URL_QUERY);
  if ($query) {
    parse_str($query, $params);
    if (!empty($params['path'])) return storage_normalize_key((string)$params['path']);
  }
  $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
  $prefix = '/uploads/';
  $pos = strpos($path, $prefix);
  if ($pos === false) return null;
  return storage_normalize_key(substr($path, $pos + strlen($prefix)));
}

function storage_delete_url(string $url): void {
  $key = storage_key_from_url($url);
  if ($key === null) return;
  if (storage_enabled()) {
    try { storage_delete_key($key); } catch (Throwable $e) { error_log('[storage delete] ' . $e->getMessage()); }
  }
  $local = uploads_root() . '/' . $key;
  if (is_file($local)) @unlink($local);
}
