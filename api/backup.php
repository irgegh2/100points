<?php
// Резервная копия БД. На проде кладёт дамп за пределами /www, чтобы nginx
// физически не мог отдать его по публичному URL. Локально — в api/data/backups/.
// Запуск:
//   • CLI / cron на сервере:   php api/backup.php
//   • из браузера (по cron-сервису reg.ru): https://100-points.ru/api/backup.php?key=<migrate_key>
// Доступ как у migrate.php: CLI / встроенный php -S / админ-сессия / корректный ?key=.
// Хранит последние 14 копий (старые удаляет). Для MySQL — чистый PHP-дамп .sql.gz, для SQLite — копия файла.
require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';

$isCli = (php_sapi_name() === 'cli');
$isLocalWeb = (php_sapi_name() === 'cli-server');
if (!$isCli) {
  header('Content-Type: text/plain; charset=utf-8');
  start_session();
  $allowed = $isLocalWeb || !empty($_SESSION['admin_id']);
  if (!$allowed) {
    $key = (string)(prod_secrets()['migrate_key'] ?? '');
    $valid = strlen($key) >= 16 && $key !== 'change-me-to-a-long-random-string';
    $allowed = $valid && hash_equals($key, (string)($_GET['key'] ?? ''));
  }
  if (!$allowed) {
    http_response_code(403);
    echo "Доступ запрещён. Запусти из CLI: php api/backup.php (или ?key=<migrate_key>)\n";
    exit;
  }
}

function bout($m) { echo $m . "\n"; if (ob_get_level() === 0) @flush(); }

$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$dir = backups_root();
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { bout('Не удалось создать каталог бэкапов'); exit(1); }
$ts = date('Ymd-His');

if ($driver === 'sqlite') {
  $src = __DIR__ . '/data/app.sqlite';
  if (!is_file($src)) { bout('Локальная БД не найдена'); exit(1); }
  $dest = "$dir/backup-$ts.sqlite.gz";
  file_put_contents($dest, gzencode(file_get_contents($src), 6));
} else {
  // MySQL: переносимый дамп средствами PHP (без exec/mysqldump, которых нет на shared-хостинге).
  $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  $sql = "-- backup $ts (" . count($tables) . " таблиц)\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
  foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
    $sql .= "\nDROP TABLE IF EXISTS `$t`;\n" . ($create['Create Table'] ?? '') . ";\n";
    $rows = $pdo->query("SELECT * FROM `$t`");
    foreach ($rows as $row) {
      $cols = '`' . implode('`,`', array_keys($row)) . '`';
      $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
      $sql .= "INSERT INTO `$t` ($cols) VALUES ($vals);\n";
    }
  }
  $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
  $dest = "$dir/backup-$ts.sql.gz";
  file_put_contents($dest, gzencode($sql, 6));
}

// Ротация: оставляем последние 14 (имя содержит timestamp → сортировка по имени = по времени).
$KEEP = 14;
$files = glob("$dir/backup-*");
sort($files);
$removed = 0;
while (count($files) > $KEEP) { @unlink(array_shift($files)); $removed++; }

bout('Бэкап готов: ' . basename($dest) . ' (' . number_format(filesize($dest) / 1024, 1) . ' КБ)');
bout("Храню последние $KEEP копий; удалено старых: $removed; всего сейчас: " . count(glob("$dir/backup-*")));
