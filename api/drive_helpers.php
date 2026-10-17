<?php
// Общая логика «Стобалльного диска». Лимит не хранится целиком в БД: он плавно
// растёт вместе с учебной активностью, а в drive_quotas лежит только купленная часть.

const DRIVE_BASE_BYTES = 104857600;       // 100 МБ сразу
const DRIVE_PER_HOMEWORK_BYTES = 26214400; // +25 МБ за каждое сданное ДЗ
const DRIVE_PURCHASE_BYTES = 1073741824;    // товар магазина: +1 ГБ

function drive_homework_count(int $uid): int {
  $st = db()->prepare("SELECT COUNT(DISTINCT homework_id) FROM submissions
                       WHERE user_id = ? AND homework_id IS NOT NULL");
  $st->execute([$uid]);
  return (int)$st->fetchColumn();
}

function drive_bonus_bytes(int $uid): int {
  $st = db()->prepare("SELECT bonus_bytes FROM drive_quotas WHERE user_id = ? LIMIT 1");
  $st->execute([$uid]);
  return max(0, (int)($st->fetchColumn() ?: 0));
}

function drive_used_bytes(int $uid): int {
  $st = db()->prepare("SELECT COALESCE(SUM(size_bytes), 0) FROM drive_items WHERE user_id = ? AND kind = 'file'");
  $st->execute([$uid]);
  return max(0, (int)$st->fetchColumn());
}

function drive_quota(int $uid): array {
  $homeworks = drive_homework_count($uid);
  $organic = DRIVE_BASE_BYTES + ($homeworks * DRIVE_PER_HOMEWORK_BYTES);
  $bonus = drive_bonus_bytes($uid);
  $used = drive_used_bytes($uid);
  $total = $organic + $bonus;
  return [
    'used_bytes' => $used,
    'total_bytes' => $total,
    'free_bytes' => max(0, $total - $used),
    'organic_bytes' => $organic,
    'bonus_bytes' => $bonus,
    'homework_count' => $homeworks,
    'per_homework_bytes' => DRIVE_PER_HOMEWORK_BYTES,
    'organic_cap_bytes' => null,
    'max_file_bytes' => null,
  ];
}

// Рекурсивный вес виртуальных папок. Файлы физически лежат по непрозрачным
// ключам S3, поэтому сумму дерева считаем по метаданным БД без запросов в облако.
function drive_folder_sizes(int $uid): array {
  $st = db()->prepare("SELECT id, parent_id, kind, size_bytes FROM drive_items
                       WHERE user_id = ? AND (upload_status IS NULL OR upload_status = 'ready')");
  $st->execute([$uid]);
  $rows = $st->fetchAll();
  $parentById = [];
  $sizes = [];
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $parentById[$id] = (int)($row['parent_id'] ?? 0);
    if (($row['kind'] ?? '') === 'folder') $sizes[$id] = 0;
  }
  foreach ($rows as $row) {
    if (($row['kind'] ?? '') !== 'file') continue;
    $size = max(0, (int)($row['size_bytes'] ?? 0));
    $parent = (int)($row['parent_id'] ?? 0);
    $seen = [];
    while ($parent > 0 && !isset($seen[$parent])) {
      $seen[$parent] = true;
      $sizes[$parent] = ($sizes[$parent] ?? 0) + $size;
      $parent = (int)($parentById[$parent] ?? 0);
    }
  }
  return $sizes;
}

function drive_item(int $uid, int $id, ?string $kind = null): ?array {
  $sql = "SELECT * FROM drive_items WHERE id = ? AND user_id = ?" . ($kind ? " AND kind = ?" : '') . " LIMIT 1";
  $st = db()->prepare($sql);
  $args = [$id, $uid];
  if ($kind) $args[] = $kind;
  $st->execute($args);
  $row = $st->fetch();
  return $row ?: null;
}

function drive_require_parent(int $uid, int $parentId): ?array {
  if ($parentId <= 0) return null;
  $parent = drive_item($uid, $parentId, 'folder');
  if (!$parent) fail('Папка не найдена', 404);
  return $parent;
}

function drive_clean_name(string $name, int $max = 240): string {
  $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
  $name = trim(str_replace(['/', '\\'], ' ', $name));
  $name = (string)preg_replace('/\s+/u', ' ', $name);
  if ($name === '' || $name === '.' || $name === '..') fail('Введи название', 400);
  return mb_substr($name, 0, $max);
}

function drive_public_item(array $row): array {
  $isFile = ($row['kind'] ?? '') === 'file';
  return [
    'id' => (int)$row['id'],
    'parent_id' => !empty($row['parent_id']) ? (int)$row['parent_id'] : null,
    'kind' => $isFile ? 'file' : 'folder',
    'name' => (string)$row['name'],
    'mime' => $isFile ? (string)($row['mime'] ?? '') : '',
    'size_bytes' => $isFile ? (int)($row['size_bytes'] ?? 0) : 0,
    'url' => $isFile && !empty($row['storage_key']) ? storage_upload_url((string)$row['storage_key']) : null,
    'created_at' => $row['created_at'] ?? null,
    'updated_at' => $row['updated_at'] ?? null,
  ];
}

function drive_breadcrumbs(int $uid, int $parentId): array {
  $trail = [];
  $seen = [];
  $current = $parentId;
  while ($current > 0 && count($trail) < 60) {
    if (isset($seen[$current])) fail('Повреждена структура папок', 409);
    $seen[$current] = true;
    $folder = drive_item($uid, $current, 'folder');
    if (!$folder) fail('Папка не найдена', 404);
    array_unshift($trail, ['id' => (int)$folder['id'], 'name' => (string)$folder['name']]);
    $current = (int)($folder['parent_id'] ?? 0);
  }
  array_unshift($trail, ['id' => 0, 'name' => 'Мой диск']);
  return $trail;
}

function drive_allowed_extension(string $name): bool {
  $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === '') return true;
  $blocked = ['php','php3','php4','php5','phtml','phar','cgi','pl','py','sh','bash','zsh','bat','cmd','com','exe','msi','dmg','pkg','app','html','htm','js','mjs','svg','htaccess'];
  return !in_array($ext, $blocked, true);
}

function drive_collect_tree(int $uid, int $rootId): array {
  $root = drive_item($uid, $rootId);
  if (!$root) fail('Файл или папка не найдены', 404);
  $items = [$root];
  if (($root['kind'] ?? '') !== 'folder') return $items;
  $queue = [$rootId];
  while ($queue) {
    $parent = array_shift($queue);
    $st = db()->prepare("SELECT * FROM drive_items WHERE user_id = ? AND parent_id = ? ORDER BY id");
    $st->execute([$uid, $parent]);
    foreach ($st->fetchAll() as $child) {
      $items[] = $child;
      if (($child['kind'] ?? '') === 'folder') $queue[] = (int)$child['id'];
    }
    if (count($items) > 10000) fail('В папке слишком много объектов для одного удаления', 409);
  }
  return $items;
}
