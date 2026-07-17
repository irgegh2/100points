<?php
// Подключение к БД через PDO.
// Локально (разработка) — SQLite-файл. На reg.ru — MySQL (по secrets.prod.php).
// Различаем по хосту: если домен 100-points.ru — это прод.

// Пользовательские календарные сутки, дедлайны и серии считаем по Москве.
// Временные метки БД по-прежнему храним в UTC и переводим при дневных выборках.
date_default_timezone_set('Europe/Moscow');

// Прод ли это (для ВЫБОРА БД). Признак — домен + наличие секретов.
// ВАЖНО: HTTP_HOST задаётся клиентом, поэтому is_prod() НЕЛЬЗЯ использовать
// для решений безопасности (скрытие ошибок, доступ к миграциям). Для этого
// есть dev_visible() в helpers.php (опирается на SAPI — клиент его не подделает).
// Для выбора БД спуфинг Host безвреден: атакующий лишь уведёт СВОЙ запрос на
// пустой локальный SQLite, к данным MySQL это доступа не даёт.
function is_prod() {
  // secrets.prod.php есть только на сервере (в git его нет) — достаточный признак прода.
  // Прежняя проверка по домену ломала MySQL, если хостинг не на 100-points.ru.
  return file_exists(__DIR__ . '/secrets.prod.php');
}

// Секреты прода (host/name/user/pass и опц. migrate_key). [] если файла нет.
function prod_secrets() {
  static $c = null;
  if ($c !== null) return $c;
  $c = file_exists(__DIR__ . '/secrets.prod.php') ? (require __DIR__ . '/secrets.prod.php') : [];
  return $c;
}

function is_hosting_layout(): bool {
  return in_array(basename(dirname(__DIR__)), [
    '100-points.ru', 'lk.100-points.ru', 'admin.100-points.ru'
  ], true);
}

// Общие файловые каталоги для всех трёх доменов.
// На проде API физически продублирован в 100-points.ru, lk. и admin., но файлы
// пользователей должны быть общими. Поэтому всегда пишем в корень основного домена.
function uploads_root() {
  if (is_prod() && is_hosting_layout()) return dirname(dirname(__DIR__)) . '/100-points.ru/uploads';
  return dirname(__DIR__) . '/uploads';
}

function uploads_url(string $path = ''): string {
  $base = is_prod() ? 'https://100-points.ru/uploads' : '/uploads';
  return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

// Продовые бэкапы нельзя хранить внутри /www: nginx раздаёт статические файлы
// раньше Apache и игнорирует запреты из .htaccess. Кладём их в домашний каталог.
function backups_root() {
  if (is_prod() && is_hosting_layout()) return dirname(dirname(dirname(__DIR__))) . '/backups/100-points.ru';
  return __DIR__ . '/data/backups';
}

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;

  $opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];

  if (is_prod()) {
    $c = prod_secrets();
    $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
    // (Постоянное соединение ATTR_PERSISTENT отключено: на shared-хостинге копило коннекты и
    //  давало случайные 500 под параллельной нагрузкой. Скорость держит разблокировка сессии.)
    $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    // Фиксируем UTC, чтобы CURRENT_TIMESTAMP в MySQL совпадал по семантике
    // с datetime('now') в SQLite (иначе created_at разъедется dev↔prod).
    $pdo->exec("SET time_zone = '+00:00'");
  } else {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $dir . '/app.sqlite', null, null, $opts);
  }
  return $pdo;
}
