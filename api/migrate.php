<?php
// Единый версионный мигратор БД. Один скрипт на все таблицы проекта.
// Заменяет ручные init-скрипты: новые таблицы добавляются новой версией в $MIGRATIONS.
//
// Запуск локально (SQLite):   php api/migrate.php
// Запуск на проде (MySQL):    из CLI по SSH  ИЛИ  https://100-points.ru/api/migrate.php?key=…
//                             (key берётся из secrets.prod.php → 'migrate_key')
//
// По умолчанию также сидит стартовые данные при пустых таблицах (см. --no-seed).
require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';

$isCli = (php_sapi_name() === 'cli');
$isLocalWeb = (php_sapi_name() === 'cli-server'); // встроенный `php -S` — только локалка

// ── Доступ ──────────────────────────────────────────────────
// Решение о доступе НЕ зависит от is_prod() (Host подделываем). Опираемся на SAPI:
//   • CLI (php api/migrate.php) — всегда можно (локально и по SSH на сервере);
//   • встроенный php -S — локалка, свободно;
//   • реальный веб-сервер — только админ-сессия ИЛИ корректный ?key=.
// Плейсхолдерный/короткий migrate_key трактуем как «ключа нет» → веб-запуск закрыт.
if (!$isCli) {
  header('Content-Type: text/plain; charset=utf-8');
  start_session();
  $allowed = $isLocalWeb || !empty($_SESSION['admin_id']);
  if (!$allowed) {
    $key = (string)(prod_secrets()['migrate_key'] ?? '');
    $valid_key = strlen($key) >= 16 && $key !== 'change-me-to-a-long-random-string';
    $allowed = $valid_key && hash_equals($key, (string)($_GET['key'] ?? ''));
  }
  if (!$allowed) {
    http_response_code(403);
    echo "Доступ запрещён. Запусти из CLI: php api/migrate.php\n";
    echo "(веб-запуск — только под админ-сессией или с корректным ?key=, не плейсхолдером)\n";
    exit;
  }
}

function out($msg) {
  echo $msg . "\n";
  if (ob_get_level() === 0) @flush();
}

// ── Портируемый DDL: одни и те же CREATE для SQLite и MySQL ──
// Токены подменяются под драйвер. Типы VARCHAR/TEXT/INT понимают обе СУБД,
// для JSON используем TEXT (переносимо). Внешние ключи описываем явно.
function ddl(string $sql, string $driver): string {
  if ($driver === 'sqlite') {
    $repl = [
      '{PK}'     => 'INTEGER PRIMARY KEY AUTOINCREMENT',
      '{TS}'     => "TEXT DEFAULT (datetime('now'))",
      '{ENGINE}' => '',
    ];
  } else {
    $repl = [
      '{PK}'     => 'INT AUTO_INCREMENT PRIMARY KEY',
      '{TS}'     => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
      '{ENGINE}' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];
  }
  return strtr($sql, $repl);
}

// Создание ДЗ «Что такое ЕГЭ по русскому? Блоки, баллы, инструкции» (урок 1, 2-й блок русского).
// Умный поиск урока: по узнаваемым фрагментам названия в ЛЮБОМ курсе (русский предмет в приоритете),
// без хрупкой привязки к названию курса. Идемпотентно по паре (lesson_id, title). Ничего не бросает —
// возвращает диагностику, которую печатает миграция v58.
function ru_ege_intro_seed(PDO $pdo): array {
  $title = 'Что такое ЕГЭ по русскому? Блоки, баллы, инструкции';

  $lessonSt = $pdo->query(
    "SELECT l.id, l.title AS lesson_title, c.title AS course_title, s.name AS subject_name
     FROM lessons l
     JOIN blocks b ON b.id = l.block_id
     JOIN courses c ON c.id = b.course_id
     JOIN subjects s ON s.id = c.subject_id
     WHERE l.title LIKE '%локи, балл%'
        OR l.title LIKE '%то такое ЕГЭ по русск%'
        OR l.title LIKE '%то такое ЕГЭ%'
     ORDER BY (s.name = 'Русский язык') DESC,
              (l.title LIKE '%локи, балл%') DESC,
              (l.title LIKE '%то такое ЕГЭ по русск%') DESC,
              l.id
     LIMIT 1"
  );
  $lesson = $lessonSt ? $lessonSt->fetch() : false;

  if (!$lesson) {
    // Не нашли — вернём список русских уроков, чтобы в логе были видны реальные названия.
    $candidates = $pdo->query(
      "SELECT c.title AS course, b.n AS block_n, l.title AS lesson
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name LIKE '%усск%' OR c.title LIKE '%усск%' OR c.title LIKE '%Летн%'
       ORDER BY c.title, b.position, l.position, l.id"
    )->fetchAll();
    return ['found' => false, 'created' => false, 'candidates' => $candidates];
  }

  $lessonId = (int)$lesson['id'];
  $ex = $pdo->prepare("SELECT 1 FROM homeworks WHERE lesson_id = ? AND title = ? LIMIT 1");
  $ex->execute([$lessonId, $title]);
  if ($ex->fetchColumn()) return ['found' => true, 'created' => false, 'lesson' => $lesson];

  $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = $lessonId")->fetchColumn();
  $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
      ->execute([$lessonId, 'normal', $title, '', $pos]);
  $hwId = (int)$pdo->lastInsertId();

  $ask = 'Укажите цифру, под которой указан правильный ответ.';
  // [текст, варианты, индекс(ы) верного ответа, пояснение]
  $qs = [
    [
      "Из чего состоит экзамен по русскому языку? $ask",
      ['Тестовая часть, сочинение и изложение', 'Тестовая часть и два сочинения', 'Тестовая часть и сочинение'],
      [2],
      'ЕГЭ по русскому состоит из тестовой части и одного сочинения. Изложения и второго сочинения на экзамене нет.',
    ],
    [
      "Сколько всего заданий в ЕГЭ по русскому языку? $ask",
      ['15', '27', '25'],
      [1],
      'Всего в работе 27 заданий: 26 тестовых и сочинение (задание 27).',
    ],
    [
      "Где можно найти словари ударений и паронимов, список словарных слов и другую информацию, важную для ЕГЭ? $ask",
      ['В навигаторе для самостоятельной подготовки от ФИПИ', 'В демоверсии', 'На любом сайте'],
      [0],
      'Все официальные материалы (словари ударений и паронимов, списки слов) собраны в навигаторе для самостоятельной подготовки от ФИПИ.',
    ],
    [
      "Сколько слов нужно написать в сочинении, чтобы получить максимальный балл? $ask",
      ['от 70', 'от 150', 'от 350'],
      [1],
      'Чтобы работу проверяли по всем критериям и можно было получить максимум, в сочинении должно быть не менее 150 слов.',
    ],
    [
      "Можно ли написать сочинение не по тексту из КИМа? $ask",
      ['Да, можно написать по тому, который хорошо помнишь', 'Нет, сочинение нужно писать по предложенному тексту'],
      [1],
      'Сочинение пишется строго по тексту, предложенному в КИМе. Работа по другому тексту не проверяется.',
    ],
    [
      "Сколько всего критериев в сочинении ЕГЭ? $ask",
      ['5', '10', '12'],
      [1],
      'Сочинение оценивается по 10 критериям.',
    ],
    [
      "Какие задания теста являются самыми «дорогими» на ЕГЭ? $ask",
      ['1, 3, 21', '8, 22', '15, 16'],
      [1],
      'Больше всего первичных баллов приносят задания 8 и 22 — на них стоит обращать особое внимание.',
    ],
    [
      'Сочинение ЕГЭ – это … ? Укажите цифру, под которой правильно продолжена фраза.',
      ['Работа с чёткой структурой, которая соответствует критериям', 'Творческая работа'],
      [0],
      'Сочинение ЕГЭ — это не свободное творчество, а работа с чёткой структурой, которая должна соответствовать критериям проверки.',
    ],
  ];

  $qi = $pdo->prepare(
    "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
     VALUES (?,'single',?,?,?,?,NULL,1,0,0,0,'full',1,?)"
  );
  foreach ($qs as $i => [$text, $options, $correct, $solution]) {
    $qi->execute([
      $hwId, $text,
      json_encode($options, JSON_UNESCAPED_UNICODE),
      json_encode($correct, JSON_UNESCAPED_UNICODE),
      $solution, $i,
    ]);
  }

  return ['found' => true, 'created' => true, 'lesson' => $lesson, 'homework_id' => $hwId, 'count' => count($qs)];
}

// ── Реестр миграций ─────────────────────────────────────────
// Каждая версия — функция($pdo, $driver). Применяются по возрастанию,
// один раз (учёт в таблице schema_migrations). Чтобы добавить таблицы
// следующих этапов — заводи новый ключ (2, 3, …), старые НЕ меняй.
//
// ⚠ Идемпотентность обязательна. В MySQL любой DDL (CREATE/ALTER) делает
// неявный COMMIT и НЕ откатывается, поэтому при сбое между DDL и записью
// версии повторный прогон должен пройти без ошибки. Правила:
//   • только CREATE TABLE IF NOT EXISTS;
//   • ALTER (добавить столбец) — сначала проверяй наличие: на MySQL через
//     information_schema.columns, на SQLite через PRAGMA table_info;
//   • шаги с данными (INSERT/UPDATE) оборачивай в отдельную транзакцию.
$MIGRATIONS = [

  // v1 — Фундамент (Этап 0/1): аккаунты учеников и админов.
  1 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS users (
      id {PK},
      name VARCHAR(120),
      email VARCHAR(190) UNIQUE,
      phone VARCHAR(40) UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role VARCHAR(20) DEFAULT 'student',
      created_at {TS}
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS admins (
      id {PK},
      username VARCHAR(80) UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      created_at {TS}
    ) {ENGINE}", $driver));
  },

  // v2 — Профиль ученика (Этап 1, п.6). Одна строка на ученика (user_id — PK).
  2 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS profiles (
      user_id INT PRIMARY KEY,
      avatar VARCHAR(255),
      birthdate VARCHAR(40),
      goal_subject VARCHAR(80),
      target_score INT,
      hours_per_week INT,
      updated_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v3 — Цели по предметам (Этап 1, доработка): у каждого предмета свой балл и часы.
  // Одна строка на пару (ученик, предмет). Заменяет одиночные target_score/hours_per_week.
  3 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS profile_goals (
      user_id INT NOT NULL,
      subject VARCHAR(80) NOT NULL,
      target_score INT,
      hours_per_week INT,
      PRIMARY KEY (user_id, subject),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v4 — Каталог контента (Этап 2). Иерархия:
  //   subjects → courses → months → blocks → lessons → { materials, timecodes }.
  // «Месяцы» — отдельный уровень между курсом и блоком: на фронте программа курса
  // листается помесячно («‹ Октябрь ›»). В роадмапе месяца не было — добавлен здесь.
  4 => function (PDO $pdo, string $driver) {
    // Предметы: название, slug, цвет обложки, иконка (ключ из фронтового ICONS).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS subjects (
      id {PK},
      name VARCHAR(120) NOT NULL,
      slug VARCHAR(120) UNIQUE,
      color VARCHAR(20),
      icon VARCHAR(40),
      position INT DEFAULT 0
    ) {ENGINE}", $driver));

    // Курсы: предмет, название, тип (вводный/основной/летняя школа/профориентация),
    // цвет, обложка, цена (₽, целое), позиция. Кол-во блоков считаем запросом.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS courses (
      id {PK},
      subject_id INT NOT NULL,
      title VARCHAR(200) NOT NULL,
      type VARCHAR(40) NOT NULL,
      color VARCHAR(20),
      cover VARCHAR(255),
      price INT,
      position INT DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    // Месяцы внутри курса: название («Октябрь»), порядок.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS months (
      id {PK},
      course_id INT NOT NULL,
      name VARCHAR(40) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (course_id) REFERENCES courses(id)
    ) {ENGINE}", $driver));

    // Блоки: принадлежат месяцу (и для удобства запросов — курсу), номер, название.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS blocks (
      id {PK},
      course_id INT NOT NULL,
      month_id INT NOT NULL,
      n INT NOT NULL,
      title VARCHAR(200) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (course_id) REFERENCES courses(id),
      FOREIGN KEY (month_id) REFERENCES months(id)
    ) {ENGINE}", $driver));

    // Уроки: блок, тип (theory/practice/webinar), название, подпись (meta),
    // id ролика Kinescope, дата эфира (готовая строка), длительность, подсказка-блокировка.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS lessons (
      id {PK},
      block_id INT NOT NULL,
      type VARCHAR(20) NOT NULL DEFAULT 'theory',
      title VARCHAR(200) NOT NULL,
      meta VARCHAR(120),
      kinescope_id VARCHAR(80),
      air VARCHAR(120),
      duration VARCHAR(40),
      locked_hint VARCHAR(120),
      position INT DEFAULT 0,
      FOREIGN KEY (block_id) REFERENCES blocks(id)
    ) {ENGINE}", $driver));

    // Материалы урока: имя, размер (готовая строка), ссылка/путь, тип (для иконки).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS materials (
      id {PK},
      lesson_id INT NOT NULL,
      name VARCHAR(200) NOT NULL,
      size VARCHAR(40),
      url VARCHAR(255),
      kind VARCHAR(40),
      position INT DEFAULT 0,
      FOREIGN KEY (lesson_id) REFERENCES lessons(id)
    ) {ENGINE}", $driver));

    // Тайм-коды урока: метка времени (строка «12:30»), подпись.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS timecodes (
      id {PK},
      lesson_id INT NOT NULL,
      t VARCHAR(20) NOT NULL,
      label VARCHAR(200) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (lesson_id) REFERENCES lessons(id)
    ) {ENGINE}", $driver));
  },

  // v5 — Доступ к контенту (Этап 3): тарифы, подписки, зачисления.
  5 => function (PDO $pdo, string $driver) {
    // Тарифы: название, слоган, цена ₽/мес, фичи (JSON-массив в TEXT), бейдж ('top'|null).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS plans (
      id {PK},
      name VARCHAR(120) NOT NULL,
      tagline VARCHAR(160),
      price INT,
      features TEXT,
      badge VARCHAR(20),
      position INT DEFAULT 0
    ) {ENGINE}", $driver));

    // Подписка ученика: тариф, статус, период (даты строкой YYYY-MM-DD).
    // На пользователя держим одну активную строку (админка перезаписывает).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS subscriptions (
      id {PK},
      user_id INT NOT NULL,
      plan_id INT,
      status VARCHAR(20) DEFAULT 'active',
      started_at VARCHAR(20),
      ends_at VARCHAR(20),
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    // Зачисление на курс: источник ('subscription'|'manual'), срок действия.
    // Уникальность (user_id, course_id) — один доступ на пару.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS enrollments (
      id {PK},
      user_id INT NOT NULL,
      course_id INT NOT NULL,
      source VARCHAR(20) DEFAULT 'manual',
      expires_at VARCHAR(20),
      created_at {TS},
      UNIQUE (user_id, course_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (course_id) REFERENCES courses(id)
    ) {ENGINE}", $driver));
  },

  // v6 — Доступ теперь строго по зачислению (подписка курсы НЕ открывает).
  // Чтобы существующие ученики не потеряли доступ, зачисляем их на все текущие
  // курсы (на проде users/courses уже есть; на свежей БД — пусто, доберём сидом).
  6 => function (PDO $pdo, string $driver) {
    enroll_all_existing($pdo);
  },

  // v7 — Тесты и домашние задания (Этап 4).
  7 => function (PDO $pdo, string $driver) {
    // ДЗ привязано к уроку. level: 'normal' (базовый) | 'medium' (средний) | 'hard' (продвинутый).
    // У урока может быть любой набор уровней; продвинутый — по подписке «Стандарт»+.
    // Повышенное ДЗ открывается только при подписке с hard_access (Стандарт+).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS homeworks (
      id {PK},
      lesson_id INT NOT NULL,
      level VARCHAR(20) NOT NULL DEFAULT 'normal',
      title VARCHAR(200) NOT NULL,
      deadline VARCHAR(80),
      position INT DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (lesson_id) REFERENCES lessons(id)
    ) {ENGINE}", $driver));

    // Вопрос ДЗ. type: single|multi|text|textarea|file. options/correct — JSON.
    //   single  → correct = индекс (число); multi → correct = массив индексов;
    //   text    → correct = массив допустимых строк; textarea → correct = null (ручная проверка).
    // optional — необязательный; hard — пометка «повышенной сложности» у вопроса.
    // case_sensitive — для text-вопросов, где важен регистр (по умолчанию 0 — регистр игнорируем).
    // homework_id ИЛИ mock_id — вопрос принадлежит ДЗ либо пробнику (Этап 6).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS questions (
      id {PK},
      homework_id INT,
      mock_id INT,
      type VARCHAR(20) NOT NULL DEFAULT 'single',
      text TEXT NOT NULL,
      options TEXT,
      correct TEXT,
      solution TEXT,
      points INT DEFAULT 1,
      optional INT DEFAULT 0,
      hard INT DEFAULT 0,
      case_sensitive INT DEFAULT 0,
      grade_mode VARCHAR(20) DEFAULT 'full',
      position INT DEFAULT 0,
      FOREIGN KEY (homework_id) REFERENCES homeworks(id)
    ) {ENGINE}", $driver));

    // Сдача ученика. status: review (есть развёрнутые — ждёт наставника) | graded.
    // Одна сдача на пару (homework, user) — пересдача перезаписывает.
    // Сдача относится к ДЗ (homework_id) ЛИБО к пробнику (mock_id). expert_id — кто проверил развёрнутые.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS submissions (
      id {PK},
      homework_id INT,
      mock_id INT,
      user_id INT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'graded',
      score INT DEFAULT 0,
      max_score INT DEFAULT 0,
      comment TEXT,
      expert_id INT,
      created_at {TS},
      graded_at VARCHAR(40),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    // «Эксперты»-проверяющие (фейковые профили для ощущения живой проверки): ФИО, вуз, балл ЕГЭ.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS experts (
      id {PK},
      last_name VARCHAR(80),
      first_name VARCHAR(80),
      university VARCHAR(160),
      ege_score INT
    ) {ENGINE}", $driver));

    // Ответ на вопрос. value — JSON (то, что выбрал/ввёл ученик).
    // is_correct: 1/0 для авто-типов, NULL для textarea до ручной проверки.
    // earned — начисленные баллы; mentor_comment — комментарий наставника (Этап 5).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS answers (
      id {PK},
      submission_id INT NOT NULL,
      question_id INT NOT NULL,
      value TEXT,
      is_correct INT,
      earned INT DEFAULT 0,
      mentor_comment TEXT,
      FOREIGN KEY (submission_id) REFERENCES submissions(id),
      FOREIGN KEY (question_id) REFERENCES questions(id)
    ) {ENGINE}", $driver));

    // Подписка открывает повышенный уровень? Колонка на plans (Стандарт+ = 1).
    if (!column_exists($pdo, $driver, 'plans', 'hard_access')) {
      $pdo->exec("ALTER TABLE plans ADD COLUMN hard_access INT DEFAULT 0");
    }
    // Существующим тарифам: Стандарт/Премиум открывают повышенный.
    $pdo->prepare("UPDATE plans SET hard_access = 1 WHERE name IN ('Стандарт','Премиум')")->execute();
  },

  // v8 — Пробники (Этап 6). Переиспользуют движок questions/submissions/answers.
  8 => function (PDO $pdo, string $driver) {
    // Пробник привязан к КУРСУ (доступ — по зачислению на курс, как у уроков/ДЗ).
    // Предмет берётся через курс. month — метка месяца («Март», «Март 2», «Март Про»).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS mock_exams (
      id {PK},
      course_id INT NOT NULL,
      month VARCHAR(40) NOT NULL,
      title VARCHAR(200) NOT NULL,
      position INT DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (course_id) REFERENCES courses(id)
    ) {ENGINE}", $driver));

    // Существующие БД (прод применил v7 со старой схемой) — добавляем mock_id и снимаем NOT NULL
    // с homework_id. На свежей БД схема уже правильная (обновлённый v7) → ветка пропускается.
    if (!column_exists($pdo, $driver, 'questions', 'mock_id')) {
      $pdo->exec("ALTER TABLE questions ADD COLUMN mock_id INT");
      if ($driver === 'mysql') $pdo->exec("ALTER TABLE questions MODIFY homework_id INT NULL");
    }
    if (!column_exists($pdo, $driver, 'submissions', 'mock_id')) {
      $pdo->exec("ALTER TABLE submissions ADD COLUMN mock_id INT");
      if ($driver === 'mysql') $pdo->exec("ALTER TABLE submissions MODIFY homework_id INT NULL");
    }
  },

  // v9 — Черновики ответов (автосохранение до отправки). Одна строка на (ученик, тип, объект).
  // kind: 'homework' | 'mock'. answers — JSON { question_id: value }. Переживает перезагрузку и
  // открытие на другом устройстве: при входе в тест ответы восстанавливаются.
  9 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS answer_drafts (
      id {PK},
      user_id INT NOT NULL,
      kind VARCHAR(20) NOT NULL,
      ref_id INT NOT NULL,
      answers TEXT,
      updated_at {TS},
      UNIQUE (user_id, kind, ref_id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v10 — Банк заданий (Этап 7). Структура как у конкурентов:
  //   subject → topics (номер задания в экзамене, % ошибок) → subtopics → questions (с тегом subtopic_id).
  //   + источники/варианты для режима «Пробник».
  10 => function (PDO $pdo, string $driver) {
    // Темы = номера заданий в экзамене. number — № задания, part — first|second (часть),
    // error_rate — процент ошибок (вводит админ, «по статистике ФИПИ»).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS topics (
      id {PK},
      subject_id INT NOT NULL,
      number INT,
      name VARCHAR(200) NOT NULL,
      part VARCHAR(20) DEFAULT 'first',
      error_rate INT,
      position INT DEFAULT 0,
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    // Подтемы внутри номера задания (Треугольники, Векторы…).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS subtopics (
      id {PK},
      topic_id INT NOT NULL,
      name VARCHAR(200) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (topic_id) REFERENCES topics(id)
    ) {ENGINE}", $driver));

    // Вопросы банка тегируются подтемой + сложностью (1–5). Колонки добавляем к общей questions.
    if (!column_exists($pdo, $driver, 'questions', 'subtopic_id')) $pdo->exec("ALTER TABLE questions ADD COLUMN subtopic_id INT");
    if (!column_exists($pdo, $driver, 'questions', 'difficulty'))  $pdo->exec("ALTER TABLE questions ADD COLUMN difficulty INT");

    // Источники вариантов (ФИПИ / Ященко / прошлые годы) и сами варианты для режима «Пробник».
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS bank_sources (
      id {PK},
      subject_id INT NOT NULL,
      name VARCHAR(160) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS bank_variants (
      id {PK},
      source_id INT NOT NULL,
      subject_id INT NOT NULL,
      name VARCHAR(200) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (source_id) REFERENCES bank_sources(id),
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    // Вариант = упорядоченный набор вопросов банка.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS variant_questions (
      id {PK},
      variant_id INT NOT NULL,
      question_id INT NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (variant_id) REFERENCES bank_variants(id),
      FOREIGN KEY (question_id) REFERENCES questions(id)
    ) {ENGINE}", $driver));
  },

  // v11 — Прогресс по банку: какие вопросы ученик уже решал (и верно ли).
  // «Решена» = есть строка (ученик отвечал). correct — был ли последний ответ верным.
  // Нужно для отметки решённых и режима «только нерешённые».
  11 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS bank_progress (
      id {PK},
      user_id INT NOT NULL,
      question_id INT NOT NULL,
      correct INT DEFAULT 0,
      updated_at {TS},
      UNIQUE (user_id, question_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (question_id) REFERENCES questions(id)
    ) {ENGINE}", $driver));
  },

  // v12 — ИИ-тренажёр (Этап 8), режим «Решение задач» + ИИ-чаты.
  12 => function (PDO $pdo, string $driver) {
    // Задачи тренажёра — заливает админ. level — сложность (1–5). answer — эталонный ответ
    // (ИИ им сверяется и объясняет ошибку). model — какая модель разбирает (простые→дешёвая).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS trainer_tasks (
      id {PK},
      subject_id INT NOT NULL,
      level INT DEFAULT 1,
      text TEXT NOT NULL,
      answer TEXT,
      solution TEXT,
      model VARCHAR(80),
      position INT DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    // Попытки: пройденную задачу не выдаём повторно. correct — вердикт ИИ (1/0), nullable.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS trainer_attempts (
      id {PK},
      user_id INT NOT NULL,
      task_id INT NOT NULL,
      correct INT,
      created_at {TS},
      UNIQUE (user_id, task_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (task_id) REFERENCES trainer_tasks(id)
    ) {ENGINE}", $driver));

    // ИИ-чаты: kind 'task' (разбор задачи) | 'free' (свободный). task_id — контекст задачи.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS ai_chats (
      id {PK},
      user_id INT NOT NULL,
      title VARCHAR(200),
      kind VARCHAR(20) DEFAULT 'free',
      task_id INT,
      model VARCHAR(80),
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    // Сообщения чата: role system|user|assistant.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS ai_messages (
      id {PK},
      chat_id INT NOT NULL,
      role VARCHAR(20) NOT NULL,
      content TEXT,
      created_at {TS},
      FOREIGN KEY (chat_id) REFERENCES ai_chats(id)
    ) {ENGINE}", $driver));
  },

  // v13 — Режим «Вопрос-ответ» тренажёра: темы-пулы, по которым ИИ ГЕНЕРИРУЕТ вопросы.
  13 => function (PDO $pdo, string $driver) {
    // Тема-пул: админ задаёт название, уровень, примеры/инструкции для ИИ и модель.
    // Из пула берётся случайная тема, ИИ генерирует по ней вопрос с вариантами.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS trainer_qa_topics (
      id {PK},
      subject_id INT NOT NULL,
      level INT DEFAULT 1,
      name VARCHAR(200) NOT NULL,
      examples TEXT,
      model VARCHAR(80),
      position INT DEFAULT 0,
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    // Сгенерированный вопрос (с правильным ответом) — хранится между генерацией и проверкой.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS trainer_qa_generated (
      id {PK},
      user_id INT NOT NULL,
      payload TEXT,
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v14 — База знаний техподдержки (для ИИ-чата «Помощь по платформе»). Админ ведёт категории и Q&A.
  // ИИ-чат типа 'platform' отвечает ученику, опираясь на эти ответы (RAG в 3 шага).
  14 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS help_categories (
      id {PK},
      name VARCHAR(200) NOT NULL,
      position INT DEFAULT 0
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS help_qa (
      id {PK},
      category_id INT NOT NULL,
      question VARCHAR(300) NOT NULL,
      answer TEXT,
      position INT DEFAULT 0,
      FOREIGN KEY (category_id) REFERENCES help_categories(id)
    ) {ENGINE}", $driver));

    // Тип subject-чата хранит предмет; для разных kind разный системный промпт (строится при создании).
    if (!column_exists($pdo, $driver, 'ai_chats', 'subject')) $pdo->exec("ALTER TABLE ai_chats ADD COLUMN subject VARCHAR(120)");
  },

  // v15 — Геймификация (Этап 9): кошелёк, журнал, достижения, штрафы за дедлайн.
  15 => function (PDO $pdo, string $driver) {
    // Баланс ученика. lives — жизни (5/нед), week_start — якорь недели, streak — серия дней.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS wallets (
      id {PK},
      user_id INT NOT NULL,
      crystals INT DEFAULT 0,
      lives INT DEFAULT 5,
      week_start VARCHAR(20),
      streak INT DEFAULT 0,
      streak_best INT DEFAULT 0,
      last_active VARCHAR(20),
      created_at {TS},
      UNIQUE (user_id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    // Журнал начислений/списаний. kind: crystals|lives. amount — со знаком.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS coin_log (
      id {PK},
      user_id INT NOT NULL,
      kind VARCHAR(20) NOT NULL,
      amount INT NOT NULL,
      reason VARCHAR(200),
      ref_type VARCHAR(40),
      ref_id INT,
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    // Каталог достижений. target — сколько нужно для открытия, reward — кристаллы за открытие.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS achievements (
      id {PK},
      code VARCHAR(60) NOT NULL,
      title VARCHAR(120) NOT NULL,
      description VARCHAR(255),
      icon VARCHAR(40),
      reward INT DEFAULT 0,
      target INT DEFAULT 1,
      position INT DEFAULT 0,
      UNIQUE (code)
    ) {ENGINE}", $driver));

    // Прогресс ученика по достижениям. unlocked_at — момент открытия (NULL пока не открыто).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS user_achievements (
      id {PK},
      user_id INT NOT NULL,
      achievement_id INT NOT NULL,
      progress INT DEFAULT 0,
      unlocked_at VARCHAR(30),
      UNIQUE (user_id, achievement_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (achievement_id) REFERENCES achievements(id)
    ) {ENGINE}", $driver));

    // Чтобы не штрафовать за один просроченный дедлайн дважды.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS deadline_penalties (
      id {PK},
      user_id INT NOT NULL,
      homework_id INT NOT NULL,
      created_at {TS},
      UNIQUE (user_id, homework_id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v16 — Галочка предмета: участвует ли в разделах-фичах (цели в профиле, ИИ-тренажёр, банк, библиотека).
  // Выкл — предмет скрыт из этих разделов (напр. «Поступление»). Доступ к курсам это НЕ затрагивает.
  16 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'subjects', 'show_features')) $pdo->exec("ALTER TABLE subjects ADD COLUMN show_features INT DEFAULT 1");
  },

  // v19 — Настоящая дата эфира у урока-вебинара (чтобы он попадал в расписание). 'YYYY-MM-DD HH:MM'.
  19 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'lessons', 'air_at')) $pdo->exec("ALTER TABLE lessons ADD COLUMN air_at VARCHAR(20)");
  },

  // v23 — Библиотека (Этап 15): материалы по предметам и категориям (файл или ссылка).
  23 => function (PDO $pdo, string $driver) {
    // subject_id NULL = общий материал (виден всем). category — Конспекты/Шпаргалки/…
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS library_items (
      id {PK},
      subject_id INT,
      category VARCHAR(60) DEFAULT 'Материалы',
      title VARCHAR(200) NOT NULL,
      url VARCHAR(400),
      size VARCHAR(30),
      kind VARCHAR(20) DEFAULT 'doc',
      position INT DEFAULT 0
    ) {ENGINE}", $driver));
  },

  // v22 — Настройки приложения (Этап 16): ключ-значение. Сейчас — режим «технические работы».
  22 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS app_settings (
      k VARCHAR(64) NOT NULL,
      v TEXT,
      PRIMARY KEY (k)
    ) {ENGINE}", $driver));
  },

  // v21 — Уведомления (Этап 13). type — категория (review/achievement/shop/mentor/event), link — раздел ЛК.
  // Непрочитанные храним вечно, прочитанные чистятся через 7 дней (лениво в notif_list).
  21 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS notifications (
      id {PK},
      user_id INT NOT NULL,
      type VARCHAR(30),
      text VARCHAR(400) NOT NULL,
      link VARCHAR(60),
      is_read INT DEFAULT 0,
      read_at VARCHAR(30),
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v20 — Онлайн-наставник (Этап 12): живой чат ученик ↔ куратор по предмету. Ответы из админки.
  20 => function (PDO $pdo, string $driver) {
    // Один чат на пару (ученик, предмет). last_at — для сортировки.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS chats (
      id {PK},
      user_id INT NOT NULL,
      subject_id INT NOT NULL,
      last_at {TS},
      created_at {TS},
      UNIQUE (user_id, subject_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ) {ENGINE}", $driver));

    // from_role: student|mentor. is_read — прочитано получателем (read — зарезервировано в MySQL).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS chat_messages (
      id {PK},
      chat_id INT NOT NULL,
      from_role VARCHAR(12) NOT NULL,
      text TEXT,
      is_read INT DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (chat_id) REFERENCES chats(id)
    ) {ENGINE}", $driver));
  },

  // v18 — Расписание (Этап 11). События недели, привязаны к курсу (гейтинг по зачислению).
  18 => function (PDO $pdo, string $driver) {
    // type: practice|webinar. starts_at — 'YYYY-MM-DD HH:MM' (строкой, сортируется хронологически).
    // link — ссылка на эфир (Kinescope live / внешняя). duration — минуты.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS events (
      id {PK},
      course_id INT NOT NULL,
      type VARCHAR(20) DEFAULT 'webinar',
      title VARCHAR(200) NOT NULL,
      starts_at VARCHAR(20),
      duration INT DEFAULT 60,
      link VARCHAR(300),
      position INT DEFAULT 0,
      FOREIGN KEY (course_id) REFERENCES courses(id)
    ) {ENGINE}", $driver));
  },

  // v17 — Магазин (Этап 10). Товары + покупки-заявки (выдача вручную через ВК).
  17 => function (PDO $pdo, string $driver) {
    // category: theme|privilege|content|reward. once_only=1 — можно купить лишь раз. badge — метка (напр. TOP).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS shop_items (
      id {PK},
      name VARCHAR(160) NOT NULL,
      description VARCHAR(255),
      category VARCHAR(30) DEFAULT 'other',
      price INT DEFAULT 0,
      once_only INT DEFAULT 0,
      badge VARCHAR(20),
      active INT DEFAULT 1,
      position INT DEFAULT 0
    ) {ENGINE}", $driver));

    // Покупка = заявка. status: pending|done|canceled. item_name/price — снимок на момент покупки.
    // note — комментарий админа / как выдали. Кристаллы списываются при покупке (транзакция).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS purchases (
      id {PK},
      user_id INT NOT NULL,
      item_id INT,
      item_name VARCHAR(160),
      price INT DEFAULT 0,
      status VARCHAR(20) DEFAULT 'pending',
      note VARCHAR(500),
      created_at {TS},
      updated_at VARCHAR(30),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v24 — Заявки (лиды лендинга, заявки на тариф, эскалация «помощь по платформе»)
  //        + прогресс уроков + вложения в чате наставника.
  24 => function (PDO $pdo, string $driver) {
    // kind: lead (форма лендинга) | subscription (запрос тарифа) | support (вопрос без ответа из ИИ-помощи).
    // status: new | done | rejected. user_id — для авторизованных заявок (NULL у анонимных лидов).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS leads (
      id {PK},
      kind VARCHAR(20) DEFAULT 'lead',
      name VARCHAR(160),
      phone VARCHAR(20),
      grade VARCHAR(40),
      user_id INT,
      plan VARCHAR(120),
      message TEXT,
      source VARCHAR(40),
      status VARCHAR(20) DEFAULT 'new',
      note VARCHAR(500),
      created_at {TS}
    ) {ENGINE}", $driver));

    // Отметка «урок пройден» учеником (для фильтра «Скрыть пройденное»).
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS lesson_progress (
      id {PK},
      user_id INT NOT NULL,
      lesson_id INT NOT NULL,
      done_at {TS},
      UNIQUE (user_id, lesson_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (lesson_id) REFERENCES lessons(id)
    ) {ENGINE}", $driver));

    // Вложение к сообщению чата наставника (относительный путь в /uploads/chat/).
    if (!column_exists($pdo, $driver, 'chat_messages', 'attachment')) {
      $pdo->exec("ALTER TABLE chat_messages ADD COLUMN attachment VARCHAR(300)");
    }
  },

  // v25 — выбранный цвет ника для купленной косметики профиля.
  25 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'profiles', 'nickname_color')) {
      $pdo->exec("ALTER TABLE profiles ADD COLUMN nickname_color VARCHAR(20)");
    }
    $pdo->beginTransaction();
    try {
      $pdo->exec("UPDATE shop_items SET description = 'Золотая академическая рамка с кристаллом' WHERE name = 'Рамка для аватара'");
      $pdo->exec("UPDATE shop_items SET description = 'Палитра цветов для имени в кабинете' WHERE name = 'Цвет ника'");
      $pdo->exec("UPDATE purchases SET status = 'done', note = 'Доступ активирован автоматически'
        WHERE status = 'pending' AND item_name IN ('Рамка для аватара', 'Цвет ника')");
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  },

  // v26 — мгновенные игровые товары, 24-часовой бустер и VIP-привилегии.
  26 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'wallets', 'booster_until')) {
      $pdo->exec("ALTER TABLE wallets ADD COLUMN booster_until VARCHAR(30)");
    }

    $pdo->beginTransaction();
    try {
      $pdo->exec("UPDATE shop_items SET description = 'Мгновенно восстанавливает одну жизнь' WHERE name = '+1 жизнь'");
      $pdo->exec("UPDATE shop_items SET description = 'Удваивает игровые начисления на 24 часа' WHERE name = 'Бустер ×2 кристалла'");
      $pdo->exec("UPDATE shop_items SET description = 'VIP-тема · +1 день к ДЗ · 7 жизней · вход +10 кристаллов' WHERE name = 'VIP-статус навсегда'");

      // Ранее оплаченные, но ожидающие цифровые товары активируем без повторного списания.
      $pending = $pdo->query("SELECT id, user_id, item_name FROM purchases
        WHERE status = 'pending' AND item_name IN ('+1 жизнь', 'Бустер ×2 кристалла', 'VIP-статус навсегда')")->fetchAll();
      usort($pending, fn($a, $b) => ($a['item_name'] === 'VIP-статус навсегда' ? 0 : 1) <=> ($b['item_name'] === 'VIP-статус навсегда' ? 0 : 1));
      foreach ($pending as $purchase) {
        $pid = (int)$purchase['id'];
        $uid = (int)$purchase['user_id'];
        $name = (string)$purchase['item_name'];
        $wallet = $pdo->prepare("SELECT lives, booster_until FROM wallets WHERE user_id = ? LIMIT 1");
        $wallet->execute([$uid]);
        $w = $wallet->fetch();

        if ($w && $name === 'VIP-статус навсегда') {
          $newLives = min(7, (int)$w['lives'] + 2);
          $delta = $newLives - (int)$w['lives'];
          $pdo->prepare("UPDATE wallets SET lives = ? WHERE user_id = ?")->execute([$newLives, $uid]);
          if ($delta > 0) $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'lives', ?, 'VIP: запас жизней увеличен', 'purchase', ?)")->execute([$uid, $delta, $pid]);
        } elseif ($w && $name === '+1 жизнь') {
          $vip = $pdo->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND item_name = 'VIP-статус навсегда' AND status IN ('pending','done') LIMIT 1");
          $vip->execute([$uid]);
          $maxLives = $vip->fetchColumn() ? 7 : 5;
          $newLives = min($maxLives, (int)$w['lives'] + 1);
          $delta = $newLives - (int)$w['lives'];
          $pdo->prepare("UPDATE wallets SET lives = ? WHERE user_id = ?")->execute([$newLives, $uid]);
          if ($delta > 0) $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'lives', ?, 'Покупка: +1 жизнь', 'purchase', ?)")->execute([$uid, $delta, $pid]);
        } elseif ($w && $name === 'Бустер ×2 кристалла') {
          $base = !empty($w['booster_until']) && strtotime((string)$w['booster_until']) > time()
            ? strtotime((string)$w['booster_until']) : time();
          $until = gmdate('Y-m-d\TH:i:s\Z', $base + 86400);
          $pdo->prepare("UPDATE wallets SET booster_until = ? WHERE user_id = ?")->execute([$until, $uid]);
        }

        $pdo->prepare("UPDATE purchases SET status = 'done', note = 'Доступ активирован автоматически', updated_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $pid]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  },

  // v27 — персональное продление ДЗ и расходуемые заморозки серии.
  27 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS deadline_extensions (
      id {PK},
      user_id INT NOT NULL,
      homework_id INT NOT NULL,
      purchase_id INT,
      extra_days INT DEFAULT 1,
      created_at {TS},
      UNIQUE (user_id, homework_id),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (homework_id) REFERENCES homeworks(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS streak_freezes (
      id {PK},
      user_id INT NOT NULL,
      purchase_id INT,
      used_at VARCHAR(30),
      missed_date VARCHAR(20),
      created_at {TS},
      UNIQUE (purchase_id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $pdo->beginTransaction();
    try {
      $pdo->exec("UPDATE shop_items SET description = 'Автоматически спасает серию за один пропущенный день' WHERE name = 'Заморозка стрика'");
      $pdo->exec("UPDATE shop_items SET description = 'Выбери активное ДЗ и добавь к его дедлайну 24 часа' WHERE name = 'Продление дедлайна +24ч'");
      $pdo->exec("UPDATE achievements SET reward = 100, target = 7 WHERE code = 'streak_7'");

      // Старые заявки на заморозку превращаем в реальные неиспользованные токены.
      $pendingFreezes = $pdo->query("SELECT id, user_id FROM purchases WHERE status IN ('pending','done') AND item_name = 'Заморозка стрика'")->fetchAll();
      $addFreeze = $pdo->prepare("INSERT INTO streak_freezes (user_id, purchase_id) VALUES (?, ?)");
      $doneFreeze = $pdo->prepare("UPDATE purchases SET status = 'done', note = 'Заморозка добавлена и сработает автоматически', updated_at = ? WHERE id = ?");
      foreach ($pendingFreezes as $purchase) {
        $addFreeze->execute([(int)$purchase['user_id'], (int)$purchase['id']]);
        $doneFreeze->execute([date('Y-m-d H:i:s'), (int)$purchase['id']]);
      }

      // Старую заявку на продление нельзя привязать к ДЗ задним числом: отменяем и возвращаем оплату.
      $pendingExtensions = $pdo->query("SELECT id, user_id, price FROM purchases WHERE status = 'pending' AND item_name = 'Продление дедлайна +24ч'")->fetchAll();
      $refundWallet = $pdo->prepare("UPDATE wallets SET crystals = crystals + ? WHERE user_id = ?");
      $refundLog = $pdo->prepare("INSERT INTO coin_log (user_id, kind, amount, reason, ref_type, ref_id) VALUES (?, 'crystals', ?, 'Возврат: выбери ДЗ для продления', 'purchase', ?)");
      $cancel = $pdo->prepare("UPDATE purchases SET status = 'canceled', note = 'Нужно выбрать конкретное ДЗ — кристаллы возвращены', updated_at = ? WHERE id = ?");
      foreach ($pendingExtensions as $purchase) {
        $price = (int)$purchase['price'];
        $uid = (int)$purchase['user_id'];
        $pid = (int)$purchase['id'];
        $refundWallet->execute([$price, $uid]);
        $refundLog->execute([$uid, $price, $pid]);
        $cancel->execute([date('Y-m-d H:i:s'), $pid]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  },

  // v28 — индивидуальные пробники с онлайн-наблюдением наставника.
  28 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'mock_exams', 'format')) {
      $pdo->exec("ALTER TABLE mock_exams ADD COLUMN format VARCHAR(20) DEFAULT 'normal'");
    }
    if (!column_exists($pdo, $driver, 'mock_exams', 'access_mode')) {
      $pdo->exec("ALTER TABLE mock_exams ADD COLUMN access_mode VARCHAR(20) DEFAULT 'general'");
    }

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS mock_assignments (
      id {PK},
      mock_id INT NOT NULL,
      user_id INT NOT NULL,
      starts_at VARCHAR(20),
      duration INT DEFAULT 180,
      status VARCHAR(20) DEFAULT 'scheduled',
      camera_consent_at VARCHAR(30),
      started_at VARCHAR(30),
      finished_at VARCHAR(30),
      created_at {TS},
      updated_at VARCHAR(30),
      UNIQUE (mock_id, user_id),
      FOREIGN KEY (mock_id) REFERENCES mock_exams(id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS proctored_mock_presence (
      id {PK},
      assignment_id INT NOT NULL,
      current_question INT DEFAULT 0,
      cursor_x INT DEFAULT 0,
      cursor_y INT DEFAULT 0,
      viewport_w INT DEFAULT 0,
      viewport_h INT DEFAULT 0,
      visibility VARCHAR(20) DEFAULT 'visible',
      focus_state VARCHAR(20) DEFAULT 'focused',
      camera_state VARCHAR(30) DEFAULT 'off',
      peer_state VARCHAR(30) DEFAULT 'new',
      answers TEXT,
      last_seen VARCHAR(30),
      updated_at {TS},
      UNIQUE (assignment_id),
      FOREIGN KEY (assignment_id) REFERENCES mock_assignments(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS proctored_mock_messages (
      id {PK},
      assignment_id INT NOT NULL,
      sender VARCHAR(20) NOT NULL,
      content VARCHAR(1000) NOT NULL,
      created_at {TS},
      FOREIGN KEY (assignment_id) REFERENCES mock_assignments(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS proctored_mock_events (
      id {PK},
      assignment_id INT NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      detail VARCHAR(255),
      created_at {TS},
      FOREIGN KEY (assignment_id) REFERENCES mock_assignments(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS proctored_mock_signals (
      id {PK},
      assignment_id INT NOT NULL,
      sender VARCHAR(20) NOT NULL,
      recipient VARCHAR(20) NOT NULL,
      signal_type VARCHAR(20) NOT NULL,
      payload TEXT NOT NULL,
      created_at {TS},
      FOREIGN KEY (assignment_id) REFERENCES mock_assignments(id)
    ) {ENGINE}", $driver));
  },

  // v29 — ручной ответ «развёрнутый текст + заданное количество файлов».
  29 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'questions', 'file_limit')) {
      $pdo->exec("ALTER TABLE questions ADD COLUMN file_limit INT DEFAULT 1");
    }

    // Файл сначала принадлежит черновику ученика, после сдачи привязывается к submission.
    // URL в answers никогда не принимаем на доверии: при сдаче он восстанавливается из этой таблицы.
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS answer_uploads (
      id {PK},
      user_id INT NOT NULL,
      question_id INT NOT NULL,
      submission_id INT,
      url VARCHAR(500) NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      mime VARCHAR(120),
      size INT DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (question_id) REFERENCES questions(id),
      FOREIGN KEY (submission_id) REFERENCES submissions(id)
    ) {ENGINE}", $driver));
  },

  // v30 — согласование рода в уведомлениях о проверенном пробнике.
  30 => function (PDO $pdo, string $driver) {
    $pdo->exec("UPDATE notifications
                SET text = REPLACE(text, 'Пробник проверена:', 'Пробник проверен:')
                WHERE text LIKE 'Пробник проверена:%'");
  },

  // v31 — даты месяца курса («с какого по какое», YYYY-MM-DD строкой). По ним при открытии
  // курса ученику автоматически выбирается текущий месяц (см. course_get + CourseInside).
  31 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'months', 'starts_on')) $pdo->exec("ALTER TABLE months ADD COLUMN starts_on VARCHAR(20)");
    if (!column_exists($pdo, $driver, 'months', 'ends_on'))   $pdo->exec("ALTER TABLE months ADD COLUMN ends_on VARCHAR(20)");
  },

  // v32 — откат демо-данных, случайно пересозданных сидом при прогоне миграций 18.07.2026
  // (админ ранее удалил демо-контент, пустые таблицы снова насеялись). Только прод (MySQL):
  // на локальной SQLite демо-данные нужны. Удаляем строго то, что вставляет сид.
  32 => function (PDO $pdo, string $driver) {
    if ($driver !== 'mysql') return;
    $eventTitles = ['Вебинар: параметры в задании 18','Практика: планиметрия','Вебинар: производная и её применение','Практика: тригонометрические уравнения','Разбор пробного ЕГЭ'];
    $mockTitles  = ['Пробный ЕГЭ по математике','Пробный ЕГЭ по математике 2','Пробник ПРО по математике','Пробный ЕГЭ по русскому языку','Пробный ЕГЭ по истории'];
    $pdo->beginTransaction();
    try {
      $ph = implode(',', array_fill(0, count($eventTitles), '?'));
      $st = $pdo->prepare("DELETE FROM events WHERE link = 'https://kinescope.io/live' AND title IN ($ph)");
      $st->execute($eventTitles);

      $ph = implode(',', array_fill(0, count($mockTitles), '?'));
      $sel = $pdo->prepare("SELECT id FROM mock_exams WHERE title IN ($ph)");
      $sel->execute($mockTitles);
      foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $pdo->prepare("DELETE FROM questions WHERE mock_id = ?")->execute([$mid]);
        $pdo->prepare("DELETE FROM mock_exams WHERE id = ?")->execute([$mid]);
      }

      // Сид зачислял всех учеников на все курсы с source='subscription' (админка ставит 'manual').
      $pdo->prepare("DELETE FROM enrollments WHERE source = 'subscription' AND created_at >= '2026-07-18 00:00:00'")->execute();
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  },

  // v33 — фото-расписание месяца: галерея до 10 картинок (грузит админ) + название
  // кнопки у ученика (например «Расписание июля»). Кнопка видна, пока не выбран урок.
  33 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'months', 'gallery_label')) $pdo->exec("ALTER TABLE months ADD COLUMN gallery_label VARCHAR(120)");
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS month_photos (
      id {PK},
      month_id INT NOT NULL,
      url VARCHAR(400) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (month_id) REFERENCES months(id)
    ) {ENGINE}", $driver));
  },

  // v34 — чинит kinescope_id, куда вставили ссылку или embed-код вместо id ролика:
  // вытаскиваем чистый id (тот же парсинг теперь стоит и на сохранении в admin_catalog).
  34 => function (PDO $pdo, string $driver) {
    $rows = $pdo->query("SELECT id, kinescope_id FROM lessons WHERE kinescope_id LIKE '%kinescope.io%'")->fetchAll();
    $upd = $pdo->prepare("UPDATE lessons SET kinescope_id = ? WHERE id = ?");
    foreach ($rows as $r) {
      if (preg_match('~kinescope\.io/(?:embed/)?([\w-]+)~', (string)$r['kinescope_id'], $m)) {
        $upd->execute([$m[1], (int)$r['id']]);
      }
    }
  },

  // v36 — вложения и подсказки у вопросов ДЗ/пробников:
  //   • questions.hint — заранее написанная подсказка (у ученика откроется по кнопке);
  //   • question_attachments — картинки (над текстом, до 5) и файлы (под текстом) вопроса.
  36 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'questions', 'hint')) $pdo->exec("ALTER TABLE questions ADD COLUMN hint TEXT");
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS question_attachments (
      id {PK},
      question_id INT NOT NULL,
      kind VARCHAR(10) NOT NULL,
      name VARCHAR(200),
      size VARCHAR(40),
      url VARCHAR(400) NOT NULL,
      position INT DEFAULT 0,
      FOREIGN KEY (question_id) REFERENCES questions(id)
    ) {ENGINE}", $driver));
  },

  // v37 — описание занятия (Markdown): показывается ученику под названием урока.
  37 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'lessons', 'description')) $pdo->exec("ALTER TABLE lessons ADD COLUMN description TEXT");
  },

  // v38 — автостатус «пройден» и настоящие блокировки уроков:
  //   • lessons.opens_at — «открыть доступ»: до этой даты-времени урок закрыт для всех,
  //     но виден с подписью «откроется …» ('YYYY-MM-DD HH:MM', сравнение строками, МСК);
  //   • lesson_prereqs — какие уроки нужно пройти, чтобы открылся этот
  //     (заменяет ручной текст locked_hint; колонка остаётся, но больше не используется).
  38 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'lessons', 'opens_at')) $pdo->exec("ALTER TABLE lessons ADD COLUMN opens_at VARCHAR(20)");
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS lesson_prereqs (
      id {PK},
      lesson_id INT NOT NULL,
      required_lesson_id INT NOT NULL,
      position INT DEFAULT 0,
      UNIQUE (lesson_id, required_lesson_id),
      FOREIGN KEY (lesson_id) REFERENCES lessons(id),
      FOREIGN KEY (required_lesson_id) REFERENCES lessons(id)
    ) {ENGINE}", $driver));
  },

  // v39 — два новых товара-темы в магазине: «Мужская тема» и «Женская тема».
  // Описание нарочно не раскрывает цвета — это сюрприз (внутри они перепутаны местами).
  39 => function (PDO $pdo, string $driver) {
    $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM shop_items")->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO shop_items (name, description, category, price, once_only, badge, active, position) VALUES (?,?,?,?,?,?,1,?)");
    foreach ([
      ['Мужская тема', 'Секретное оформление для парней. Что внутри — узнаешь после покупки 😏', 500],
      ['Женская тема', 'Секретное оформление для девушек. Что внутри — узнаешь после покупки 😉', 500],
    ] as $t) {
      $ex = $pdo->prepare("SELECT 1 FROM shop_items WHERE name = ? LIMIT 1");
      $ex->execute([$t[0]]);
      if (!$ex->fetchColumn()) { $ins->execute([$t[0], $t[1], 'theme', $t[2], 1, 'СЮРПРИЗ', $pos]); $pos++; }
    }
  },

  // v40 — ДЗ «Базовый уровень» к уроку «Present Simple. Порядок слов» (из PDF, англ. летняя школа).
  // 13 вопросов в 4 заданиях; подсказки из PDF → hint (кнопка), пояснения из ответов → solution.
  // Идемпотентно: урок ищем по точному названию, при повторном прогоне/отсутствии урока — пропуск.
  40 => function (PDO $pdo, string $driver) {
    $lesson = $pdo->query("SELECT id FROM lessons WHERE title = 'Present Simple. Порядок слов' ORDER BY id LIMIT 1")->fetch();
    if (!$lesson) return;
    $lid = (int)$lesson['id'];
    $ex = $pdo->prepare("SELECT 1 FROM homeworks WHERE lesson_id = ? AND title = 'Базовый уровень' LIMIT 1");
    $ex->execute([$lid]);
    if ($ex->fetchColumn()) return;

    $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,0)")
        ->execute([$lid, 'normal', 'Базовый уровень', '']);
    $hwId = (int)$pdo->lastInsertId();

    $meanings = ['Регулярное действие', 'Постоянное состояние', 'Расписание/возраст', 'Эмоции', 'Законы природы/Непреложные истины'];
    $hint1 = "Примеры каждого значения Present Simple:\n1. Регулярное действие — I often watch Spongebob. Я часто смотрю Спанджбоба.\n2. Постоянное состояние — I live in Moscow. My friends are loyal to me.\n3. Расписание/возраст — My plane takes off at 6 am tomorrow. I turn 17 next month.\n4. Эмоции — I am afraid to fly. Я боюсь летать.\n5. Законы природы/Непреложные истины — The sun rises in the east. Family is important for everyone.";
    $hint2 = "Формы Present Simple.\nУтверждение: I/you/we/they + V; he/she/it + V+s. Если нет смыслового глагола: I am… / He/she/it is… / You/we/they are…\nОтрицание: I/you/we/they + do not + V; he/she/it + does not + V. Без смыслового глагола: am not / is not / are not.\nПример ответа: does not go";
    $hint3 = "Наречия НЕопределённой частотности (always, usually, sometimes, often, rarely, hardly ever, never) ставятся перед смысловым глаголом (Olaf ALWAYS MAKES everyone laugh), а после глагола be — за ним (Kristoff IS HARDLY EVER sad).\nНаречия определённой частотности (hourly, daily, weekly, monthly, every day, two times a day…) ставятся в начале или в конце предложения (Kristoff takes care of Sven DAILY).";
    $hint4 = "Что использовать в вопросах:\nDO/DOES — когда в предложении ЕСТЬ смысловой глагол (кроме be): DO + I/you/we/they, DOES + he/she/it. Do you like pizza? Does she work here?\nAM/IS/ARE — когда смыслового глагола НЕТ (только be): AM + I, IS + he/she/it, ARE + you/we/they. Are you ready? Is he tall?";

    // [type, text, options, correct, solution, hint]
    $qs = [
      ['single', "Задание 1. Определи значение Present Simple.\n\nRapunzel's hair is magic and heals people. — Волосы Рапунцель волшебные и исцеляют людей.",
        $meanings, [1, 4],
        "Это постоянное свойство волос Рапунцель. Можно выбрать и непреложную истину — для мультика это, можно сказать, закон =). Но «постоянное состояние» точнее.", $hint1],
      ['single', "Задание 1. Определи значение Present Simple.\n\nElsa is afraid to hurt Anna with ice powers. — Эльза боится навредить Анне своими ледяными чарами.",
        $meanings, [3],
        "Страх, любовь, ненависть, уважение, отвращение — это всё эмоции. Передаём их через Present Simple, если это постоянное чувство.", $hint1],
      ['single', "Задание 1. Определи значение Present Simple.\n\nSquidward turns 48 on October 9 this year. — Сквидварду исполнится 48 в октябре этого года (это, кстати, не придуманный факт).",
        $meanings, [2],
        "Когда мы говорим о будущем возрасте человека, о расписании транспорта или программе с назначенным временем — используем Present Simple.", $hint1],

      ['text', "Задание 2. Раскрой скобки.\n\nBruno _____ (not want) to talk about his visions with his family. — Бруно не хочет рассказывать своей семье о своих видениях.\n\nВАЖНО: в отрицании пиши только полную форму (без апострофа).",
        null, ['does not want', 'doesnotwant'],
        "Bruno does not want… Бруно — подлежащее в единственном числе (he), значит в отрицании нужен глагол does, а смысловой глагол want остаётся без окончания.", $hint2],
      ['text', "Задание 2. Раскрой скобки.\n\nSpongeBob and Patrick _____ (be) always loyal to each other. — Губка Боб и Патрик всегда верны друг другу.",
        null, ['are'],
        "SpongeBob and Patrick are… Это два персонажа, значит глагол be нужно использовать в форме множественного числа — are.", $hint2],
      ['text', "Задание 2. Раскрой скобки.\n\nMarlin _____ (be) afraid to let Nemo swim to school alone. — Марлин боится отпускать Немо в школу одного.",
        null, ['is'],
        "Marlin is… Глагол be ставим в форму is, если подлежащее единственного числа и заменяется на he/she/it.", $hint2],
      ['text', "Задание 2. Раскрой скобки.\n\nDonkey and Fiona _____ (not give) up on Shrek when the ogre tries to push everyone away. — Осёл и Фиона не отворачиваются от Шрека, даже когда огр пытается оттолкнуть от себя всех вокруг.\n\nВАЖНО: в отрицании пиши только полную форму (без апострофа).",
        null, ['do not give', 'donotgive'],
        "Donkey and Fiona do not give up… Осёл и Фиона — это два персонажа, значит в отрицании нам нужен вспомогательный глагол do.", $hint2],

      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\n(sometimes) Olaf (1)___ loses (2)___ his parts. — Олаф иногда теряет свои части тела.",
        ['Место (1)', 'Место (2)'], [0],
        "Olaf sometimes loses his parts. Наречия неопределённой частотности ставятся между подлежащим и смысловым глаголом в утверждении.", $hint3],
      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\n(daily) Peter Griffin (1)___ sees his friends in the bar (2)___. — Питер Гриффин ежедневно видится с друзьями в баре.",
        ['Место (1)', 'Место (2)'], [1],
        "Peter Griffin sees his friends in the bar daily. Наречия определённой частотности ставятся в конец предложения.", $hint3],
      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\n(rarely) (1)___ Shrek (2)___ leaves his swamp. — Шрек редко покидает своё болото.",
        ['Место (1)', 'Место (2)'], [1],
        "Shrek rarely leaves his swamp. Наречия неопределённой частотности ставятся между подлежащим и смысловым глаголом в утверждении.", $hint3],
      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\n(two times a day) Alberto practices (1)___ fast-eating pasta (2)___. — Альберто тренируется в скоростном поедании пасты два раза в день.",
        ['Место (1)', 'Место (2)'], [1],
        "Alberto practices fast-eating pasta two times a day. Наречия определённой частотности ставятся в конец предложения.", $hint3],

      ['text', "Задание 4. Ты собираешься посмотреть новую пьесу в местном театре и хочешь узнать подробности. Добавь глагол в вопрос.\n\n1) time — When _____ the play start at your theatre?",
        null, ['does'],
        "When does the play start at your theatre? Play — единственное число, третье лицо. Есть смысловой глагол start — нужен does.", $hint4],
      ['text', "Задание 4. Добавь глагол в вопрос.\n\n2) drinks availability — _____ you sell drinks?",
        null, ['do'],
        "Do you sell drinks? You — подлежащее второго лица. Есть смысловой глагол sell — нужен do.", $hint4],
      ['text', "Задание 4. Добавь глагол в вопрос.\n\n3) ticket price — How much _____ the ticket cost?",
        null, ['does'],
        "How much does the ticket cost? Ticket — единственное число, третье лицо. Есть смысловой глагол cost — нужен does.", $hint4],
      ['text', "Задание 4. Добавь глагол в вопрос.\n\n4) discounts for students — _____ you offer discounts for students?",
        null, ['do'],
        "Do you offer discounts for students? You — подлежащее второго лица. Есть смысловой глагол offer — нужен do.", $hint4],
    ];

    $qi = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $i => [$type, $text, $options, $correct, $solution, $hint]) {
      $qi->execute([
        $hwId, $type, $text,
        $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
        json_encode($correct, JSON_UNESCAPED_UNICODE),
        $solution, $hint, $i,
      ]);
    }
  },

  // v41 — ДЗ «Средний уровень» (medium) к уроку «Present Simple. Порядок слов» (из PDF).
  // 25 вопросов в 5 заданиях. Идемпотентно, как v40.
  41 => function (PDO $pdo, string $driver) {
    $lesson = $pdo->query("SELECT id FROM lessons WHERE title = 'Present Simple. Порядок слов' ORDER BY id LIMIT 1")->fetch();
    if (!$lesson) return;
    $lid = (int)$lesson['id'];
    $ex = $pdo->prepare("SELECT 1 FROM homeworks WHERE lesson_id = ? AND title = 'Средний уровень' LIMIT 1");
    $ex->execute([$lid]);
    if ($ex->fetchColumn()) return;

    $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = $lid")->fetchColumn();
    $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
        ->execute([$lid, 'medium', 'Средний уровень', '', $pos]);
    $hwId = (int)$pdo->lastInsertId();

    $hintSp = "Правописание окончаний 3-го лица единственного числа:\n• глагол заканчивается на -ch, -ss, -sh, -x, -zz → +es (watch → watches, miss → misses, buzz → buzzes);\n• согласная + y → y меняется на ies (hurry → hurries, reply → replies);\n• во всех остальных случаях → +s (come → comes, travel → travels).\nИсключения: have → has, go → goes, do → does, be → is.\nВ отрицании окончание добавляется к do: he/she/it + does not + V.";
    $hintQ = "Что использовать в вопросах:\nDO/DOES — когда в предложении ЕСТЬ смысловой глагол (кроме be): DO + I/you/we/they, DOES + he/she/it. Do you like pizza? Does she work here?\nAM/IS/ARE — когда смыслового глагола НЕТ (только be): AM + I, IS + he/she/it, ARE + you/we/they. Are you ready? Is he tall?";
    $hintAdv = "Наречия НЕопределённой частотности (always, usually, sometimes, often, rarely, hardly ever, never) ставятся перед смысловым глаголом, а после глагола be — за ним (Kristoff IS HARDLY EVER sad).\nНаречия определённой частотности (hourly, daily, weekly, every day, every five seconds, two times a day…) ставятся в начале или в конце предложения.";

    $t1 = "Задание 1. Пересказ 1-й части Frozen: впиши глагол в форме 3-го лица единственного числа.\n\n";
    $neg = "\n\nВАЖНО: в отрицании пиши только полную форму (без апострофа).";
    // [type, text, options, correct, solution, hint]
    $qs = [
      ['text', $t1 . "Elsa and Anna are two sisters in the kingdom of Arendelle. Elsa (1)______ (have) magical ice powers that she cannot control.",
        null, ['has'], "(1) has — исключение, запоминаем.", $hintSp],
      ['text', $t1 . "The king and queen decide to hide Elsa's powers. Elsa (2)______ (learn) how to control her magic alone.",
        null, ['learns'], "(2) learns — никаких особенных правил, просто s.", $hintSp],
      ['text', $t1 . "Anna (3)______ (miss) her sister but does not understand why Elsa never comes out anymore.",
        null, ['misses'], "(3) misses — после свистящих и шипящих сочетаний добавляем es.", $hintSp],
      ['text', $t1 . "Anna misses her sister but (4)______ (not understand) why Elsa never comes out anymore." . $neg,
        null, ['does not understand', 'doesnotunderstand'], "(4) does not understand — в отрицании окончание 3-го лица добавляется не к смысловому глаголу, а к вспомогательному do.", $hintSp],
      ['text', $t1 . "Years later, their parents die at sea. Elsa (5)______ (become) queen and has her coronation.",
        null, ['becomes'], "(5) becomes — никаких особенных правил, просто s.", $hintSp],
      ['text', $t1 . "She (6)______ (watch) nervously as people arrive.",
        null, ['watches'], "(6) watches — после свистящих и шипящих сочетаний добавляем es.", $hintSp],
      ['text', $t1 . "During the party, Anna announces she wants to marry Prince Hans, whom she just met. Elsa (7)______ (reply) that Anna cannot marry a stranger.",
        null, ['replies'], "(7) replies — если глагол заканчивается на согласную букву + y, заменяем y на i и добавляем es.", $hintSp],
      ['text', $t1 . "This argument makes Elsa lose control. Ice magic (8)______ (buzz) from her hands and everyone sees her powers.",
        null, ['buzzes'], "(8) buzzes — после свистящих и шипящих сочетаний добавляем es.", $hintSp],
      ['text', $t1 . "Terrified, Elsa (9)______ (go) to the mountains where she builds a beautiful ice castle and feels free.",
        null, ['goes'], "(9) goes — исключение, запоминаем.", $hintSp],
      ['text', $t1 . "Anna (10)______ (hurry) after her sister with Kristoff, his reindeer Sven, and Olaf (a snowman created by Elsa).",
        null, ['hurries'], "(10) hurries — если глагол заканчивается на согласную букву + y, заменяем y на i и добавляем es.", $hintSp],
      ['text', $t1 . "Anna slowly turns to ice and must find true love to save herself. Hans reveals he (11)______ (not love) Anna and leaves her to die." . $neg,
        null, ['does not love', 'doesnotlove'], "(11) does not love — в отрицании окончание 3-го лица добавляется не к смысловому глаголу, а к вспомогательному do.", $hintSp],
      ['text', $t1 . "Hans reveals he does not love Anna and (12)______ (leave) her to die. Anna sacrifices herself to save Elsa from Hans' sword and completely freezes.",
        null, ['leaves'], "(12) leaves — никаких особенных правил, просто s.", $hintSp],
      ['text', $t1 . "This act of true love melts Anna's frozen heart and (13)______ (save) her life. Elsa finally understands that love, not fear, controls her magic.",
        null, ['saves'], "(13) saves — никаких особенных правил, просто s.", $hintSp],

      ['text', "Задание 2. Восстанови недостающую часть вопроса, ориентируясь на перевод. Запиши только пропущенную часть.\n\n(1) ______ ______ ______ a magical gift? — Есть ли у Мирабель (Mirabel) особый дар?",
        null, ['Does Mirabel have', 'DoesMirabelhave'], "Does Mirabel have a magical gift? Есть смысловой глагол have, подлежащее — 3-е лицо единственного числа, поэтому вопрос начинается с Does.", $hintQ],
      ['text', "Задание 2. Восстанови недостающую часть вопроса.\n\n(2) ______ ______ ______? — Кристофф (Kristoff) храбрый (brave)?",
        null, ['Is Kristoff brave', 'IsKristoffbrave'], "Is Kristoff brave? Смыслового глагола нет — только be, поэтому вопрос начинается с Is.", $hintQ],
      ['text', "Задание 2. Восстанови недостающую часть вопроса.\n\n(3) ______ ______ ______ ______ of the forgetful fish in Finding Nemo? — Как зовут забывчивую рыбку из мультика «В поисках Немо»?",
        null, ['What is the name', 'Whatisthename'], "What is the name of the forgetful fish in Finding Nemo? Вопросительное слово ставится до глагола be.", $hintQ],
      ['text', "Задание 2. Восстанови недостающую часть вопроса.\n\n(4) ______ ______ ______ ______ ______ with her sister? — У Анны крепкая связь (have strong bonds) с Эльзой?",
        null, ['Does Anna have strong bonds', 'DoesAnnahavestrongbonds'], "Does Anna have strong bonds with her sister? Есть смысловой глагол have — нужен вспомогательный does.", $hintQ],
      ['text', "Задание 2. Восстанови недостающую часть вопроса.\n\n(5) ______ ______ ______ ______ their own language? — Говорят (speak) ли миньоны (the minions) на своём собственном языке?",
        null, ['Do the minions speak', 'Dotheminionsspeak'], "Do the minions speak their own language? Миньоны — множественное число, есть смысловой глагол speak — нужен do.", $hintQ],
      ['text', "Задание 2. Восстанови недостающую часть вопроса.\n\n(6) ______ ______ ______ ______ melt? — Боится (afraid) ли Олаф (Olaf) растаять?",
        null, ['Is Olaf afraid to', 'IsOlafafraidto'], "Is Olaf afraid to melt? Смыслового глагола нет (afraid — прилагательное), поэтому вопрос начинается с Is.", $hintQ],

      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\na) (daily) The Incredibles family (1)___ trains together (2)___.",
        ['Место (1)', 'Место (2)'], [1], "The Incredibles family trains together daily. Наречия определённой частотности ставятся в конец предложения.", $hintAdv],
      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\nb) (hardly ever) Rapunzel (1)___ leaves (2)___ her tower.",
        ['Место (1)', 'Место (2)'], [0], "Rapunzel hardly ever leaves her tower. Наречия неопределённой частотности ставятся между подлежащим и смысловым глаголом.", $hintAdv],
      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\nc) (always) Olaf (1)___ is (2)___ cheerful.",
        ['Место (1)', 'Место (2)'], [1], "Olaf is always cheerful. Наречия неопределённой частотности ставятся после глагола be.", $hintAdv],
      ['single', "Задание 3. Определи, куда нужно поставить наречие частотности.\n\nd) (every five seconds) Dory (1)___ forgets important things (2)___.",
        ['Место (1)', 'Место (2)'], [1], "Dory forgets important things every five seconds. Наречия определённой частотности ставятся в конец предложения.", $hintAdv],

      ['text', "Задание 4. Повторяем фразовые глаголы.\nПрочитай предложения (a–e) и найди правильный перевод для каждого фразового глагола из списка (1–5). Запиши ответ как последовательность из 5 цифр: первая цифра — перевод для (a), вторая — для (b) и т.д.\n\na) Spongebob and Patrick often EAT OUT at the Krusty Krab.\nb) Brian and Stewie often HANG OUT together.\nc) The Madrigals GET TOGETHER to celebrate the Gift day.\nd) Luca and Giulia GO OUT to gaze at the stars in the evening.\ne) Mirabel LOOKS AFTER her little cousin Antonio.\n\n1) проводить время\n2) гулять/выходить\n3) есть в кафе/ресторане\n4) собираться\n5) заботиться",
        null, ['31425'], "a) eat out — есть в кафе (Спанчбоб и Патрик часто едят в «Красти Крабе»);\nb) hang out — проводить время (Брайан и Стьюи часто проводят время вместе);\nc) get together — собираться (Мадригалы собираются, чтобы отпраздновать День Дара);\nd) go out — гулять/выходить (Лука и Джулия выходят любоваться звёздами вечером);\ne) look after — заботиться (Мирабель заботится о своём младшем двоюродном брате Антонио).", null],

      ['text', "Задание 5. Прочитай текст и подбери подходящий фразовый глагол для каждого пропуска (a–e). Каждый глагол можно использовать только один раз. Запиши последовательность из 5 цифр.\n\nГлаголы: 1) go out  2) eat out  3) get together  4) look after  5) hang out\n\nPeter Griffin isn't a model family guy. He spends every evening (a) ____ing out and drinking with his friends at the Drunken Clam instead of (b) ____ing out with his family at home. While he enjoys the time with his buddies, his dog, Brian, often has to (c) ____ after Peter's younger son Stewie.\nPeter loves to (d) ____ out somewhere and have fun, but this means he misses family moments and hardly ever (e) ____s together with his family.",
        null, ['25413'], "Питер Гриффин вовсе не образцовый семьянин. Он проводит каждый вечер, (a) eating out — ужиная и выпивая с друзьями в «Пьяной устрице», вместо того чтобы (b) hanging out — проводить время с семьёй дома. Пока он наслаждается временем с приятелями, его пёс Брайан часто вынужден (c) look after — присматривать за младшим сыном Питера, Стьюи.\nПитер любит (d) go out — куда-нибудь ходить и развлекаться, но это значит, что он упускает семейные моменты и почти никогда не (e) gets together — собирается со всей семьёй.", null],
    ];

    $qi = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $i => [$type, $text, $options, $correct, $solution, $hint]) {
      $qi->execute([
        $hwId, $type, $text,
        $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
        json_encode($correct, JSON_UNESCAPED_UNICODE),
        $solution, $hint, $i,
      ]);
    }
  },

  // v42 — ДЗ к уроку «Словообразование с нуля» (из PDF): «Базовый уровень» (normal, 14 вопросов)
  // и «Средний уровень» (medium, 14 вопросов). Идемпотентно, как v40/v41.
  42 => function (PDO $pdo, string $driver) {
    $lesson = $pdo->query("SELECT id FROM lessons WHERE title = 'Словообразование с нуля' ORDER BY id LIMIT 1")->fetch();
    if (!$lesson) return;
    $lid = (int)$lesson['id'];

    $insertHw = function (string $level, string $title, array $qs) use ($pdo, $lid) {
      $ex = $pdo->prepare("SELECT 1 FROM homeworks WHERE lesson_id = ? AND title = ? LIMIT 1");
      $ex->execute([$lid, $title]);
      if ($ex->fetchColumn()) return;
      $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = $lid")->fetchColumn();
      $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
          ->execute([$lid, $level, $title, '', $pos]);
      $hwId = (int)$pdo->lastInsertId();
      $qi = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
      );
      foreach ($qs as $i => [$type, $text, $options, $correct, $solution, $hint]) {
        $qi->execute([
          $hwId, $type, $text,
          $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
          json_encode($correct, JSON_UNESCAPED_UNICODE),
          $solution, $hint, $i,
        ]);
      }
    };

    // ── Базовый уровень ──
    $hintPeople = "Суффиксы, образующие названия людей:\n-er (teach → teacher), -or (act → actor, invent → inventor), -ist (art → artist, science → scientist), -ian (music → musician).\nНе забудь про окончание множественного числа, если речь о нескольких людях.";
    $hintNum = "Как понять, один человек или несколько:\n• a / an / one → один человек;\n• two / many / several → несколько человек;\n• is / глагол с -s → один человек;\n• are / глагол без -s → несколько человек.";
    $b2 = "Задание 2. Образуй существительное от слова в скобках. Запиши только готовое слово.\n\n";
    $b3 = "Задание 3. Выбери правильную форму существительного.\n\n";
    $baseQs = [
      ['text', "Задание 1. Определи, какие слова называют людей. Запиши их номера подряд, без пробелов (пример ответа: 1234).\n\n1. musician\n2. perform\n3. scientist\n4. friendly\n5. actor\n6. create\n7. traveller\n8. artistic",
        null, ['1357'],
        "1. musician — музыкант, СУЩ; называет человека.\n2. perform — выступать, ГЛ.\n3. scientist — учёный, СУЩ; называет человека.\n4. friendly — дружелюбный, ПРИЛ.\n5. actor — актёр, СУЩ; называет человека.\n6. create — создавать, ГЛ.\n7. traveller — путешественник, СУЩ; называет человека.\n8. artistic — творческий, артистичный, ПРИЛ.", null],
      ['text', $b2 . "1. Miguel dreams of becoming a famous __________ and playing his guitar on a big stage. (music)\nМигель мечтает стать известным музыкантом и играть на гитаре на большой сцене.",
        null, ['musician'], "music → musician — музыкант; используется суффикс -ian.", $hintPeople],
      ['text', $b2 . "2. Hiro is a young __________ who can build incredible robots. (invent)\nХиро — молодой изобретатель, который умеет создавать невероятных роботов.",
        null, ['inventor'], "invent → inventor — изобретатель; используется суффикс -or.", $hintPeople],
      ['text', $b2 . "3. The local __________ wants to make a huge snow sculpture of Olaf. (art)\nМестный художник хочет сделать огромную скульптуру Олафа из снега.",
        null, ['artist'], "art → artist — художник; используется суффикс -ist.", $hintPeople],
      ['text', $b2 . "4. Our science __________ shows us an experiment with colourful bubbles. (teach)\nНаш учитель естественных наук показывает нам эксперимент с разноцветными пузырями.",
        null, ['teacher'], "teach → teacher — учитель; используется суффикс -er.", $hintPeople],
      ['text', $b2 . "5. A young __________ studies why we like Disney characters that seem like real people. (science)\nМолодой учёный изучает, почему нам нравятся диснеевские персонажи, похожие на настоящих людей.",
        null, ['scientist'], "science → scientist — учёный; используется суффикс -ist.", $hintPeople],
      ['text', $b2 . "6. The __________ plays a brave superhero in the new film. (act)\nАктёр играет отважного супергероя в новом фильме.",
        null, ['actor'], "act → actor — актёр; используется суффикс -or.", $hintPeople],
      ['text', $b2 . "7. Disney World attracts millions of __________ every year. (travel)\nДиснейуорлд ежегодно привлекает миллионы путешественников.",
        null, ['travellers', 'travelers'], "travel → travellers — путешественники; в британском варианте удваивается буква l. После millions of нужно множественное число.", $hintPeople],
      ['single', $b3 . "1. Many young __________ visit Disney World every year.", ['traveller', 'travellers'], [1],
        "travellers — после many требуется множественное число.", $hintNum],
      ['single', $b3 . "2. A talented __________ plays the guitar at the family celebration.", ['musician', 'musicians'], [0],
        "musician — после артикля a требуется единственное число.", $hintNum],
      ['single', $b3 . "3. Two clever __________ are building a flying bicycle.", ['inventor', 'inventors'], [1],
        "inventors — после числительного two требуется множественное число.", $hintNum],
      ['single', $b3 . "4. The young __________ studies the strange footprints near the castle.", ['scientist', 'scientists'], [0],
        "scientist — глагол studies показывает, что речь идёт об одном человеке.", $hintNum],
      ['single', $b3 . "5. Several __________ paint colourful pictures for the festival.", ['artist', 'artists'], [1],
        "artists — после several требуется множественное число.", $hintNum],
      ['single', $b3 . "6. A famous __________ plays the main character in the film.", ['actor', 'actors'], [0],
        "actor — после артикля a требуется единственное число.", $hintNum],
    ];
    $insertHw('normal', 'Базовый уровень', $baseQs);

    // ── Средний уровень ──
    $hintBla = "Смотри на место слова в предложении:\n• Her blablabla helped us. → СУЩ\n• The blablabla robot moved. → ПРИЛ\n• They blablabla the door. → ГЛ\n• The robot moved very blablabla. → НАР";
    $hintAbs = "Суффиксы абстрактных существительных:\n-ness (kind → kindness), -ity (curious → curiosity), -ion (decide → decision, protect → protection), -ance (perform → performance), -ure (depart → departure, mix → mixture), -ence (evident → evidence).";
    $m2 = "Задание 2. Образуй существительное от слова в скобках.\n\n";
    $m3 = "Задание 3. Формат ЕГЭ (25–29). Образуй от слова, напечатанного заглавными буквами, однокоренное слово так, чтобы оно грамматически и лексически соответствовало тексту «School Animation Day».\n\n";
    $midQs = [
      ['text', "Задание 1. Где спряталось существительное? В каждом предложении blablabla заменяет одну часть речи: существительное, прилагательное, глагол или наречие. Запиши номера предложений, в которых blablabla — существительное, подряд, без пробелов (пример ответа: 147). Само слово угадывать не нужно.\n\n1. Mirabel's blablabla helps her notice what every member of her family really needs.\n2. Baymax moves blablabla when he tries to enter the tiny bedroom.\n3. Hiro's blablabla surprises everyone at the school science fair.\n4. Moana must blablabla the island before the storm begins.\n5. Rapunzel asks hundreds of questions out of blablabla, not because she wants to annoy Flynn.\n6. The villagers blablabla the magical candle from the strong wind.\n7. Miguel's first public blablabla makes his family see music differently.\n8. Olaf explains very blablabla why summer is his favourite season.\n9. The blablabla sculpture of a dragon stands near the palace gate.",
        null, ['1357'],
        "1. СУЩ — перед пропуском притяжательная форма Mirabel's, после — глагол helps (например, kindness).\n2. НАР — слово описывает, как Baymax двигается (carefully).\n3. СУЩ — притяжательная форма Hiro's, после — глагол surprises (invention, creativity).\n4. ГЛ — после модального must нужен смысловой глагол без to (leave).\n5. СУЩ — после предлога of требуется существительное (curiosity).\n6. ГЛ — после подлежащего the villagers требуется сказуемое (protect).\n7. СУЩ — перед пропуском Miguel's first public, после — глагол makes (performance).\n8. НАР — слово описывает, как Olaf объясняет (cheerfully).\n9. ПРИЛ — слово стоит перед существительным sculpture и описывает его (enormous).", $hintBla],
      ['text', $m2 . "1. Mirabel's __________ helps her understand relatives who feel lonely or forgotten. (kind)\nДоброта Мирабель помогает ей понимать родственников, которые чувствуют себя одинокими или забытыми.",
        null, ['kindness'], "kind → kindness — после притяжательной формы Mirabel's требуется существительное. Суффикс -ness образует название качества.", $hintAbs],
      ['text', $m2 . "2. Rapunzel's __________ becomes even stronger when she sees the floating lights outside the tower. (curious)\nЛюбопытство Рапунцель становится ещё сильнее, когда она видит парящие фонарики за пределами башни.",
        null, ['curiosity'], "curious → curiosity — пропущенное слово является подлежащим перед becomes. Суффикс -ity образует абстрактное существительное.", $hintAbs],
      ['text', $m2 . "3. Moana makes the __________ to sail across the ocean and return the heart of Te Fiti. (decide)\nМоана принимает решение отправиться через океан и вернуть сердце Те Фити.",
        null, ['decision'], "decide → decision — устойчивое выражение make the decision to do something — принять решение что-либо сделать.", $hintAbs],
      ['text', $m2 . "4. Baymax was designed to provide __________ in dangerous situations. (protect)\nБэймакс был создан, чтобы обеспечивать защиту в опасных ситуациях.",
        null, ['protection'], "protect → protection — после глагола provide требуется существительное: обеспечить защиту.", $hintAbs],
      ['text', $m2 . "5. Miguel's final __________ changes his family's attitude to music. (perform)\nФинальное выступление Мигеля меняет отношение его семьи к музыке.",
        null, ['performance'], "perform → performance — после притяжательной формы и прилагательного final требуется существительное: финальное выступление.", $hintAbs],
      ['text', $m2 . "6. The sudden __________ of Elsa surprises everyone in the palace. (depart)\nНеожиданный уход Эльзы удивляет всех во дворце.",
        null, ['departure'], "depart → departure — после артикля the и прилагательного sudden требуется существительное: неожиданный отъезд.", $hintAbs],
      ['text', $m2 . "7. The magical drink is a strange __________ of flowers, rainwater and silver dust. (mix)\nВолшебный напиток представляет собой странную смесь цветов, дождевой воды и серебряной пыли.",
        null, ['mixture'], "mix → mixture — после артикля a и прилагательного strange требуется существительное в единственном числе.", $hintAbs],
      ['text', $m2 . "8. The detectives finally find enough __________ to solve the mystery. (evident)\nДетективы наконец находят достаточно доказательств, чтобы раскрыть тайну.",
        null, ['evidence'], "evident → evidence — после enough требуется существительное. Evidence — неисчисляемое, окончание множественного числа не добавляется.", $hintAbs],
      ['text', $m3 . "(25) Our school recently held an Animation Day. The main __________ ORGANISE was our art teacher.",
        null, ['organiser', 'organizer'], "organiser — после прилагательного main требуется существительное, обозначающее человека: главный организатор.", $hintPeople],
      ['text', $m3 . "(26) Her careful __________ PREPARE made the event enjoyable and easy to follow.",
        null, ['preparation'], "preparation — после притяжательного местоимения her и прилагательного careful требуется существительное: тщательная подготовка.", $hintAbs],
      ['text', $m3 . "(27) Students used their __________ IMAGINE to create heroes, villains and magical creatures.",
        null, ['imagination'], "imagination — после притяжательного местоимения their требуется существительное. Выражение use one's imagination — «использовать воображение».", $hintAbs],
      ['text', $m3 . "(28) The audience watched the final film in complete __________ SILENT.",
        null, ['silence'], "silence — устойчивое сочетание in complete silence — в полной тишине.", $hintAbs],
      ['text', $m3 . "(29) Winning first prize was the students' greatest __________ ACHIEVE of the school year.",
        null, ['achievement'], "achievement — после притяжательной формы students' и превосходной степени greatest требуется существительное: главное достижение.", $hintAbs],
    ];
    $insertHw('medium', 'Средний уровень', $midQs);
  },

  // v43 — ремонт уровней ДЗ, затёртых багом админки: 'level' стоял в списке числовых полей,
  // и любое сохранение ДЗ превращало 'medium'/'hard' в 0. Восстанавливаем по названиям.
  43 => function (PDO $pdo, string $driver) {
    $pdo->exec("UPDATE homeworks SET level = 'medium' WHERE level NOT IN ('normal','medium','hard') AND title = 'Средний уровень'");
    $pdo->exec("UPDATE homeworks SET level = 'hard' WHERE level NOT IN ('normal','medium','hard') AND (title LIKE '%овышенн%' OR title LIKE '%родвинут%')");
    $pdo->exec("UPDATE homeworks SET level = 'normal' WHERE level IS NULL OR level NOT IN ('normal','medium','hard')");
  },

  // v44 — осмысленные названия ДЗ: «тема урока (уровень)» вместо «Базовый/Средний уровень».
  // Название видно в разделе «Домашка» и при прохождении; кнопки в уроке подписаны уровнем.
  44 => function (PDO $pdo, string $driver) {
    $renames = [
      // [название урока, старое название ДЗ, новое название ДЗ]
      ['Present Simple. Порядок слов', 'Базовый уровень', 'Закрепление Present Simple (база)'],
      ['Present Simple. Порядок слов', 'Средний уровень', 'Закрепление Present Simple (средний)'],
      ['Словообразование с нуля', 'Базовый уровень', 'Суффиксы существительных (база)'],
      ['Словообразование с нуля', 'Средний уровень', 'Суффиксы существительных (средний)'],
    ];
    $st = $pdo->prepare(
      "UPDATE homeworks SET title = ?
       WHERE title = ? AND lesson_id IN (SELECT id FROM lessons WHERE title = ?)"
    );
    foreach ($renames as [$lessonTitle, $old, $new]) $st->execute([$new, $old, $lessonTitle]);
  },

  // v45 — уточнение названий ДЗ урока «Словообразование с нуля»: содержание шире суффиксов
  // (части речи, ед./мн. число), поэтому называем как исходное ДЗ — «Словообразование существительных».
  45 => function (PDO $pdo, string $driver) {
    $st = $pdo->prepare(
      "UPDATE homeworks SET title = ?
       WHERE title = ? AND lesson_id IN (SELECT id FROM lessons WHERE title = 'Словообразование с нуля')"
    );
    $st->execute(['Словообразование существительных (база)', 'Суффиксы существительных (база)']);
    $st->execute(['Словообразование существительных (средний)', 'Суффиксы существительных (средний)']);
  },

  // v46 — после переноса загрузок в приватный S3 меняем старые абсолютные /uploads URL
  // на прокси текущего домена. Так авторизационная cookie lk/admin остаётся на своём хосте.
  46 => function (PDO $pdo, string $driver) {
    foreach ([
      ['materials', 'url', 'id'],
      ['month_photos', 'url', 'id'],
      ['question_attachments', 'url', 'id'],
      ['answer_uploads', 'url', 'id'],
      ['profiles', 'avatar', 'user_id'],
    ] as [$table, $column, $idColumn]) {
      $rows = $pdo->query("SELECT `$idColumn` AS row_id, `$column` AS file_url FROM `$table` WHERE `$column` LIKE '%/uploads/%'")->fetchAll();
      $upd = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE `$idColumn` = ?");
      foreach ($rows as $row) {
        $url = (string)$row['file_url'];
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
        $pos = strpos($path, '/uploads/');
        if ($pos === false) continue;
        $key = ltrim(substr($path, $pos + strlen('/uploads/')), '/');
        if ($key === '' || str_contains($key, '..')) continue;
        $upd->execute(['/api/file.php?path=' . rawurlencode($key), (int)$row['row_id']]);
      }
    }
  },

  // v47 — базовое ДЗ к уроку «Тригонометрия с нуля» (13 заданий из PDF).
  // Условия и решения хранят формулы как LaTeX между $...$/$$...$$ — фронт рисует их через KaTeX.
  47 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->prepare(
      "SELECT l.id
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND c.title = 'Летняя школа по Профильной Математике'
         AND (
           l.title LIKE '%Тригонометрия с нуля%'
           OR (b.title = 'Тригонометрия' AND l.title = 'Практика')
         )
       ORDER BY l.id DESC LIMIT 1"
    );
    $lessonSt->execute();
    $lesson = $lessonSt->fetch();
    if (!$lesson) {
      // На чистой БД контента ещё может не быть. Но если математический курс уже создан,
      // не помечаем миграцию выполненной молча: название урока нужно уточнить.
      $hasMathCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id WHERE s.name = 'Профильная математика'"
      )->fetchColumn();
      if ($hasMathCourse > 0) {
        $titles = $pdo->query(
          "SELECT CONCAT(c.title, ' → ', b.title, ' → ', l.title)
           FROM lessons l
           JOIN blocks b ON b.id = l.block_id
           JOIN courses c ON c.id = b.course_id
           JOIN subjects s ON s.id = c.subject_id
           WHERE s.name = 'Профильная математика'
           ORDER BY c.id DESC, l.position, l.id"
        )->fetchAll(PDO::FETCH_COLUMN);
        throw new RuntimeException('Не найден урок «Тригонометрия с нуля». Уроки математики: ' . implode(' | ', $titles));
      }
      return;
    }
    $lid = (int)$lesson['id'];
    $ex = $pdo->prepare("SELECT 1 FROM homeworks WHERE lesson_id = ? AND title = 'Базовый уровень' LIMIT 1");
    $ex->execute([$lid]);
    if ($ex->fetchColumn()) return;

    $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?");
    $posSt->execute([$lid]);
    $pos = (int)$posSt->fetchColumn();
    $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
        ->execute([$lid, 'normal', 'Базовый уровень', '', $pos]);
    $hwId = (int)$pdo->lastInsertId();

    // [условие, допустимые ответы, решение]
    $qs = [
      [
        "Найдите значение выражения.\n\n" . '$$8\sqrt{3}\cos\frac{\pi}{6}\operatorname{ctg}\frac{\pi}{4}$$',
        ['12'],
        '$\cos\frac{\pi}{6}=\frac{\sqrt3}{2}$, а $\operatorname{ctg}\frac{\pi}{4}=1$. Поэтому $8\sqrt3\cdot\frac{\sqrt3}{2}\cdot1=12$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$\frac{-18\sqrt{12}}{\tan\frac{5\pi}{3}\cdot\tan\frac{19\pi}{4}}$$',
        ['-36'],
        '$\tan\frac{5\pi}{3}=-\sqrt3$, а $\tan\frac{19\pi}{4}=\tan\frac{3\pi}{4}=-1$. Тогда $\frac{-18\sqrt{12}}{(-\sqrt3)(-1)}=-36$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$13\sqrt6\cos495^\circ\sin(-240^\circ)$$',
        ['-19.5', '-19,5'],
        '$\cos495^\circ=-\frac{\sqrt2}{2}$, $\sin(-240^\circ)=\frac{\sqrt3}{2}$. Поэтому $13\sqrt6\cdot\left(-\frac{\sqrt2}{2}\right)\cdot\frac{\sqrt3}{2}=-19{,}5$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$-13\sqrt{12}\cos\left(-\frac{5\pi}{3}\right)\tan\frac{11\pi}{6}$$',
        ['13'],
        '$\cos\left(-\frac{5\pi}{3}\right)=\frac12$, $\tan\frac{11\pi}{6}=-\frac{\sqrt3}{3}$. Подставляем и получаем $13$.',
      ],
      [
        "Найдите " . '$21\operatorname{ctg}\alpha$' . ", если\n\n" . '$$\cos\alpha=\frac{2\sqrt{13}}{13},\qquad \alpha\in\left(\frac{3\pi}{2};2\pi\right).$$',
        ['-14'],
        'В IV четверти $\sin\alpha<0$. Из основного тригонометрического тождества получаем $\sin\alpha=-\frac{3\sqrt{13}}{13}$. Тогда $21\operatorname{ctg}\alpha=21\frac{\cos\alpha}{\sin\alpha}=-14$.',
      ],
      [
        "Найдите " . '$60\sqrt2\cos\alpha$' . ", если\n\n" . '$$\sin\alpha=\frac{7\sqrt2}{10},\qquad \alpha\in\left(\frac{\pi}{2};\pi\right).$$',
        ['-12'],
        'Во II четверти $\cos\alpha<0$. Из $\sin^2\alpha+\cos^2\alpha=1$ получаем $\cos\alpha=-\frac{\sqrt2}{10}$. Поэтому $60\sqrt2\cos\alpha=-12$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$28\sin\frac{\pi}{12}\cos\frac{\pi}{12}$$',
        ['7'],
        'Используем формулу $2\sin x\cos x=\sin2x$: $28\sin\frac{\pi}{12}\cos\frac{\pi}{12}=14\sin\frac{\pi}{6}=7$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$30\sqrt3\cos^2\frac{11\pi}{12}-30\sqrt3\sin^2\frac{11\pi}{12}$$',
        ['45'],
        '$\cos^2x-\sin^2x=\cos2x$. Значит выражение равно $30\sqrt3\cos\frac{11\pi}{6}=30\sqrt3\cdot\frac{\sqrt3}{2}=45$.',
      ],
      [
        "Найдите " . '$-30\cos2\alpha$' . ", если\n\n" . '$$\cos\alpha=0{,}9.$$' ,
        ['-18.6', '-18,6'],
        '$\cos2\alpha=2\cos^2\alpha-1$. Поэтому $-30\cos2\alpha=-30\left(2\cdot0{,}9^2-1\right)=-18{,}6$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$\frac{-53\sin49^\circ\cos49^\circ}{\sin98^\circ}$$',
        ['-26.5', '-26,5'],
        '$\sin98^\circ=2\sin49^\circ\cos49^\circ$. После сокращения получаем $-\frac{53}{2}=-26{,}5$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$\frac{-28\sin58^\circ}{\sin29^\circ\cdot\sin61^\circ}$$',
        ['-56'],
        '$\sin58^\circ=2\sin29^\circ\cos29^\circ$, а $\sin61^\circ=\cos29^\circ$. После сокращения получаем $-56$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$12\sqrt3\cos^2\frac{13\pi}{12}-\sqrt{108}$$',
        ['9'],
        '$\sqrt{108}=6\sqrt3$, поэтому выражение равно $6\sqrt3\left(2\cos^2\frac{13\pi}{12}-1\right)=6\sqrt3\cos\frac{13\pi}{6}=9$.',
      ],
      [
        "Найдите значение выражения.\n\n" . '$$\sqrt{147}-14\sqrt3\sin^2\frac{29\pi}{12}$$',
        ['-10.5', '-10,5'],
        '$\sqrt{147}=7\sqrt3$, поэтому выражение равно $7\sqrt3\left(1-2\sin^2\frac{29\pi}{12}\right)=7\sqrt3\cos\frac{29\pi}{6}=-10{,}5$.',
      ],
    ];

    $qi = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,NULL,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $i => [$text, $correct, $solution]) {
      $qi->execute([$hwId, $text, json_encode($correct, JSON_UNESCAPED_UNICODE), $solution, $i]);
    }
  },

  // v48 — по просьбе администратора сбрасываем тур Ярославу для повторного прохождения.
  // Строку исходной награды не трогаем: 100 кристаллов остаются одноразовыми.
  48 => function (PDO $pdo, string $driver) {
    $user = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $user->execute(['barsukovjena@mail.ru']);
    $uid = (int)($user->fetchColumn() ?: 0);
    if (!$uid) return;
    $reset = $pdo->prepare(
      "DELETE FROM coin_log
       WHERE user_id = ? AND kind = 'tour' AND ref_type = 'platform_tour_progress'"
    );
    $reset->execute([$uid]);
  },

  // v49 — центр аналитики ученика: еженедельные отчёты и безопасный просмотр кабинета.
  49 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS student_report_settings (
      id {PK},
      user_id INT NOT NULL,
      enabled INT DEFAULT 0,
      recipients TEXT,
      weekday INT DEFAULT 1,
      sender_email VARCHAR(190),
      sender_name VARCHAR(120),
      last_sent_week VARCHAR(20),
      updated_at {TS},
      UNIQUE (user_id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS student_report_deliveries (
      id {PK},
      user_id INT NOT NULL,
      recipients TEXT,
      period_start VARCHAR(20),
      period_end VARCHAR(20),
      status VARCHAR(20),
      error VARCHAR(500),
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS admin_impersonation_tokens (
      id {PK},
      token_hash VARCHAR(64) NOT NULL,
      admin_id INT NOT NULL,
      user_id INT NOT NULL,
      expires_at VARCHAR(40) NOT NULL,
      used_at VARCHAR(40),
      created_at {TS},
      UNIQUE (token_hash),
      FOREIGN KEY (admin_id) REFERENCES admins(id),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));
  },

  // v50 — базовое ДЗ ко второму блоку летней школы по математике:
  // «Планиметрия с нуля», 20 заданий с автопроверкой и текстовыми решениями.
  // Чертежи администратор добавит отдельно через вложения к вопросам.
  50 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->prepare(
      "SELECT l.id
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND c.title = 'Летняя школа по Профильной Математике'
         AND (l.title = 'Планиметрия с нуля' OR (b.n = 2 AND l.title LIKE '%Планиметр%'))
       ORDER BY l.id DESC LIMIT 1"
    );
    $lessonSt->execute();
    $lid = (int)($lessonSt->fetchColumn() ?: 0);
    if (!$lid) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses WHERE title = 'Летняя школа по Профильной Математике'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден урок второго блока «Планиметрия с нуля»');
      return;
    }

    $title = 'Планиметрия с нуля (база)';
    $hwSt = $pdo->prepare("SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? LIMIT 1");
    $hwSt->execute([$lid, $title]);
    $hwId = (int)($hwSt->fetchColumn() ?: 0);
    if ($hwId) {
      $countSt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
      $countSt->execute([$hwId]);
      if ((int)$countSt->fetchColumn() > 0) return;
    } else {
      $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?");
      $posSt->execute([$lid]);
      $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
          ->execute([$lid, 'normal', $title, '', (int)$posSt->fetchColumn()]);
      $hwId = (int)$pdo->lastInsertId();
    }

    // [условие, допустимые ответы, решение]
    $qs = [
      [
        'В треугольнике $ZTO$ угол $O$ равен $90^\\circ$, $ZO=\\sqrt3$, $ZT=2$. Найдите $\\sin Z$.',
        ['0,5', '0.5', '1/2'],
        'По теореме Пифагора $OT=\\sqrt{ZT^2-ZO^2}=\\sqrt{4-3}=1$. Тогда $\\sin Z=\\dfrac{OT}{ZT}=\\dfrac12=0{,}5$.',
      ],
      [
        'В треугольнике $ENA$ угол $A$ равен $90^\\circ$, $NA=2\\sqrt6$, $EN=5$. Найдите $\\cos E$.',
        ['0,2', '0.2', '1/5'],
        'По теореме Пифагора $EA=\\sqrt{EN^2-AN^2}=\\sqrt{25-24}=1$. Поэтому $\\cos E=\\dfrac{EA}{EN}=\\dfrac15=0{,}2$.',
      ],
      [
        'Острый угол $O$ прямоугольного треугольника $POS$ равен $31^\\circ$. Найдите угол между биссектрисой $SB$ и медианой $SK$, проведёнными из вершины прямого угла $S$. Ответ дайте в градусах.',
        ['14'],
        'Угол $P=90^\\circ-31^\\circ=59^\\circ$. Точка $K$ — середина гипотенузы, поэтому $KP=KS$, и треугольник $PSK$ равнобедренный: $\\angle PSK=59^\\circ$. Биссектриса прямого угла даёт $\\angle PSB=45^\\circ$. Искомый угол равен $59^\\circ-45^\\circ=14^\\circ$.',
      ],
      [
        'Угол между биссектрисой и медианой прямоугольного треугольника, проведёнными из вершины прямого угла, равен $13^\\circ$. Найдите больший острый угол этого треугольника. Ответ дайте в градусах.',
        ['58'],
        'Биссектриса делит прямой угол пополам. Больший острый угол равен углу между стороной и медианой: $45^\\circ+13^\\circ=58^\\circ$. Второй острый угол равен $32^\\circ$, поэтому искомый угол действительно $58^\\circ$.',
      ],
      [
        'В треугольнике $ZNR$ стороны $ZR$ и $NR$ равны. Внешний угол при вершине $N$ равен $130^\\circ$. Найдите угол $R$. Ответ дайте в градусах.',
        ['80'],
        'Внутренний угол при $N$ равен $180^\\circ-130^\\circ=50^\\circ$. Треугольник равнобедренный, поэтому угол $Z$ также равен $50^\\circ$. Тогда $\\angle R=180^\\circ-50^\\circ-50^\\circ=80^\\circ$.',
      ],
      [
        'Площадь треугольника $RXA$ равна $116$. Отрезок $ZH$ — средняя линия, параллельная стороне $RX$. Найдите площадь треугольника $AZH$.',
        ['29'],
        'Треугольники $AZH$ и $ARX$ подобны с коэффициентом $\\dfrac12$. Площади относятся как квадрат коэффициента подобия: $\\left(\\dfrac12\\right)^2=\\dfrac14$. Поэтому $S_{AZH}=116:4=29$.',
      ],
      [
        'Две стороны треугольника равны $24$ и $25$. Высота, проведённая к меньшей стороне, равна $18$. Чему равна высота, проведённая к большей стороне?',
        ['17,28', '17.28'],
        'Площадь треугольника через сторону $24$ равна $S=\\dfrac12\\cdot24\\cdot18=216$. Для стороны $25$ имеем $216=\\dfrac12\\cdot25\\cdot h$, откуда $h=\\dfrac{432}{25}=17{,}28$.',
      ],
      [
        'В треугольнике $DCS$ отрезок $DO$ — биссектриса, угол $S$ равен $41^\\circ$, угол $SDO$ равен $56^\\circ$. Найдите угол $DOC$. Ответ дайте в градусах.',
        ['97'],
        'Так как $DO$ — биссектриса, $\\angle CDO=56^\\circ$. В треугольнике $SDO$ угол $SOD=180^\\circ-56^\\circ-41^\\circ=83^\\circ$. Углы $SOD$ и $DOC$ смежные, поэтому $\\angle DOC=180^\\circ-83^\\circ=97^\\circ$.',
      ],
      [
        'Площадь треугольника $NZF$ равна $144$. Отрезок $RS$ — средняя линия, параллельная стороне $NZ$. Найдите площадь трапеции $NZSR$.',
        ['108'],
        'Треугольник $FRS$ подобен треугольнику $FNZ$ с коэффициентом $\\dfrac12$, поэтому его площадь равна $144:4=36$. Площадь трапеции $NZSR$ равна $144-36=108$.',
      ],
      [
        'Найдите вписанный угол $CHZ$, если он на $50^\\circ$ меньше центрального угла $CTZ$, опирающегося на ту же дугу. Ответ дайте в градусах.',
        ['50'],
        'Пусть вписанный угол равен $x$. Центральный угол, опирающийся на ту же дугу, равен $2x$. По условию $2x-x=50^\\circ$, значит $x=50^\\circ$.',
      ],
      [
        'В окружности с центром $M$ проведены диаметры $NE$ и $CR$. Вписанный угол $NEC$ равен $68^\\circ$. Найдите центральный угол $NMR$. Ответ дайте в градусах.',
        ['44'],
        'В треугольнике $CME$ радиусы $MC$ и $ME$ равны, а $\\angle CEM=68^\\circ$. Поэтому $\\angle CME=180^\\circ-2\\cdot68^\\circ=44^\\circ$. Углы $CME$ и $NMR$ вертикальные, следовательно $\\angle NMR=44^\\circ$.',
      ],
      [
        'В окружности с центром $H$ проведены диаметры $SR$ и $NC$. Центральный угол $SHC$ равен $60^\\circ$. Найдите вписанный угол $SRN$. Ответ дайте в градусах.',
        ['60'],
        'Угол $RHN$ вертикален углу $SHC$, поэтому $\\angle RHN=60^\\circ$. Треугольник $RHN$ равнобедренный, так как $RH=NH$. Значит $\\angle SRN=\\angle HRN=\\dfrac{180^\\circ-60^\\circ}{2}=60^\\circ$.',
      ],
      [
        'Четырёхугольник $XRKH$ вписан в окружность. Угол $XRH$ равен $24^\\circ$, угол $KXH$ равен $17^\\circ$. Найдите угол $XRK$. Ответ дайте в градусах.',
        ['41'],
        'Вписанные углы $KXH$ и $KRH$ опираются на одну дугу $KH$, поэтому $\\angle KRH=17^\\circ$. Тогда $\\angle XRK=\\angle XRH+\\angle HRK=24^\\circ+17^\\circ=41^\\circ$.',
      ],
      [
        'Четырёхугольник $RZNF$ вписан в окружность. Угол $RZN$ равен $16^\\circ$, угол $NRF$ равен $3^\\circ$. Найдите угол $RZF$. Ответ дайте в градусах.',
        ['13'],
        'Вписанные углы $NRF$ и $NZF$ опираются на одну дугу $NF$, поэтому $\\angle NZF=3^\\circ$. При этом $\\angle RZN=\\angle RZF+\\angle FZN$, следовательно $\\angle RZF=16^\\circ-3^\\circ=13^\\circ$.',
      ],
      [
        'В треугольнике $MCS$ сторона $MC$ равна $185$, угол $S$ равен $30^\\circ$. Найдите радиус окружности, описанной около этого треугольника.',
        ['185'],
        'По расширенной теореме синусов $\\dfrac{MC}{\\sin S}=2R$. Поэтому $R=\\dfrac{185}{2\\sin30^\\circ}=\\dfrac{185}{2\\cdot0{,}5}=185$.',
      ],
      [
        'Найдите угол $CKX$, если сторона $KC$ касается окружности в точке $C$, $X$ — центр окружности, сторона $KX$ пересекает окружность в точке $A$, а дуга $CA$, заключённая внутри этого угла, равна $22^\\circ$. Ответ дайте в градусах.',
        ['68'],
        'Центральный угол $AXC$ равен градусной мере дуги $AC$, то есть $22^\\circ$. Так как точки $K$, $A$ и $X$ лежат на одной прямой, $\\angle KXC=22^\\circ$. Радиус $XC$ перпендикулярен касательной $KC$, поэтому $\\angle KCX=90^\\circ$. Тогда $\\angle CKX=180^\\circ-90^\\circ-22^\\circ=68^\\circ$.',
      ],
      [
        'Угол $MBH$ равен $88^\\circ$, где $H$ — центр окружности. Сторона $BM$ касается окружности в точке $M$, а сторона $BH$ пересекает окружность в точке $O$. Найдите величину меньшей дуги $MO$, заключённой внутри этого угла. Ответ дайте в градусах.',
        ['2'],
        'Радиус $HM$ перпендикулярен касательной $BM$, поэтому треугольник $BHM$ прямоугольный. $\\angle BHM=180^\\circ-90^\\circ-88^\\circ=2^\\circ$. Лучи $HB$ и $HO$ совпадают, значит центральный угол $MHO$ и меньшая дуга $MO$ равны $2^\\circ$.',
      ],
      [
        'В четырёхугольник $NTCH$ вписана окружность, $NT=85$, $CH=81$. Найдите периметр четырёхугольника $NTCH$.',
        ['332'],
        'У четырёхугольника, описанного около окружности, суммы длин противоположных сторон равны: $NT+CH=TC+HN$. Поэтому $P=2(NT+CH)=2(85+81)=332$.',
      ],
      [
        'Угол $O$ четырёхугольника $OANK$, вписанного в окружность, равен $47^\\circ$. Найдите угол $N$ этого четырёхугольника. Ответ дайте в градусах.',
        ['133'],
        'Сумма противоположных углов вписанного четырёхугольника равна $180^\\circ$. Поэтому $\\angle N=180^\\circ-47^\\circ=133^\\circ$.',
      ],
      [
        'Два угла четырёхугольника, вписанного в окружность, равны $168^\\circ$ и $3^\\circ$. Найдите меньший из двух оставшихся углов. Ответ дайте в градусах.',
        ['12'],
        'Данные углы не противоположные, так как их сумма не равна $180^\\circ$. Противоположные им углы равны $180^\\circ-168^\\circ=12^\\circ$ и $180^\\circ-3^\\circ=177^\\circ$. Меньший из них равен $12^\\circ$.',
      ],
    ];

    $qi = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,NULL,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $i => [$text, $correct, $solution]) {
      $qi->execute([$hwId, $text, json_encode($correct, JSON_UNESCAPED_UNICODE), $solution, $i]);
    }
  },

  // v51 — прикрепляем к 20 заданиям по планиметрии соответствующие схемы,
  // заранее загруженные в приватный S3.
  51 => function (PDO $pdo, string $driver) {
    $hwSt = $pdo->prepare(
      "SELECT h.id
       FROM homeworks h
       JOIN lessons l ON l.id = h.lesson_id
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       WHERE c.title = 'Летняя школа по Профильной Математике'
         AND l.title = 'Планиметрия с нуля'
         AND h.title = 'Планиметрия с нуля (база)'
       ORDER BY h.id DESC LIMIT 1"
    );
    $hwSt->execute();
    $hwId = (int)($hwSt->fetchColumn() ?: 0);
    if (!$hwId) throw new RuntimeException('Не найдено ДЗ «Планиметрия с нуля (база)» для прикрепления схем');

    $qSt = $pdo->prepare("SELECT id, position FROM questions WHERE homework_id = ? ORDER BY position, id");
    $qSt->execute([$hwId]);
    $questions = $qSt->fetchAll();
    if (count($questions) !== 20) {
      throw new RuntimeException('В ДЗ по планиметрии ожидалось 20 вопросов, найдено: ' . count($questions));
    }

    $existsSt = $pdo->prepare("SELECT 1 FROM question_attachments WHERE question_id = ? AND kind = 'image' LIMIT 1");
    $insertSt = $pdo->prepare(
      "INSERT INTO question_attachments (question_id, kind, name, size, url, position)
       VALUES (?,'image',?,NULL,?,0)"
    );
    foreach ($questions as $i => $question) {
      $qid = (int)$question['id'];
      $existsSt->execute([$qid]);
      if ($existsSt->fetchColumn()) continue;
      $number = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
      $key = 'questions/math-summer-2026-block-2/' . $number . '.jpg';
      $insertSt->execute([
        $qid,
        'Схема к заданию ' . ($i + 1),
        '/api/file.php?path=' . rawurlencode($key),
      ]);
    }
  },

  // v52 — базовое ДЗ к третьему блоку летней школы по математике:
  // «Уравнения с нуля», 8 заданий с автопроверкой и решениями в LaTeX.
  52 => function (PDO $pdo, string $driver) {
    $blockSt = $pdo->prepare(
      "SELECT b.id
       FROM blocks b
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND c.title = 'Летняя школа по Профильной Математике'
         AND (b.n = 3 OR b.title = 'Уравнения')
       ORDER BY b.id DESC LIMIT 1"
    );
    $blockSt->execute();
    $blockId = (int)($blockSt->fetchColumn() ?: 0);
    if (!$blockId) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses WHERE title = 'Летняя школа по Профильной Математике'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден третий блок «Уравнения»');
      return;
    }

    $lessonSt = $pdo->prepare(
      "SELECT id FROM lessons
       WHERE block_id = ? AND (title = 'Уравнения с нуля' OR title LIKE '%Уравнен%')
       ORDER BY id DESC LIMIT 1"
    );
    $lessonSt->execute([$blockId]);
    $lid = (int)($lessonSt->fetchColumn() ?: 0);
    if (!$lid) {
      $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM lessons WHERE block_id = ?");
      $posSt->execute([$blockId]);
      $pdo->prepare("INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, position, description) VALUES (?,?,?,?,?,?,?,?,?,?)")
          ->execute([$blockId, 'practice', 'Уравнения с нуля', null, '', '', 0, null, (int)$posSt->fetchColumn(), '']);
      $lid = (int)$pdo->lastInsertId();
    }

    $title = 'Уравнения с нуля (база)';
    $hwSt = $pdo->prepare("SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? LIMIT 1");
    $hwSt->execute([$lid, $title]);
    $hwId = (int)($hwSt->fetchColumn() ?: 0);
    if (!$hwId) {
      // Если администратор уже успел создать пустой базовый уровень, используем его,
      // чтобы не плодить две одинаковые карточки.
      $blankSt = $pdo->prepare(
        "SELECT h.id FROM homeworks h
         WHERE h.lesson_id = ? AND h.level = 'normal'
           AND h.title IN ('Базовый уровень','Уравнения с нуля (база)')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY h.id DESC LIMIT 1"
      );
      $blankSt->execute([$lid]);
      $hwId = (int)($blankSt->fetchColumn() ?: 0);
      if ($hwId) {
        $pdo->prepare("UPDATE homeworks SET title = ? WHERE id = ?")->execute([$title, $hwId]);
      } else {
        $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?");
        $posSt->execute([$lid]);
        $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
            ->execute([$lid, 'normal', $title, '', (int)$posSt->fetchColumn()]);
        $hwId = (int)$pdo->lastInsertId();
      }
    } else {
      $countSt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
      $countSt->execute([$hwId]);
      if ((int)$countSt->fetchColumn() > 0) return;
    }

    // [условие, допустимые ответы, решение]
    $qs = [
      [
        'Найдите корень уравнения $$(x-1)^3=8.$$',
        ['3'],
        'Представим правую часть как куб: $8=2^3$. Тогда $(x-1)^3=2^3$, откуда $x-1=2$ и $x=3$.',
      ],
      [
        'Найдите корень уравнения $$(x+7)^7=1.$$',
        ['-6'],
        'Представим $1$ как $1^7$. Поскольку степень нечётная, из $(x+7)^7=1^7$ получаем $x+7=1$. Следовательно, $x=-6$.',
      ],
      [
        'Найдите корень уравнения $$\\frac{1}{2x+7}=5.$$',
        ['-3,4', '-3.4', '-17/5'],
        'Учтём ОДЗ: $2x+7\\ne0$. Умножим обе части на $2x+7$: $1=5(2x+7)=10x+35$. Тогда $10x=-34$ и $x=-\\dfrac{17}{5}=-3{,}4$. Корень удовлетворяет ОДЗ.',
      ],
      [
        'Найдите корень уравнения $$\\frac{1}{4x+9}=\\frac{1}{6x+12}.$$',
        ['-1,5', '-1.5', '-3/2'],
        'На ОДЗ знаменатели не равны нулю. Приравняем их: $4x+9=6x+12$. Получаем $-3=2x$, поэтому $x=-\\dfrac32=-1{,}5$. Оба знаменателя при этом ненулевые.',
      ],
      [
        'Найдите корень уравнения $$\\sqrt{x+28}=12.$$',
        ['116'],
        'Возведём обе части в квадрат: $x+28=12^2=144$. Отсюда $x=144-28=116$. Проверка: $\\sqrt{116+28}=\\sqrt{144}=12$.',
      ],
      [
        'Найдите корень уравнения $$\\sqrt{6x+19}=5.$$',
        ['1'],
        'Возведём обе части в квадрат: $6x+19=25$. Тогда $6x=6$ и $x=1$. Проверка: $\\sqrt{6\\cdot1+19}=\\sqrt{25}=5$.',
      ],
      [
        'Найдите корень уравнения $$2^{-4-x}=16.$$',
        ['-8'],
        'Представим $16$ как $2^4$. Основания равны, значит равны показатели: $-4-x=4$. Отсюда $-x=8$ и $x=-8$.',
      ],
      [
        'Найдите корень уравнения $$3^{x+5}=27.$$',
        ['-2'],
        'Представим $27$ как $3^3$. Основания равны, поэтому $x+5=3$. Следовательно, $x=-2$.',
      ],
    ];

    $qi = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,NULL,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $i => [$text, $correct, $solution]) {
      $qi->execute([$hwId, $text, json_encode($correct, JSON_UNESCAPED_UNICODE), $solution, $i]);
    }
  },

  // v53 — двойные $$ внутри строки оставляли один служебный знак на экране.
  // Для коротких уравнений используем корректную inline-разметку $...$.
  53 => function (PDO $pdo, string $driver) {
    $hwSt = $pdo->prepare(
      "SELECT h.id
       FROM homeworks h
       JOIN lessons l ON l.id = h.lesson_id
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       WHERE c.title = 'Летняя школа по Профильной Математике'
         AND l.title = 'Уравнения с нуля'
         AND h.title = 'Уравнения с нуля (база)'
       ORDER BY h.id DESC LIMIT 1"
    );
    $hwSt->execute();
    $hwId = (int)($hwSt->fetchColumn() ?: 0);
    if (!$hwId) return;

    $texts = [
      'Найдите корень уравнения $(x-1)^3=8$.',
      'Найдите корень уравнения $(x+7)^7=1$.',
      'Найдите корень уравнения $\\dfrac{1}{2x+7}=5$.',
      'Найдите корень уравнения $\\dfrac{1}{4x+9}=\\dfrac{1}{6x+12}$.',
      'Найдите корень уравнения $\\sqrt{x+28}=12$.',
      'Найдите корень уравнения $\\sqrt{6x+19}=5$.',
      'Найдите корень уравнения $2^{-4-x}=16$.',
      'Найдите корень уравнения $3^{x+5}=27$.',
    ];
    $upd = $pdo->prepare("UPDATE questions SET text = ? WHERE homework_id = ? AND position = ?");
    foreach ($texts as $position => $text) $upd->execute([$text, $hwId, $position]);
  },

  // v54 — личное файловое пространство ученика «Стобалльный диск».
  // Папки виртуальные (дерево хранится в БД), сами объекты лежат в приватном S3.
  // drive_quotas хранит только купленное место: бесплатный лимит считается
  // динамически по числу сданных домашних заданий.
  54 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS drive_items (
      id {PK},
      user_id INT NOT NULL,
      parent_id INT,
      kind VARCHAR(10) DEFAULT 'file',
      name VARCHAR(240) NOT NULL,
      storage_key VARCHAR(500),
      mime VARCHAR(160),
      size_bytes BIGINT DEFAULT 0,
      created_at {TS},
      updated_at VARCHAR(30),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS drive_quotas (
      user_id INT PRIMARY KEY,
      bonus_bytes BIGINT DEFAULT 0,
      updated_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $exists = $pdo->prepare("SELECT id FROM shop_items WHERE name = ? LIMIT 1");
    $exists->execute(['Стобалльный диск +1 ГБ']);
    if (!$exists->fetchColumn()) {
      $position = (int)$pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM shop_items")->fetchColumn();
      $pdo->prepare("INSERT INTO shop_items (name, description, category, price, once_only, badge, active, position)
                     VALUES (?,?,?,?,?,?,1,?)")
          ->execute(['Стобалльный диск +1 ГБ', 'Постоянно добавляет 1 ГБ. Можно покупать несколько раз', 'privilege', 1200, 0, 'ДИСК', $position]);
    }
  },

  // v55 — старые занятия могли получить «пройдено» за открытие/просмотр или
  // другое ДЗ, даже когда базовое ДЗ ещё не было сдано.
  55 => function (PDO $pdo, string $driver) {
    $rows = $pdo->query("SELECT lp.user_id, lp.lesson_id
      FROM lesson_progress lp")->fetchAll();
    $pending = $pdo->prepare("SELECT 1 FROM homeworks h
      WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
        AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.homework_id = h.id AND s.user_id = ?)
      LIMIT 1");
    $del = $pdo->prepare("DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?");
    foreach ($rows as $row) {
      $uid = (int)$row['user_id']; $lessonId = (int)$row['lesson_id'];
      $pending->execute([$lessonId, $uid]);
      if ($pending->fetchColumn()) $del->execute([$uid, $lessonId]);
    }
  },

  // v56 — входная диагностика к первому блоку летней школы по русскому языку.
  // Используем последнюю базовую домашку, которую администратор уже создал в этом
  // блоке, и заменяем её черновые вопросы полным вариантом из 26 заданий.
  56 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->prepare(
      "SELECT l.id
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Русский язык'
         AND c.title LIKE '%Летн%'
         AND (b.n = 1 OR b.position = 0)
       ORDER BY (SELECT COUNT(*) FROM homeworks h WHERE h.lesson_id = l.id) DESC,
                l.position, l.id DESC
       LIMIT 1"
    );
    $lessonSt->execute();
    $lessonId = (int)($lessonSt->fetchColumn() ?: 0);
    if (!$lessonId) {
      $hasCourse = $pdo->prepare("SELECT 1 FROM courses c JOIN subjects s ON s.id = c.subject_id WHERE s.name = 'Русский язык' AND c.title LIKE '%Летн%' LIMIT 1");
      $hasCourse->execute();
      if ($hasCourse->fetchColumn()) throw new RuntimeException('Не найден первый блок летней школы по русскому языку');
      return;
    }

    $description = 'Входная диагностика по формату первой части ЕГЭ: 26 заданий на анализ текста, орфоэпию, лексику, грамматику, орфографию, пунктуацию и средства выразительности. Реши вариант самостоятельно — после отправки система сразу проверит ответы и покажет разбор.';
    $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ?")->execute([$description, $lessonId]);

    $hwSt = $pdo->prepare(
      "SELECT id FROM homeworks
       WHERE lesson_id = ? AND (level = 'normal' OR level IS NULL OR level = '')
       ORDER BY id DESC LIMIT 1"
    );
    $hwSt->execute([$lessonId]);
    $hwId = (int)($hwSt->fetchColumn() ?: 0);
    $title = 'Входная диагностика: задания 1–26';
    if (!$hwId) {
      $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?");
      $posSt->execute([$lessonId]);
      $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
          ->execute([$lessonId, 'normal', $title, '', (int)$posSt->fetchColumn()]);
      $hwId = (int)$pdo->lastInsertId();
    } else {
      $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $hwId]);
      $qIdsSt = $pdo->prepare("SELECT id FROM questions WHERE homework_id = ?");
      $qIdsSt->execute([$hwId]);
      $qIds = array_map('intval', $qIdsSt->fetchAll(PDO::FETCH_COLUMN));
      if ($qIds) {
        $ph = implode(',', array_fill(0, count($qIds), '?'));
        $pdo->prepare("DELETE FROM question_attachments WHERE question_id IN ($ph)")->execute($qIds);
        $pdo->prepare("DELETE FROM answer_uploads WHERE question_id IN ($ph)")->execute($qIds);
        $pdo->prepare("DELETE FROM answers WHERE question_id IN ($ph)")->execute($qIds);
        $pdo->prepare("DELETE FROM questions WHERE id IN ($ph)")->execute($qIds);
      }
    }

    $firstPassage = <<<'TXT'
**Текст для заданий 1–3**

Больше всего в людях раздражает отсутствие простоты.

В этих людях, считавших себя близкими к литературе, поражало обилие предрассудков, высокомерие, отсутствие любопытства ко всему, не относившемуся к их профессиональным интересам.

О таких превосходных вещах, как дождь или собирание грибов, с ними можно говорить не всерьёз. Всё это для них темы третьего сорта, вызывающие лишь недоумевающий взгляд. Первостепенными считаются разговоры о шумных, но совершенно неинтересных театральных премьерах.

Бесполезная, никому не интересная жизнь. Невесёлая судьба человека, который ни разу не испытал незабываемую теплоту земли на босых ногах, не промокал насквозь под дождём в чаще орешника. А если и испытал всё это, то недооценил, недопонял этой недюжинной силы простоты и страдал, как от протекающего потолка или тесной обуви. Таким людям неуютно в природе. У них нет непоколебимой веры в счастье и чудо, сказку они проходят с открытыми, но совершенно невидящими глазами мимо никогда не виданного чуда. Глубинная прелесть жизни не измерена и не понята ими.

Большинство из них живут `<…>` чужого ума, таланта, чужих ошибок и успехов, но считают, что без них, без их неустанного вмешательства не существовало бы ни настоящей литературы, ни поэзии, ни театра.

Им не под силу понять, что лучше внести в жизнь крупинку своего, собственного, как бы ни была она мала, чем суетиться у чужих верстаков, не сделав ничего важного в жизни.

*(По К. Г. Паустовскому)*
TXT;

    $secondPassage = <<<'TXT'
**Текст для заданий 23–26**

(1) Когда на лекциях я спрашиваю: а чем, собственно говоря, дети отличаются от взрослых, — незамедлительно получаю ответ: «Опытом!»

(2) Взрослые убеждены, что у них опыт есть, а у детей его нет. (3) А что же имеется у детей? (4) Ничего у них нет в смысле опыта. (5) Имеется у детей наивное, чаще всего нелепое поведение маленьких существ, которые не знают суровых законов нашего взрослого мира. (6) И пока существа эти не познают наши законы, в клуб взрослых их не примут.

(7) Когда мы говорим о ком-то: «Он — большой ребёнок», мы имеем в виду, как правило, что это, возможно, милый и приятный человек, но совершенно не приспособленный к нашей серьёзной, взрослой жизни. (8) Это устоявшаяся точка зрения. (9) Именно отсутствие у детей опыта и даёт нам твёрдые основания воспитывать детей. (10) Увы, многие взрослые понимают воспитание именно как навязывание ребёнку собственного опыта. (11) Наш опыт — это опыт жизни в нашем мире. (12) Свой опыт мы уважаем, принимаем. (13) Поведение детей мы расцениваем как неразумное и глупое лишь потому, что оно не соответствует опыту нашей жизни.

(14) Однако если у детей своя жизнь, не разумно ли предположить, что у них свой опыт? (15) Вот как вспоминает детство замечательный современный поэт Сергей Трофимов:

(16) У меня под майкой голубок белый, / Я с утра сменял его на свой велик. (17) Ох, и будет дома нагоняй, только / Нет меня счастливей в эти дни.

(18) С точки зрения взрослого человека, поступок, мягко говоря, нелепый: сменять велосипед на голубя. (19) Велосипед дороже голубя, потому что стоит денег. (20) Логика понятна. (21) С точки зрения ребёнка, живой, трепещущий голубь гораздо интереснее, чем механический велосипед. (22) Голубь дороже велосипеда, потому что живой. (23) Непонятная логика. (24) Логика взрослых нам ближе и ясней. (25) Но можно ли с уверенностью утверждать, что в данном случае в поведении ребёнка нет совсем уж никакой логики? (26) Есть. (27) Только она нам неблизка и непонятна.

(28) Уверен, что мы все признаём: мир, который построили взрослые, мягко говоря, несовершенен. (29) И тем не менее в клуб взрослых принимают только тех, кто понял законы этого мира — небезупречные, часто самоубийственные, нередко циничные...

(30) Итак, какова же позиция у нас, взрослых? (31) Она элементарно проста. (32) Дети должны учиться у нас. (33) Мы должны учить детей. (34) Мы должны их воспитывать, передавая собственный опыт. (35) Худо ли, бедно ли создали мы свой мир, нашим детям жить в нём, и они должны подчиняться его законам. (36) О том, чтобы взрослым учиться у детей, речи нет.

(37) Но разве детские навыки, их опыт не могут пригодиться и нам? (38) Скажем, их опыт взаимоотношения с природой? (39) Их навыки верить в предлагаемые обстоятельства, которые мы высокомерно называем игрой? (40) Или их бескомпромиссность?

(41) Дети не любят выискивать вторые смыслы. (42) Они гораздо конкретнее взрослых, и такая конкретность делает общение с ними более понятным и, если угодно, более приятным.

(43) Мальчика спросили: (44) — Кем ты хочешь быть, когда вырастешь? (45) Мальчик ответил: (46) — Волшебником. (47) Все расхохотались. (48) Мальчик удивился: (49) — Когда я вырасту, я, скорее всего, буду работать в банке, как папа. (50) Но вы же спросили, кем я хочу быть! (51) Казалось бы, такой манере общения не грех бы и поучиться...

(52) Дети воспринимают мир в чёрно-белых цветах. (53) Они делят мир на своих и чужих. (54) Скажем, когда в споре ученика с учителем родитель встаёт на сторону учителя — он сразу становится для ребёнка чужим. (55) И учителя ребёнок оценивает именно так: свой или чужой, понимающий или непонимающий... (56) Делить мир на своих и чужих? (57) Не замечать оттенков? (58) В каждом вновь встреченном видеть своего или чужого? (59) Да, в нашем взрослом мире это всё не работает. (60) Мы понимаем про оттенки. (61) Мы считаем нормальным манипулировать людьми, а то и использовать их. (62) Всё так. (63) Мы построили такой мир и такую жизнь.

(64) Для детей общение со взрослыми естественно, для взрослых общение с детьми — это некий процесс, требующий едва ли не усиленной теоретической подготовки. (65) Мы к этому привыкли. (66) И воспринимаем как норму... (67) Поверьте, я далёк от того, чтобы идеализировать детей. (68) Дети — это люди. (69) И как люди любого возраста, они бывают добрые и злые, более циничные и менее, умные и не очень... (70) Они — разные. (71) Но я против того, чтобы считать, будто дети отличаются от взрослых тем, что у них нет опыта. (72) Я против взрослого убеждения, что нам нечему учиться у детей. (73) У детей свой (увы, чаще всего непригодный для построенной нами жизни) опыт. (74) Но он есть. (75) Как минимум он нуждается в том, чтобы его уважали. (76) Как максимум — в том, чтобы его перенимали. (77) И если взрослый человек открыт детям, он способен многому у них научиться. (78) И многое понять в самом себе, отражаясь в зеркале детства.

*(По А. М. Максимову)*
TXT;

    $seq = static fn(string $s): array => [$s, implode(' ', str_split($s)), implode(',', str_split($s))];
    $qs = [
      [
        $firstPassage . "\n\n**Задание 1.** Самостоятельно подберите производный предлог, который должен стоять на месте пропуска в пятом абзаце текста. Запишите этот предлог.",
        ['за счёт','за счет','засчёт','засчет','посредством','с помощью','спомощью','при помощи','припомощи'],
        'По смыслу подходят производные предлоги **за счёт**, **посредством**, **с помощью**, **при помощи**.',
      ],
      [
        "**Задание 2.** Используйте текст из задания 1. Укажите варианты, в которых лексическое значение выделенного слова соответствует его значению в тексте.\n\n1. **ПРОФЕССИОНАЛЬНЫЙ** — относящийся к профессии, связанный с профессией.\n2. **ВЗГЛЯД** — мнение, суждение, оценка кого-либо или чего-либо.\n3. **СИЛА** — способность к физическим действиям, требующим значительного напряжения мышц.\n4. **ТЕАТР** — род искусства, художественное отражение жизни посредством сценического представления.\n5. **ВАЖНЫЙ** — проникнутый достоинством, высокомерием; величавый, гордый, надменный.\n\nЗапишите номера ответов подряд.",
        $seq('14'),
        'Верны варианты **1** и **4**. «Взгляд» означает направление зрения, «сила» — влияние и выразительность, «важный» — имеющий большое значение.',
      ],
      [
        "**Задание 3.** Используйте текст из задания 1. Укажите верные характеристики фрагмента.\n\n1. Эпитеты несут эмоционально-оценочную нагрузку.\n2. Цель автора — описать красоту природы.\n3. Неприятие описанных людей объясняет частое употребление НЕ, НИ и слов с НЕДО-.\n4. Метафоры, сравнение и фразеологизм помогают понять авторскую позицию.\n5. В тексте используется парцелляция.\n\nЗапишите номера ответов подряд.",
        $seq('134'),
        'Верны **1, 3, 4**. Автор рассуждает об отсутствии простоты, а парцелляции в тексте нет.',
      ],
      [
        "**Задание 4.** Укажите варианты, в которых верно выделена буква, обозначающая ударный гласный звук.\n\n1. понЯвший\n2. цепОчка\n3. зАпертый\n4. ворвАлась\n5. дОнельзя\n\nЗапишите номера ответов подряд.",
        $seq('123'),
        'Верно: **понЯвший, цепОчка, зАпертый**. Нормы: ворвалАсь, донЕльзя.',
      ],
      [
        "**Задание 5.** В одном предложении неверно употреблено выделенное слово. Исправьте ошибку, подобрав пароним. Запишите одно слово.\n\n1. Все обращали внимание на **ЦАРСТВЕННЫЙ** поворот головы и осанку баронессы.\n2. Обладатель исключительного права на товарный знак может **ПРЕДСТАВИТЬ** право на его использование другим лицам.\n3. Раковина может стать украшением интерьера или **ПАМЯТНЫМ** подарком.\n4. Дуб часто является олицетворением силы, гордости, **ВЕКОВОЙ** мудрости.\n5. Механизм регулирования глобализации должен **ОХВАТЫВАТЬ** все сферы.",
        ['предоставить'],
        'Право можно **предоставить** — передать возможность им пользоваться. «Представить» означает предъявить или показать.',
      ],
      [
        '**Задание 6.** Исправьте лексическую ошибку, заменив неверно употреблённое слово. Запишите одно слово.\n\nНевозможно добиться успеха в конном спорте, не посвящая внимания общей физической подготовке.',
        ['уделяя','времени'],
        'Верно: **не уделяя внимания** общей физической подготовке. Возможен и вариант: **не посвящая времени** подготовке.',
      ],
      [
        "**Задание 7.** В одном слове допущена грамматическая ошибка. Исправьте её и запишите слово правильно.\n\nЗАЖЖЁМ свечу; в ДЕВЯНОСТА сёлах; ситцевых ПЛАТЬЕВ; выразить более ТОЧНО; ИСПЕКЁМ торт.",
        ['испечём','испечем'],
        'Нормативная форма первого лица множественного числа — **испечём**.',
      ],
      [
        "**Задание 8.** Установите соответствие между ошибками (А–Д) и предложениями (1–9). Запишите пять цифр подряд.\n\n**А)** нарушение в построении предложения с деепричастным оборотом; **Б)** с причастным оборотом; **В)** с однородными членами; **Г)** сложного предложения; **Д)** неправильное употребление падежной формы управляемого слова.\n\n1. Будучи главой правительства, П. А. Столыпин считал, что аграрную проблему можно решить, ликвидировав общины и создав крупные фермерские крестьянские хозяйства не только в уже освоенных районах, а также на пустующих землях.\n2. Любой заповедник, являясь государственным учреждением, финансируется из федерального бюджета.\n3. Осторожно спустив на воду шлюпку, она сразу оказалась заполненной людьми.\n4. Система действовала великолепно, оборудование было в отличном состоянии, все наборы программ работали согласно плана.\n5. В. О. Ключевский писал, что «история учит даже тех, кто у неё не учится: она проучивает их за невежество и пренебрежение».\n6. Дороги — это общее наименование всех разновидностей наземных путей сообщения, предназначенное для передвижения людей, транспорта и грузов.\n7. В 1380 году объединённые силы русских князей нанесли поражение войску Мамая, доказав способность противостоять такому сильному противнику, как Золотая Орда.\n8. Один пастух заметил то, что козы, поевшие днём плодов с кофейного куста, были очень возбуждёнными.\n9. В середине августа татаро-монгольская армия вышла к притоку Дона и остановилась в ожидании союзников.",
        $seq('36184'),
        'Соответствие: **А–3, Б–6, В–1, Г–8, Д–4**.',
      ],
      [
        "**Задание 9.** Укажите ряды, в которых во всех словах пропущена одна и та же буква.\n\n1. ч..стосердечный, щ..бетанье, од..чать\n2. щ..велевый, ш..рада, ж..сминовый\n3. отв..рить (грибы), ст..рожил (деревни), исп..рение\n4. несг..раемый (сейф), к..мпонент, р..скошный\n5. ух..щрение, прим..рять (джинсы), заст..клить\n\nЗапишите номера ответов подряд.",
        $seq('234'),
        'Одинаковые буквы пропущены в рядах **2, 3, 4**.',
      ],
      [
        "**Задание 10.** Укажите ряды, в которых во всех словах пропущена одна и та же буква.\n\n1. пр..вратные представления, пр..граждать, пр..емник президента\n2. пр..домовая территория, пр..щедрый, пр..ступить к делу\n3. бе..полезный, и..царапать, и..синя-чёрный\n4. без..языкий, в..явь, из..ян\n5. сверх..дея, под..скать, от..скать\n\nЗапишите номера ответов подряд.",
        $seq('134'),
        'Верны ряды **1, 3, 4**: Е; С; Ъ соответственно.',
      ],
      [
        "**Задание 11.** Укажите ряды, в которых в обоих словах пропущена одна и та же буква.\n\n1. справ.. (от дома), половинч..тый\n2. выкорч..вать, застенч..вый\n3. кормил..ца, наста..вая\n4. эмал..вый, имень..це\n5. размаз..вать, развед..вательный\n\nЗапишите номера ответов подряд.",
        $seq('135'),
        'Верны ряды **1, 3, 5**.',
      ],
      [
        "**Задание 12.** Укажите ряды, в которых в обоих словах пропущена одна и та же буква.\n\n1. обстрел..нный, прострел..нный\n2. кле..щий карандаш, зала..вшая собака\n3. противореч..щий логике, слыш..тся звуки\n4. стел..щийся по земле туман, сугробы раста..т\n5. он гон..тся за нами, актёр наде..тся на успех\n\nЗапишите номера ответов подряд.",
        $seq('234'),
        'Верны ряды **2, 3, 4**.',
      ],
      [
        "**Задание 13.** Укажите варианты, в которых НЕ с выделенным словом пишется раздельно.\n\n1. (НЕ)ПОЛЬЗУЮЩИЙСЯ особым расположением Семён.\n2. Встреча оказалась (НЕ)СЛУЧАЙНОЙ.\n3. Никто (НЕ)ОБЯЗАН руководствоваться мнением товарища.\n4. Молодые люди говорили (НЕ)ПО-РУССКИ.\n5. Все молодые люди стремятся к (НЕ)ЗАВИСИМОСТИ.\n\nЗапишите номера ответов подряд.",
        $seq('134'),
        'Раздельное написание: **не пользующийся**, **не обязан**, **не по-русски**.',
      ],
      [
        "**Задание 14.** Укажите варианты, в которых все выделенные слова пишутся раздельно.\n\n1. ТАК(ЖЕ), как и прежде; (ПО)СВОЕМУ усмотрению.\n2. (ОТ)ТОГО здания; (В)ДАЛИ.\n3. КАКОГО(ТО) дерева; (ПОЛ)ЯБЛОКА.\n4. (В)СЛЕДСТВИЕ засухи; (ПО)ЭТОМУ.\n5. (ЗА)СЧЁТ того; одни и ТЕ(ЖЕ) слова.\n\nЗапишите номера ответов подряд.",
        $seq('15'),
        'Раздельно пишутся все слова в вариантах **1** и **5**.',
      ],
      [
        '**Задание 15.** Укажите цифры, на месте которых пишется одна буква Н.\n\nИностра(1)ыми судьями уже были поставле(2)ы оценки, подсчита(3)ы баллы и подведе(4)ы предварительные итоги соревнований по ко(5)ому спорту.',
        $seq('234'),
        'В кратких страдательных причастиях **поставлены, подсчитаны, подведены** пишется одна Н: **2, 3, 4**.',
      ],
      [
        "**Задание 16.** Укажите предложения, в которых нужно поставить одну запятую.\n\n1. Ни улыбкой ни ласковым взглядом не одарила сегодня Анюта соседей.\n2. Ложь в истолковании прошлого приводит к провалам в настоящем а также готовит катастрофу в будущем.\n3. В южных районах плодородных земель было много и участки земли засевали в течение двух-трёх и более лет.\n4. Уже третий день ленивый и вялый дождь тоскливо и однообразно стучит в оконные стёкла.\n5. Ветер колышет кроны деревьев и по земле бегут лёгкие и прозрачные тени.\n\nЗапишите номера ответов подряд.",
        $seq('125'),
        'По одной запятой требуется в предложениях **1, 2, 5**.',
      ],
      [
        '**Задание 17.** Укажите цифры, на месте которых должны стоять запятые.\n\nНе менее прочную позицию занимали в душе Гараськи немногие истины (1) добытые путём житейского опыта (2) и (3) господствовавшие над представлениями (4) внушаемыми ему взрослыми.',
        $seq('14'),
        'Запятыми обособляются два причастных оборота после определяемых слов: позиции **1** и **4**.',
      ],
      [
        '**Задание 18.** Укажите цифры, на месте которых должны стоять запятые.\n\nСобрание Третьяковской галереи посвящено (1) исключительно (2) национальному русскому искусству, произведениям тех художников, которые (3) безусловно (4) внесли значительный вклад в историю русского искусства.',
        $seq('34'),
        '«Безусловно» — вводное слово, поэтому нужны запятые **3** и **4**. «Исключительно» — частица.',
      ],
      [
        '**Задание 19.** Укажите цифры, на месте которых должны стоять запятые.\n\nДнём основания Литературно-театрального музея (1) основу фондов (2) которого (3) составила коллекция по истории русского и западноевропейского театра (4) А. А. Бахрушина (5) считается 29 октября 1894 года (6) когда Бахрушин впервые представил свою коллекцию для публичного осмотра (7) хотя впервые Алексей Александрович показал собрание друзьям 11 июня 1894 года.',
        $seq('1567'),
        'Запятые **1, 5** выделяют придаточное определительное; **6, 7** открывают следующие придаточные части.',
      ],
      [
        '**Задание 20.** Укажите цифры, на месте которых должны стоять запятые.\n\nБлагодаря земному притяжению каждое тело (1) вблизи поверхности земли обладает пропорциональным его массе весом (2) поэтому (3) если мы роняем какой-то предмет (4) он падает.',
        $seq('234'),
        'Запятая **2** разделяет части сложного предложения, **3–4** выделяют придаточное условия.',
      ],
      [
        "**Задание 21.** Найдите предложения, в которых тире ставится по одному и тому же правилу. Запишите номера подряд.\n\n(1) В 18 километрах от Смоленска — небольшое село Талашкино. (2) На склоне пригорка стоит развесёлый теремок: теремок — творение художника Сергея Малютина. (3) Расписные двери открывают небольшую комнату; рядом — коллекция кружев. (4) В следующей комнате сверкают сани, здесь же деревянные ковши и кованые лари. (5) Сочен древнерусский орнамент росписи — силён орнамент царства природы. (6) «В Талашкине, — писал художник С. Маковский, — удалось возродить всё великолепие былой действительности». (7) Создатель музея Мария Тенишева вдохнула жизнь в симфонию, ноты которой — расписные балалайки, резные складни, шитьё уборов и кружево узоров — казались утерянными.",
        $seq('13'),
        'В предложениях **1** и **3** тире стоит в неполном предложении на месте пропущенного сказуемого.',
      ],
      [
        "**Задание 22.** Установите соответствие между примерами (А–Д) и средствами выразительности (1–9). Запишите пять цифр подряд.\n\n**А)** Светает. / Всё шире разверзается неба рот.\n**Б)** Ни кровью, ни грубостью праздников, / Ни безводьем духовных рек, / Ни кощунством, ни безобразием / Победить не властен наш век.\n**В)** Труба бубнит, бьют в барабан, и флейта / Свистит...\n**Г)** Пусть безумствует море и хлещет, / Гребни волн поднялись в небеса.\n**Д)** Мне дело — измена, мне имя — Марина.\n\n1. гипербола; 2. антитеза; 3. вопросно-ответная форма; 4. многосоюзие; 5. метафора; 6. синтаксический параллелизм; 7. риторический вопрос; 8. аллитерация; 9. сравнение.",
        $seq('54816'),
        'Соответствие: **А–5 (метафора), Б–4 (многосоюзие), В–8 (аллитерация), Г–1 (гипербола), Д–6 (синтаксический параллелизм)**. В приложенном файле решений для этого задания напечатан ошибочный ключ `49126`; здесь используется разбор по самим примерам.',
      ],
      [
        $secondPassage . "\n\n**Задание 23.** Какие высказывания соответствуют содержанию текста?\n\n1. Автор идеализирует детей и уничижительно относится ко взрослым.\n2. Взрослый человек при желании способен многому научиться у детей.\n3. Дети отличаются от взрослых прежде всего тем, что у них нет опыта.\n4. Особенность детского мировосприятия — стремление делить людей на своих и чужих.\n5. Автор уверен, что современное общество несовершенно.\n\nЗапишите номера ответов подряд.",
        $seq('245'),
        'Содержанию текста соответствуют высказывания **2, 4, 5**.',
      ],
      [
        $secondPassage . "\n\n**Задание 24.** Какие утверждения являются верными?\n\n1. В предложениях 4–5 содержится ответ на вопрос из предложения 3.\n2. В предложении 13 указана причина того, о чём говорится в предложении 12.\n3. Предложения 43–47 содержат повествование.\n4. Предложения 43–51 иллюстрируют мысль из предложений 41–42.\n5. В предложениях 67–70 представлено описание.\n\nЗапишите номера ответов подряд.",
        $seq('134'),
        'Верны утверждения **1, 3, 4**.',
      ],
      [
        '**Задание 25.** Из предложений 21–24 выпишите фразеологизм.\n\n(21) С точки зрения ребёнка, живой, трепещущий голубь гораздо интереснее, чем механический велосипед. (22) Голубь дороже велосипеда, потому что живой. (23) Непонятная логика. (24) Логика взрослых нам ближе и ясней.',
        ['с точки зрения','сточкизрения'],
        'Фразеологизм — **с точки зрения**.',
      ],
      [
        '**Задание 26.** Среди предложений 7–14 найдите предложение, связанное с предыдущим с помощью сочинительного союза и форм слов.\n\n(7) Когда мы говорим о ком-то: «Он — большой ребёнок», мы имеем в виду, как правило, что это милый и приятный человек, но не приспособленный к взрослой жизни. (8) Это устоявшаяся точка зрения. (9) Именно отсутствие у детей опыта и даёт нам твёрдые основания воспитывать детей. (10) Увы, многие взрослые понимают воспитание как навязывание ребёнку собственного опыта. (11) Наш опыт — это опыт жизни в нашем мире. (12) Свой опыт мы уважаем, принимаем. (13) Поведение детей мы расцениваем как неразумное и глупое лишь потому, что оно не соответствует опыту нашей жизни. (14) Однако если у детей своя жизнь, не разумно ли предположить, что у них свой опыт?',
        ['14'],
        'Это предложение **14**: союз «однако» и формы слов «детей — у детей».',
      ],
    ];

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,NULL,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $position => [$text, $correct, $solution]) {
      $insert->execute([$hwId, $text, json_encode($correct, JSON_UNESCAPED_UNICODE), $solution, $position]);
    }
  },

  // v57 — резервирование места для прямой загрузки крупных файлов в S3.
  // pending-файл учитывается в квоте, но не показывается до подтверждения HEAD.
  57 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'drive_items', 'upload_status')) {
      $pdo->exec("ALTER TABLE drive_items ADD COLUMN upload_status VARCHAR(12) DEFAULT 'ready'");
    }
    $pdo->exec("UPDATE drive_items SET upload_status = 'ready' WHERE upload_status IS NULL OR upload_status = ''");
  },

  // v58 — ДЗ к уроку «Что такое ЕГЭ по русскому? Блоки, баллы, инструкции» (урок 1, 2-й блок русского).
  // 8 вопросов с выбором ответа из PDF «Домашка»; ключи из файла решений. Логика в ru_ege_intro_seed()
  // (умный поиск урока по названию, идемпотентно). Печатает в лог, какой урок найден и создано ли ДЗ,
  // либо — если урок не найден — список русских уроков в базе, чтобы сразу увидеть их реальные названия.
  58 => function (PDO $pdo, string $driver) {
    $r = ru_ege_intro_seed($pdo);
    if (!empty($r['created'])) {
      out("  + ДЗ «Что такое ЕГЭ по русскому?…» создано у урока «{$r['lesson']['lesson_title']}» (курс «{$r['lesson']['course_title']}»); вопросов: {$r['count']}");
    } elseif (!empty($r['found'])) {
      out("  = ДЗ уже есть у урока «{$r['lesson']['lesson_title']}» (курс «{$r['lesson']['course_title']}») — пропуск");
    } else {
      out("  ! Урок для ДЗ не найден по названию. Русские уроки в базе:");
      foreach (($r['candidates'] ?? []) as $c) {
        out("      курс «{$c['course']}» / блок {$c['block_n']} / урок «{$c['lesson']}»");
      }
      if (empty($r['candidates'])) out("      (русских курсов/уроков в базе не найдено)");
      out("  ! Пришли точное название нужного урока — подстроим поиск под него.");
    }
  },

  // v59 — ДЗ к 3-му блоку английской летней школы «Качаем аудирование».
  // Базовый: 6 отдельных True / False / Not stated. Средний: 2 полных задания ЕГЭ.
  // Если админ уже создал пустые ДЗ с такими названиями, заполняем их; заполненные не трогаем.
  59 => function (PDO $pdo, string $driver) {
    $lesson = $pdo->query("SELECT id FROM lessons WHERE title = 'Качаем аудирование' ORDER BY id LIMIT 1")->fetch();
    if (!$lesson) { out('  ! Урок «Качаем аудирование» не найден — английские ДЗ пропущены'); return; }
    $lessonId = (int)$lesson['id'];

    $ensureHomework = function (string $level, string $title, array $questions) use ($pdo, $lessonId): string {
      $find = $pdo->prepare("SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1");
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);
      if ($homeworkId <= 0) {
        $position = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = $lessonId")->fetchColumn();
        $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
            ->execute([$lessonId, $level, $title, '', $position]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
      $count = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
      $count->execute([$homeworkId]);
      if ((int)$count->fetchColumn() > 0) return 'exists';

      $insert = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
      );
      foreach ($questions as $position => [$type, $text, $options, $correct, $solution, $hint]) {
        $insert->execute([
          $homeworkId, $type, $text,
          $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
          json_encode($correct, JSON_UNESCAPED_UNICODE),
          $solution, $hint, $position,
        ]);
      }
      return 'created';
    };

    $choices = ['1 — True', '2 — False', '3 — Not stated'];
    $baseHint = "Прослушай фрагмент целиком и сравни смысл утверждения с тем, что сказали спикеры.\n\n1 — True: утверждение подтверждается.\n2 — False: в записи сказано обратное.\n3 — Not stated: нужной информации в записи нет.";
    $base = [
      ['single', 'Many tourists visit the Isle of Mull in summer.', $choices, [0],
        'True. В записи сказано: “During the summer months it’s a popular tourist destination.” Popular tourist destination — популярное среди туристов место, значит летом на остров приезжает много путешественников.', $baseHint],
      ['single', "Picnics are forbidden in St James’s Park.", $choices, [1],
        'False. В записи сказано: “Sure, but you should follow certain rules.” Пикники разрешены, но посетителям нужно соблюдать правила. Утверждение сообщает обратное.', $baseHint],
      ['single', 'Miranda is an athlete.', $choices, [2],
        'Not stated. В диалоге говорится об учёбе Миранды и университетской ярмарке. Информации о том, занимается ли она спортом, нет.', $baseHint],
      ['single', 'Bob’s next biology class is on Thursday.', $choices, [0],
        'True. Боб прямо говорит, что следующее занятие по биологии состоится в четверг: “Actually, it’s on Thursday.”', $baseHint],
      ['single', 'Anna sent Mike a party invitation by email.', $choices, [1],
        'False. Анна отправила приглашение обычной почтой, а по электронной почте сообщила об отмене вечеринки: “I sent you an invitation by post and then cancelled it by email.”', $baseHint],
      ['single', 'Jake has an elder brother.', $choices, [2],
        'Not stated. Джейк говорит, что будет помогать брату после операции, но в записи не сказано, старший это брат или младший.', $baseHint],
    ];

    $mediumHint = "Запиши семь цифр подряд без пробелов и дополнительных знаков. Для каждого утверждения A–G используй: 1 — True, 2 — False, 3 — Not stated.";
    $medium = [
      ['text', "Прослушай диалог и определи, какие утверждения соответствуют его содержанию (1 — True), какие не соответствуют (2 — False), а о чём в тексте не сказано (3 — Not stated). Запиши семь цифр подряд.\n\nA. Jake succeeded in his school-leaving exams.\nB. Jake has an elder brother.\nC. Jake wants to teach the subject Miss Clark teaches.\nD. Miss Clark is surprised with Jake’s career choice.\nE. Jake doesn’t believe in the abilities of every student.\nF. Miss Clark thinks Jake’s made the right choice.\nG. Miss Clark isn’t happy to hear Jake’s words.", null, ['1331212'],
        "Ответ: 1331212.\n\nA — True: “You worked so hard during the academic year and you passed your exams with flying colours.”\nB — Not stated: Джейк упоминает брата после операции, но его возраст не указан.\nC — Not stated: Джейк хочет стать учителем, но предмет ни он, ни мисс Кларк не называют.\nD — True: “Really? I would never have thought you had chosen this career.”\nE — False: Джейк, наоборот, верит в потенциал каждого ученика.\nF — True: мисс Кларк говорит, что преподавание — определённо его призвание.\nG — False: она объясняет, что всегда рада слышать такие слова.", $mediumHint],
      ['text', "Прослушай диалог и определи, какие утверждения соответствуют его содержанию (1 — True), какие не соответствуют (2 — False), а о чём в тексте не сказано (3 — Not stated). Запиши семь цифр подряд.\n\nA. Miranda is an athlete.\nB. This conversation is happening on St. Valentine’s Day.\nC. Eric and Miranda are boyfriend and girlfriend.\nD. Miranda wants to sell a table at the fair.\nE. Miranda studies Physics at the university.\nF. Eric wants to buy earrings for Jennifer.\nG. Eric will buy a cup of coffee for Miranda.", null, ['3222113'],
        "Ответ: 3222113.\n\nA — Not stated: о занятиях Миранды спортом ничего не сказано.\nB — False: День святого Валентина будет только на следующей неделе.\nC — False: Дженнифер — девушка Эрика, а Миранда его подруга.\nD — False: Миранда несёт стол, чтобы продавать на нём серьги, а не сам стол.\nE — True: Миранда учится на физическом факультете и пользуется его 3D-принтерами и лазерными резаками.\nF — True: Эрик интересуется ценой серёг, потому что Дженнифер их любит.\nG — Not stated: Эрик собирается взять кофе себе; про кофе для Миранды не сказано.", $mediumHint],
    ];

    $baseResult = $ensureHomework('normal', 'Базовый уровень', $base);
    $mediumResult = $ensureHomework('medium', 'Средний уровень', $medium);
    out("  + Английский блок 3: базовый — $baseResult, средний — $mediumResult");
  },

  // v60 — ДЗ к 3-му блоку летней школы по русскому: «Задание 16».
  // 10 заданий из PDF на предложения, в которых требуется ровно одна запятая.
  // Если админ уже создал пустое ДЗ, заполняем его; заполненное ДЗ не перезаписываем.
  60 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->query(
      "SELECT l.id, l.title AS lesson_title, c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Русский язык' AND b.n = 3 AND l.title = 'Задание 16'
       ORDER BY (c.title LIKE '%Летн%') DESC, l.id
       LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) { out('  ! Русский урок блока 3 «Задание 16» не найден — ДЗ пропущено'); return; }
    $lessonId = (int)$lesson['id'];

    $homeworkId = 0;
    $find = $pdo->prepare("SELECT id FROM homeworks WHERE lesson_id = ? AND title IN ('Задание 16', 'Задание 16: одна запятая') ORDER BY id LIMIT 1");
    $find->execute([$lessonId]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId <= 0) {
      $position = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = $lessonId")->fetchColumn();
      $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
          ->execute([$lessonId, 'normal', 'Задание 16', '', $position]);
      $homeworkId = (int)$pdo->lastInsertId();
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
    $count->execute([$homeworkId]);
    if ((int)$count->fetchColumn() > 0) {
      out("  = Русский блок 3: ДЗ уже заполнено у урока «{$lesson['lesson_title']}» — пропуск");
      return;
    }

    $prompt = static function (array $sentences): string {
      $numbered = [];
      foreach ($sentences as $i => $sentence) $numbered[] = ($i + 1) . ') ' . $sentence;
      return "**Задание 16.** Укажите все предложения, в которых нужно поставить **ОДНУ** запятую. Запишите номера этих предложений подряд, без пробелов.\n\n" . implode("\n", $numbered);
    };
    $seq = static fn(string $s): array => [$s, implode(' ', str_split($s)), implode(',', str_split($s))];
    $hint = 'Сначала найди грамматические основы и ряды однородных членов. Проверь повторяющиеся и двойные союзы, части сложного предложения и сочетания определений. Выбирай только предложения, где требуется ровно одна запятая.';

    // [предложения, ответ, разбор]
    $qs = [
      [
        [
          'При погружении тела в воду она давит на него и сверху и снизу и с боков.',
          'Постарайся доказать свою верность односельчанам либо уезжай из села.',
          'Но дорог ведь не столько результат сколько накал эмоций да интерес к самой охоте.',
          'Дедушка стремился воспитать внуков гражданами и хорошими людьми и к тому же он использовал уникальные методы.',
          'К отличительным особенностям рыбы-капли можно отнести отсутствие плавательного пузыря и мускулатуры а также студенистое строение тела.',
        ],
        '345',
        "Ответ: **345**.\n\n3) Запятая ставится в двойном союзе «не столько…, сколько…».\n4) Запятая разделяет части сложносочинённого предложения.\n5) Запятая нужна перед второй частью присоединительного союза «а также». В предложении 1 нужны две запятые при повторяющемся союзе, а в предложении 2 запятая не нужна.",
      ],
      [
        [
          'Так будущие юристы прокуроры и судьи не только познают тонкости советского права но и готовятся заодно к зачётам.',
          'Там сушилось бельё и висели громадные стенды с указанием центнеров пойманной рыбы.',
          'И тогда этому человеку лучше всего и проще всего так и сделать и явиться в назначенный час.',
          'Его взрастили в тишине садов и прилавков и он попробовал алма-атинскую землю и воду и полюбил их.',
          'Курганами средней и малой величины покрыта вся территория все окрестности города Верного от прилегающих к ним Алма-атинских станиц и выселок.',
        ],
        '45',
        "Ответ: **45**.\n\n4) Запятая разделяет части сложносочинённого предложения: «его взрастили» и «он попробовал и полюбил».\n5) Запятая ставится между однородными подлежащими «территория, окрестности». В предложении 1 требуются две запятые, а в предложениях 2 и 3 запятые не нужны.",
      ],
      [
        [
          'Я ни к селу ни к городу рассказал и о пьяном господине и о прежнем моём поведении.',
          'Перед каждой поездкой в горы или на озеро Саша чувствовала необычайный прилив сил и радости и в предвкушении встречи с дорогими и любимыми местами.',
          'Гриша был не столько обижен на друга из-за ссоры сколько расстроен ни с того ни с сего случившейся размолвкой.',
          'Первый звук его голоса если и был слаб и неровен то очень недолго.',
          'В шкафах и на столе на диване и на подоконниках лежали огромные подшивки старых газет и журналов.',
        ],
        '1345',
        "Ответ: **1345**.\n\n1) Запятая разделяет пары однородных членов; сочетание «ни к селу ни к городу» — фразеологизм.\n3) Запятая ставится в двойном союзе «не столько…, сколько…».\n4) Запятая нужна перед второй частью союза «если и…, то…».\n5) Запятая разделяет попарно соединённые обстоятельства места. В предложении 2 разные ряды однородных членов, поэтому запятая не нужна.",
      ],
      [
        [
          'Какая другая беда может быть милее женскому сердцу и где вернее встретит оно участие и поддержку?',
          'На него надвигалась мощь объединённых Олегом сил и думы его были заняты не столько военной помощью сколько возможностями помешать наступлению противника.',
          'Глаз внизу вспыхнул открыто и чистым зелёным светом резко мигнул погас и снова загорелся спокойно и глубоко.',
          'Одна и та же приглушённость и серьёзность появилась и в Волином косящем глазе и в Толиной ямочке.',
          'Старшина сбросил с плеч тяжёлый набитый толом вещевой мешок и с автоматом в одной руке пошёл по меже.',
        ],
        '345',
        "Ответ: **345**.\n\n3) Запятая разделяет попарно соединённые сказуемые: «вспыхнул и мигнул, погас и загорелся».\n4) Запятая ставится при повторяющемся союзе «и в глазе, и в ямочке».\n5) Запятая нужна между одиночным определением и следующим за ним причастным оборотом: «тяжёлый, набитый толом». В предложении 1 обе части вопросительные, а в предложении 2 требуются две запятые.",
      ],
      [
        [
          'Мы собирали в лесу не только грибы но и бруснику с голубикой.',
          'Чёрное море убаюкивающе шумит и плещет.',
          'По полям и лесам ударило морозцем и по дорогам закружила метель.',
          'Он был знатоком оперы джаза и современной эстрадной музыки.',
          'Около реки лес был значительно гуще и состоял из ольхи и берёзы.',
        ],
        '134',
        "Ответ: **134**.\n\n1) Запятая ставится перед второй частью двойного союза «не только…, но и…».\n3) Запятая разделяет части сложносочинённого предложения с разными обстоятельствами.\n4) Запятая разделяет бессоюзный и союзный элементы ряда: «оперы, джаза и … музыки». В предложениях 2 и 5 запятые не нужны.",
      ],
      [
        [
          'Около самой чёрной тучи летали перекати-поле и крутились обломанные ветки.',
          'Попробовал подняться самостоятельно да не смог.',
          'И меня поразила не столько её красота сколько необыкновенное никем не виданное одиночество в глазах.',
          'Зазеленели деревья и вскоре расцвели подтопленные луга.',
          'Эти люди обладают добрым сердцем и недюжинным умом и умеют в споре отстаивать свои убеждения.',
        ],
        '24',
        "Ответ: **24**.\n\n2) Союз «да» употреблён в значении «но»: «попробовал подняться, да не смог».\n4) Запятая разделяет части сложносочинённого предложения: «деревья зазеленели» и «луга расцвели». В предложении 1 есть общее обстоятельство, в предложении 3 нужны две запятые, а в предложении 5 соединены разные ряды однородных членов.",
      ],
      [
        [
          'Попадались и деревеньки с низкими домиками под коричневыми часто до половины размётанными крышами.',
          'Всё на поляне расцветало всё широко и мягко волновалось и лоснилось под тихим дыханием летнего ветра.',
          'Александр Евгеньевич вынул из кармана свою красивую руку с ухоженными ногтями и подал её девушке.',
          'Там лес и дол видений полны.',
          'Весной все вокруг оживает и у многих людей появляются силы.',
        ],
        '12',
        "Ответ: **12**.\n\n1) Запятая ставится между одиночным определением и следующим за ним причастным оборотом: «под коричневыми, часто до половины размётанными крышами».\n2) Запятая разделяет части бессоюзного сложного предложения. В предложениях 3 и 4 однородные члены соединены одиночным «и», а в предложении 5 есть общее обстоятельство «весной».",
      ],
      [
        [
          'Рассмотрите строение семян фасоли и перца под микроскопом и выделите черты их сходства и различия.',
          'Слышны были тихое дыхание спящих в кресле родителей да треск камина.',
          'Месяц был морозный хотя и солнечный.',
          'Менее могущественные и менее обеспеченные семьи в древности не имели собственного герба.',
          'Влияние Фландрии на северные провинции существовало всегда и сравнение фламандской и голландской живописи совсем не означает их противопоставления.',
        ],
        '35',
        "Ответ: **35**.\n\n3) Между однородными определениями, соединёнными союзом «хотя и», ставится запятая: «морозный, хотя и солнечный».\n5) Запятая разделяет части сложносочинённого предложения. В остальных предложениях однородные члены соединены одиночными союзами.",
      ],
      [
        [
          'Длинная извивающаяся змеёй дорога манила своей непредсказуемостью.',
          'С А.Н. Островским молодого П.И. Чайковского сближала не только любовь к народной песне и русскому театру но и совместная работа.',
          'В сизом воздухе над лесом и полями стояли облака и тлели слабым светом.',
          'Соль можно получить естественным выпариванием из солёных вод или искусственным путём.',
          'За окном гремел гром и дул сильный ветер.',
        ],
        '12',
        "Ответ: **12**.\n\n1) Запятая ставится между одиночным определением и следующим за ним причастным оборотом: «длинная, извивающаяся змеёй дорога».\n2) Запятая нужна перед второй частью двойного союза «не только…, но и…». В предложениях 3 и 4 союз соединяет однородные члены, а в предложении 5 части сложного предложения имеют общий второстепенный член «за окном».",
      ],
      [
        [
          'Поднялся ветер и по стенам и крышам звонко застучал дождь.',
          'В пустом лесу одиноко и грустно жутко и страшно.',
          'Поворчал он однако не посмел ослушаться.',
          'В свободное время мы гуляли по парку или вели беседы у камина.',
          'Печорин не верит людям и сам страдает от этого.',
        ],
        '123',
        "Ответ: **123**.\n\n1) Запятая разделяет части сложносочинённого предложения.\n2) Запятая разделяет две пары однородных сказуемых: «одиноко и грустно, жутко и страшно».\n3) Союз «однако» имеет значение «но»: «поворчал, однако не посмел». В предложениях 4 и 5 однородные сказуемые соединены одиночными союзами.",
      ],
    ];

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    foreach ($qs as $position => [$sentences, $answer, $solution]) {
      $insert->execute([$homeworkId, $prompt($sentences), json_encode($seq($answer), JSON_UNESCAPED_UNICODE), $solution, $hint, $position]);
    }
    out("  + Русский блок 3: ДЗ «Задание 16» создано у урока «{$lesson['lesson_title']}»; вопросов: " . count($qs));
  },

  // v61 — одноразовый обзор новой функции «Стобалльный диск» только для уже существующих аккаунтов.
  // Когорта фиксируется в момент миграции: новые пользователи строку не получают и тур не увидят.
  61 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS user_feature_tours (
      id {PK},
      user_id INT NOT NULL,
      tour_key VARCHAR(80) NOT NULL,
      step INT DEFAULT 0,
      status VARCHAR(20) DEFAULT 'pending',
      eligible_at {TS},
      finished_at VARCHAR(20),
      UNIQUE (user_id, tour_key),
      FOREIGN KEY (user_id) REFERENCES users(id)
    ) {ENGINE}", $driver));

    $key = 'drive-launch-2026';
    $exists = $pdo->prepare("SELECT 1 FROM user_feature_tours WHERE user_id = ? AND tour_key = ? LIMIT 1");
    $insert = $pdo->prepare("INSERT INTO user_feature_tours (user_id, tour_key, step, status) VALUES (?,?,0,'pending')");
    $added = 0;
    foreach ($pdo->query("SELECT id FROM users") as $user) {
      $uid = (int)$user['id'];
      $exists->execute([$uid, $key]);
      if ($exists->fetchColumn()) continue;
      $insert->execute([$uid, $key]);
      $added++;
    }
    out("  + Обзор Стобалльного диска доступен существующим аккаунтам: $added");
  },

  // v62 — повторно показываем исправленный обзор владельцу аккаунта «Игорь».
  62 => function (PDO $pdo, string $driver) {
    $userId = $pdo->query("SELECT id FROM users WHERE name = 'Игорь' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$userId) {
      out("  ! Аккаунт Игоря для повторного показа обзора не найден");
      return;
    }

    $reset = $pdo->prepare(
      "UPDATE user_feature_tours
       SET step = 0, status = 'pending', finished_at = NULL
       WHERE user_id = ? AND tour_key = 'drive-launch-2026'"
    );
    $reset->execute([(int)$userId]);
    out("  + Обзор Стобалльного диска сброшен для аккаунта Игоря: " . $reset->rowCount());
  },

  // v63 — базовое ДЗ к четвёртому блоку летней школы по математике:
  // «Неравенства с нуля», 8 заданий с автопроверкой, решениями и SVG-схемами.
  63 => function (PDO $pdo, string $driver) {
    $blockSt = $pdo->prepare(
      "SELECT b.id
       FROM blocks b
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND (c.title = 'Летняя школа по Профильной Математике' OR c.title LIKE 'Летняя школа по%математик%')
         AND (b.n = 4 OR b.title LIKE '%Неравен%')
       ORDER BY CASE WHEN b.n = 4 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $blockSt->execute();
    $blockId = (int)($blockSt->fetchColumn() ?: 0);
    if (!$blockId) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Профильная математика' AND c.title LIKE 'Летняя школа по%математик%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден четвёртый блок по математике');
      return;
    }

    $lessonSt = $pdo->prepare(
      "SELECT id FROM lessons
       WHERE block_id = ? AND (title = 'Неравенства с нуля' OR title LIKE '%Неравенств%')
       ORDER BY id DESC LIMIT 1"
    );
    $lessonSt->execute([$blockId]);
    $lessonId = (int)($lessonSt->fetchColumn() ?: 0);
    $description = 'Научимся решать линейные, рациональные и дробно-рациональные неравенства методом интервалов, правильно учитывать кратность корней и ОДЗ.';
    if (!$lessonId) {
      $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM lessons WHERE block_id = ?");
      $posSt->execute([$blockId]);
      $pdo->prepare("INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, position, description) VALUES (?,?,?,?,?,?,?,?,?,?)")
          ->execute([$blockId, 'practice', 'Неравенства с нуля', null, '', '', 0, null, (int)$posSt->fetchColumn(), $description]);
      $lessonId = (int)$pdo->lastInsertId();
    } else {
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Неравенства с нуля (база)';
    $hwSt = $pdo->prepare("SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? LIMIT 1");
    $hwSt->execute([$lessonId, $title]);
    $homeworkId = (int)($hwSt->fetchColumn() ?: 0);
    if (!$homeworkId) {
      $blankSt = $pdo->prepare(
        "SELECT h.id FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND h.title IN ('Базовый уровень','Неравенства с нуля (база)')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY h.id DESC LIMIT 1"
      );
      $blankSt->execute([$lessonId]);
      $homeworkId = (int)($blankSt->fetchColumn() ?: 0);
      if ($homeworkId) {
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $posSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?");
        $posSt->execute([$lessonId]);
        $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
            ->execute([$lessonId, 'normal', $title, '', (int)$posSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    } else {
      $countSt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Математика блок 4: ДЗ уже заполнено — пропуск");
        return;
      }
    }

    // [условие, допустимые варианты ответа, подробное решение]
    $questions = [
      [
        'Решите неравенство:'."\n\n".'$$3(x-4)-5(2x+1)<4+x$$',
        ['(-21/8;+∞)', '(-21/8;+inf)', 'x>-21/8', '(-2,625;+∞)', 'x>-2,625', '(-2.625;+∞)', 'x>-2.625'],
        '**Раскроем скобки и перенесём слагаемые:**'."\n\n".'$$3x-12-10x-5<4+x$$'."\n\n".'$$-8x<21$$'."\n\n".'При делении на отрицательное число знак неравенства меняется: $x>-\\dfrac{21}{8}$.'."\n\n".'**Ответ:** $\\left(-\\dfrac{21}{8};+\\infty\\right)$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$(x-2024)(x+2025)(x-2023)\\ge 0$$',
        ['[-2025;2023]∪[2024;+∞)', '[-2025;2023]U[2024;+∞)', '[-2025;2023] ∪ [2024;+∞)', '[-2025,2023]∪[2024,+∞)'],
        'Нули произведения: $x=-2025$, $x=2023$ и $x=2024$. Все множители первой степени, поэтому знак при прохождении каждой точки меняется. Нужны промежутки, на которых произведение неотрицательно; сами нули включаем.'."\n\n".'![Знаки произведения на числовой прямой](/img/math-block4/sign-2.svg)'."\n\n".'**Ответ:** $[-2025;2023]\\cup[2024;+\\infty)$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$(x+1)(3-x)(x-2)^2>0$$',
        ['(-1;2)∪(2;3)', '(-1;2)U(2;3)', '(-1;2) ∪ (2;3)', '(-1,2)∪(2,3)'],
        'Нули: $x=-1$, $x=2$, $x=3$. Корень $x=2$ имеет чётную кратность, поэтому в этой точке знак не меняется. Неравенство строгое — все три точки исключаем.'."\n\n".'![Знаки произведения на числовой прямой](/img/math-block4/sign-3.svg)'."\n\n".'**Ответ:** $(-1;2)\\cup(2;3)$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$(x^2+3)(x^2+x+7)\\ge 0$$',
        ['R', 'ℝ', 'x∈R', 'x∈ℝ', '(-∞;+∞)', 'все действительные числа'],
        'Первый множитель всегда положителен: $x^2+3>0$. Для второго множителя $D=1-28=-27<0$, а старший коэффициент положителен, значит $x^2+x+7>0$ при любом действительном $x$. Поэтому произведение всегда положительно.'."\n\n".'**Ответ:** $x\\in\\mathbb{R}$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$(x-1)^2(x+5)(x-10)^3(x+1)\\le 0$$',
        ['(-∞;-5]∪[-1;10]', '(-∞;-5]U[-1;10]', '(-∞;-5] ∪ [-1;10]', '(-inf;-5]∪[-1;10]'],
        'Критические точки: $-5$, $-1$, $1$, $10$. В точке $x=1$ кратность чётная, поэтому знак не меняется; в остальных точках — меняется. Так как неравенство нестрогое, все нули включаем.'."\n\n".'![Знаки произведения на числовой прямой](/img/math-block4/sign-5.svg)'."\n\n".'**Ответ:** $(-\\infty;-5]\\cup[-1;10]$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$\\dfrac{x^3-x^2+x-1}{x+8}\\le 0$$',
        ['(-8;1]', '(-8,1]'],
        'Разложим числитель группировкой:'."\n\n".'$$x^3-x^2+x-1=(x-1)(x^2+1)$$'."\n\n".'Так как $x^2+1>0$, знак дроби определяется множителями $x-1$ и $x+8$. Точку $x=-8$ исключаем по ОДЗ, а $x=1$ включаем.'."\n\n".'![Знаки дроби на числовой прямой](/img/math-block4/sign-6.svg)'."\n\n".'**Ответ:** $(-8;1]$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$\\dfrac{(x-4)^8(5-x)(x-52)^2(x+8)}{(x^2-4x-5)(x-1)^3(x+10)^9}\\le 0$$',
        ['(-∞;-10)∪[-8;-1)∪(1;5)∪(5;+∞)', '(-∞;-10) U [-8;-1) U (1;5) U (5;+∞)', '(-∞;-10)∪[-8;-1)∪(1;5)∪(5;+inf)'],
        'Разложим $x^2-4x-5=(x-5)(x+1)$ и учтём $5-x=-(x-5)$. После сокращения сохраняем ограничение $x\\ne5$. Также из знаменателя: $x\\ne-10,-1,1$.'."\n\n".'Чётные степени при $x=4$ и $x=52$ знак не меняют; эти точки входят в решение. Метод интервалов даёт:'."\n\n".'![Знаки дроби на числовой прямой](/img/math-block4/sign-7.svg)'."\n\n".'**Ответ:** $(-\\infty;-10)\\cup[-8;-1)\\cup(1;5)\\cup(5;+\\infty)$.',
      ],
      [
        'Решите неравенство:'."\n\n".'$$\\dfrac{(x^2-2x-3)\\sqrt{x+24}}{x^2-x-6}\\le 0$$',
        ['{-24}∪(-2;-1]', '{-24} U (-2;-1]', '{-24} ∪ (-2;-1]', '-24∪(-2;-1]', '-24;(-2;-1]'],
        'Сначала ОДЗ: $x\\ge-24$, $x\\ne-2$, $x\\ne3$. Разложим квадратные трёхчлены:'."\n\n".'$$x^2-2x-3=(x-3)(x+1),\\qquad x^2-x-6=(x-3)(x+2)$$'."\n\n".'Сокращаем $x-3$, но точка $x=3$ всё равно остаётся запрещённой. На ОДЗ исследуем знак $\\dfrac{(x+1)\\sqrt{x+24}}{x+2}$.'."\n\n".'![ОДЗ и знаки дроби на числовой прямой](/img/math-block4/sign-8-v2.svg)'."\n\n".'**Ответ:** $\\{-24\\}\\cup(-2;-1]$.',
      ],
    ];

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши множество решений промежутками. Можно использовать символ ∪ или латинскую U.';
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([$homeworkId, $text, json_encode($answers, JSON_UNESCAPED_UNICODE), $solution, $hint, $position]);
    }
    out('  + Математика блок 4: ДЗ «' . $title . '» создано; вопросов: ' . count($questions));
  },

  // v64 — новое имя исправленной SVG-схемы обходит старый браузерный кэш.
  64 => function (PDO $pdo, string $driver) {
    if ($driver === 'sqlite') {
      $update = $pdo->prepare(
        "UPDATE questions SET solution = REPLACE(solution, '/img/math-block4/sign-8.svg', '/img/math-block4/sign-8-v2.svg')
         WHERE homework_id IN (
           SELECT h.id FROM homeworks h JOIN lessons l ON l.id = h.lesson_id
           WHERE l.title = 'Неравенства с нуля' AND h.title = 'Неравенства с нуля (база)'
         ) AND solution LIKE '%/img/math-block4/sign-8.svg%'"
      );
    } else {
      $update = $pdo->prepare(
        "UPDATE questions q
         JOIN homeworks h ON h.id = q.homework_id
         JOIN lessons l ON l.id = h.lesson_id
         SET q.solution = REPLACE(q.solution, '/img/math-block4/sign-8.svg', '/img/math-block4/sign-8-v2.svg')
         WHERE l.title = 'Неравенства с нуля'
           AND h.title = 'Неравенства с нуля (база)'
           AND q.solution LIKE '%/img/math-block4/sign-8.svg%'"
      );
    }
    $update->execute();
    out('  + Ссылка на исправленную схему ОДЗ обновлена: ' . $update->rowCount());
  },

  // v65 — название существующего урока могло отличаться; старый путь уникален,
  // поэтому заменяем его без привязки к названию урока или ДЗ.
  65 => function (PDO $pdo, string $driver) {
    $update = $pdo->prepare(
      "UPDATE questions
       SET solution = REPLACE(solution, '/img/math-block4/sign-8.svg', '/img/math-block4/sign-8-v2.svg')
       WHERE solution LIKE '%/img/math-block4/sign-8.svg%'"
    );
    $update->execute();
    out('  + Старая кэшируемая ссылка на схему заменена: ' . $update->rowCount());
  },

  // v66 — диагностическое ДЗ к 4-му блоку июльской летней школы по английскому.
  // 25 вопросов с одним вариантом ответа; результат переводится в Starter/A1/A2/B1/B2/C1
  // на фронте. В исходном PDF нет содержательных картинок — только текст и водяной знак.
  66 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->query(
      "SELECT l.id, l.title AS lesson_title, b.id AS block_id, b.title AS block_title,
              m.name AS month_name, c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE '%Летн%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND b.n = 4
       ORDER BY CASE WHEN l.title LIKE '%ЕГЭ%' OR l.title LIKE '%уров%' THEN 0 ELSE 1 END,
                l.position, l.id
       LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) {
      out('  ! Английский июльский блок 4 не найден — ДЗ «Экспресс-тест на уровень английского» пропущено');
      return;
    }
    $lessonId = (int)$lesson['id'];
    $title = 'Экспресс-тест на уровень английского';

    $find = $pdo->prepare(
      "SELECT id FROM homeworks
       WHERE lesson_id = ? AND title IN ('Экспресс-тест на уровень английского','Тест на уровень')
       ORDER BY id LIMIT 1"
    );
    $find->execute([$lessonId]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId <= 0) {
      $position = (int)$pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = $lessonId")->fetchColumn();
      $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
          ->execute([$lessonId, 'normal', $title, '', $position]);
      $homeworkId = (int)$pdo->lastInsertId();
    } else {
      $pdo->prepare("UPDATE homeworks SET level = 'normal', title = ? WHERE id = ?")->execute([$title, $homeworkId]);
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
    $count->execute([$homeworkId]);
    if ((int)$count->fetchColumn() > 0) {
      out("  = Английский блок 4: ДЗ уже заполнено у урока «{$lesson['lesson_title']}» — пропуск");
      return;
    }

    $questions = [
      ['My best friend _____ in another city.', ['live','lives','living','is live'], 1,
        '**Lives**: подлежащее *my best friend* — 3-е лицо единственного числа, поэтому в Present Simple к глаголу добавляется **-s**.'],
      ['There _____ two new students in our class.', ['is','has','are','be'], 2,
        '**There are** употребляется перед существительным во множественном числе: *two new students*.'],
      ['I usually do my homework _____ the evening.', ['in','on','at','for'], 0,
        'С частями дня используется предлог **in**: *in the morning / afternoon / evening*.'],
      ['Would you like some pizza?', ['Yes, I do.','Yes, please.','I like pizza.','Yes, I like.'], 1,
        'На вежливое предложение *Would you like…?* естественный краткий ответ — **Yes, please.**'],
      ['This is Emma. _____ favourite subject is art.', ['She','Hers','Her','His'], 2,
        'Перед существительным *favourite subject* нужно притяжательное прилагательное **her**.'],
      ['Look! Mia _____ a video for the school channel.', ['records','recorded','is recording','has recording'], 2,
        'Слово **Look!** указывает на действие прямо сейчас, поэтому нужен Present Continuous: **is recording**.'],
      ['We _____ to the new skate park last Saturday.', ['go','went','have gone','are going'], 1,
        '*Last Saturday* — законченное время в прошлом, поэтому используется Past Simple: **went**.'],
      ['This game is _____ than the previous one.', ['exciting','more exciting','most exciting','the exciting'], 1,
        'После **than** нужна сравнительная степень. Для многосложного *exciting* — **more exciting**.'],
      ["I haven’t got _____ money for the concert ticket.", ['many','some','any','a'], 2,
        'В отрицательном предложении с неисчисляемым *money* употребляется **any**.'],
      ['_____ you ever tried making your own podcast?', ['Did','Do','Are','Have'], 3,
        '*Ever* в вопросе о жизненном опыте требует Present Perfect: **Have you ever tried…?**'],
      ['I _____ this series three times, and I still love it.', ['watched','have watched','am watching','have been watching'], 1,
        'Речь об опыте к настоящему моменту и количестве просмотров, поэтому **have watched**.'],
      ['If I finish the project tonight, I _____ it tomorrow.', ['upload','uploaded','will upload','would upload'], 2,
        'Первый тип условных предложений: *If + Present Simple*, затем **will + verb** — **will upload**.'],
      ['The girl _____ won the science contest is in my class.', ['which','whose','where','who'], 3,
        'Для человека, который является подлежащим придаточного, используется относительное местоимение **who**.'],
      ['My coach advised me _____ a short break.', ['take','taking','to take','took'], 2,
        'Модель **advise somebody to do something**: *advised me to take*.'],
      ["I can’t _____ with all these notifications, so I’m turning them off.", ['put up','take up','get over','look after'], 0,
        'Устойчивое сочетание **put up with** означает «мириться, терпеть».'],
      ['By the time we arrived, the concert _____.', ['already started','had already started','has already started','was already starting'], 1,
        'Концерт начался раньше другого действия в прошлом (*we arrived*), поэтому нужен Past Perfect: **had already started**.'],
      ["If I _____ the tutorial more carefully, I wouldn’t be fixing the video now.", ['followed','had followed','would follow','have followed'], 1,
        'Смешанное условное: прошлое условие с результатом сейчас. После *if* — **had followed**, в главной части — *wouldn’t be fixing now*.'],
      ['The actor is believed _____ all his own stunts in the film.', ['perform','to perform','performing','to have performed'], 3,
        'Для действия, которое произошло раньше момента сообщения, после *is believed* используется перфектный инфинитив: **to have performed**.'],
      ['I keep _____ starting my homework until it gets late.', ['putting off','taking over','bringing up','turning down'], 0,
        '**Put off** означает «откладывать». После *keep* употребляется форма с **-ing**: *keep putting off*.'],
      ['The lights are off and nobody is answering. They _____ gone home.', ['should have','must have','need have','would have'], 1,
        'Это логический вывод о прошлом по видимым признакам: **must have gone home**.'],
      ['Rarely _____ a game with such an original story.', ['I have played','have I played','I played','did I have played'], 1,
        'После отрицательного наречия **Rarely** в начале предложения нужна инверсия: **have I played**.'],
      ['Not until the final scene _____ why the character had lied.', ['I understood','had I understood','did I understand','I had understood'], 2,
        'После **Not until** в начале предложения нужна инверсия с Past Simple: **did I understand**.'],
      ['The new study _____ doubt on the claim that multitasking saves time.', ['puts','gives','casts','makes'], 2,
        'Устойчивое выражение **cast doubt on** — «поставить под сомнение». Для *the study* — **casts**.'],
      ["The influencer’s apology came _____ as completely insincere.", ['across','over','through','by'], 0,
        'Фразовый глагол **come across as** означает «производить впечатление, казаться».'],
      ['No sooner _____ the trailer than fans began discussing every detail.', ['had they released','they had released','did they release','they released'], 0,
        'После **No sooner** используется инверсия Past Perfect: **No sooner had they released… than…**.'],
    ];

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'single',?,?,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Выбери один правильный вариант. Переводчик и словарь для этого диагностического теста лучше не использовать.';
    foreach ($questions as $position => [$text, $options, $correct, $solution]) {
      $insert->execute([
        $homeworkId, $text,
        json_encode($options, JSON_UNESCAPED_UNICODE),
        json_encode([$correct], JSON_UNESCAPED_UNICODE),
        $solution, $hint, $position,
      ]);
    }
    out('  + Английский июль, блок 4: ДЗ «' . $title . '» создано у урока «' . $lesson['lesson_title'] . '»; вопросов: ' . count($questions));
  },

  // v67 — полноценная база знаний ИИ-техподдержки вместо четырёх демо-категорий.
  // Старые демонстрационные разделы удаляем точечно; любые категории, которые
  // администратор успел создать сам под другими именами, сохраняются.
  67 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/help_knowledge_v1.php';

    $legacyNames = ['Домашние задания', 'Пробники', 'Подписка и доступ', 'Технические проблемы'];
    $legacyPh = implode(',', array_fill(0, count($legacyNames), '?'));
    $legacySt = $pdo->prepare("SELECT id FROM help_categories WHERE name IN ($legacyPh)");
    $legacySt->execute($legacyNames);
    $legacyIds = array_map('intval', $legacySt->fetchAll(PDO::FETCH_COLUMN));
    if ($legacyIds) {
      $idPh = implode(',', array_fill(0, count($legacyIds), '?'));
      $pdo->prepare("DELETE FROM help_qa WHERE category_id IN ($idPh)")->execute($legacyIds);
      $pdo->prepare("DELETE FROM help_categories WHERE id IN ($idPh)")->execute($legacyIds);
    }

    $findCategory = $pdo->prepare('SELECT id FROM help_categories WHERE name = ? ORDER BY id LIMIT 1');
    $insertCategory = $pdo->prepare('INSERT INTO help_categories (name, position) VALUES (?,?)');
    $updateCategory = $pdo->prepare('UPDATE help_categories SET position = ? WHERE id = ?');
    $findQuestion = $pdo->prepare('SELECT id FROM help_qa WHERE category_id = ? AND question = ? ORDER BY id LIMIT 1');
    $insertQuestion = $pdo->prepare('INSERT INTO help_qa (category_id, question, answer, position) VALUES (?,?,?,?)');
    $updateQuestion = $pdo->prepare('UPDATE help_qa SET answer = ?, position = ? WHERE id = ?');

    $categoryCount = 0;
    $questionCount = 0;
    foreach (help_knowledge_v1() as $categoryPosition => [$categoryName, $questions]) {
      $findCategory->execute([$categoryName]);
      $categoryId = (int)($findCategory->fetchColumn() ?: 0);
      if ($categoryId <= 0) {
        $insertCategory->execute([$categoryName, $categoryPosition]);
        $categoryId = (int)$pdo->lastInsertId();
      } else {
        $updateCategory->execute([$categoryPosition, $categoryId]);
      }
      $categoryCount++;

      foreach ($questions as $questionPosition => [$question, $answer]) {
        $findQuestion->execute([$categoryId, $question]);
        $questionId = (int)($findQuestion->fetchColumn() ?: 0);
        if ($questionId > 0) $updateQuestion->execute([$answer, $questionPosition, $questionId]);
        else $insertQuestion->execute([$categoryId, $question, $answer, $questionPosition]);
        $questionCount++;
      }
    }
    out("  + База ИИ-техподдержки: $categoryCount категорий, $questionCount вопросов; старые демо-категории удалены: " . count($legacyIds));
  },

  // v68 — ДЗ к 4-му блоку июльской летней школы по русскому языку:
  // десять вариантов задания 22 на средства выразительности с автопроверкой.
  // В исходном листе у заданий 4, 5, 6, 8 и 9 таблицы терминов ошибочно
  // продублированы от соседних вариантов. Нумерацию восстанавливаем по решениям.
  68 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/ru_module4_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title, m.name AS month_name, c.title AS course_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Русский язык'
         AND c.title LIKE '%Летн%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND b.n = 4
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Русский язык' AND c.title LIKE '%Летн%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 4 летней школы по русскому языку');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare("SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id");
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/22|выразитель/u', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];

    $description = 'Разберём задание 22 ЕГЭ: научимся различать тропы, лексические и синтаксические средства выразительности в художественных примерах.';
    if (!$lesson) {
      $position = $lessons ? ((int)max(array_column($lessons, 'position')) + 1) : 0;
      $pdo->prepare(
        "INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
      )->execute([$blockId, 'theory', 'Задание 22: средства выразительности', 'Видео и тест', '', '', 0, null, $description, $position]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Задание 22: средства выразительности';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Задание 22: средства выразительности';
    $find = $pdo->prepare(
      "SELECT id FROM homeworks
       WHERE lesson_id = ? AND title IN ('Задание 22: средства выразительности','Задание 22','Базовый уровень')
       ORDER BY CASE WHEN title = 'Задание 22: средства выразительности' THEN 0 ELSE 1 END, id LIMIT 1"
    );
    $find->execute([$lessonId]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE homework_id = ?");
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Русский июль, блок 4: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
      $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")
          ->execute([$title, $homeworkId]);
    } else {
      $positionSt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?");
      $positionSt->execute([$lessonId]);
      $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)")
          ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
      $homeworkId = (int)$pdo->lastInsertId();
    }

    $makeText = static function (array $examples, array $devices): string {
      $letters = ['А', 'Б', 'В', 'Г', 'Д'];
      $left = [];
      foreach ($examples as $i => $example) $left[] = '**' . $letters[$i] . ')** ' . $example;
      $right = [];
      foreach ($devices as $i => $device) $right[] = ($i + 1) . '. ' . $device;
      return "**Задание 22.** Установите соответствие между примерами (А–Д) и средствами выразительности (1–9). Запишите пять цифр подряд, без пробелов.\n\n"
        . implode("\n\n", $left) . "\n\n**Средства выразительности:**\n" . implode("\n", $right);
    };
    $answerVariants = static fn(string $answer): array => [
      $answer,
      implode(' ', str_split($answer)),
      implode(',', str_split($answer)),
    ];
    $hint = 'Для каждого примера А–Д выбери номер термина. В ответ запиши пять цифр подряд в порядке А, Б, В, Г, Д.';
    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $questions = ru_module4_homework_questions();
    foreach ($questions as $position => [$examples, $devices, $answer, $explanation]) {
      $text = $makeText($examples, $devices);
      $solution = 'Ответ: **' . $answer . "**.\n\n" . $explanation;
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answerVariants($answer), JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Русский июль, блок 4: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v69 — точечная уборка демонстрационных данных, которые мог добавить старый
  // автосид после применения v68 на проде. Удаляем только при полном совпадении
  // сигнатуры; при любом расхождении останавливаемся, не затрагивая данные.
  69 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/delete_helpers.php';

    $appliedSt = $pdo->prepare('SELECT applied_at FROM schema_migrations WHERE version = 68 LIMIT 1');
    $appliedSt->execute();
    $appliedAt = (string)($appliedSt->fetchColumn() ?: '');
    if ($appliedAt === '') {
      out('  = Уборка автосида: v68 не найдена — пропуск');
      return;
    }

    $from = new DateTimeImmutable($appliedAt, new DateTimeZone('UTC'));
    $to = $from->modify('+10 minutes');
    $enrollmentSt = $pdo->prepare(
      "SELECT id FROM enrollments
       WHERE source = 'subscription' AND expires_at IS NULL
         AND created_at BETWEEN ? AND ?
       ORDER BY id"
    );
    $enrollmentSt->execute([$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
    $enrollmentIds = array_map('intval', $enrollmentSt->fetchAll(PDO::FETCH_COLUMN));
    $mockIds = array_map('intval', $pdo->query('SELECT id FROM mock_exams ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $eventIds = array_map('intval', $pdo->query('SELECT id FROM events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

    if (!$enrollmentIds && !$mockIds && !$eventIds) {
      out('  = Уборка автосида: побочных данных нет');
      return;
    }
    // На проде сейчас есть курсы math/rus, но нет history, поэтому seed_mocks
    // создал четыре из пяти шаблонов (три математических и один русский).
    if (count($enrollmentIds) !== 19 || count($mockIds) !== 4 || count($eventIds) !== 5) {
      throw new RuntimeException(
        'Уборка автосида остановлена: сигнатура не совпала (зачисления '
        . count($enrollmentIds) . ', пробники ' . count($mockIds) . ', события ' . count($eventIds) . ')'
      );
    }

    $pdo->beginTransaction();
    try {
      foreach ($mockIds as $mockId) delete_root_row($pdo, 'mock_exams', $mockId);
      $deleteEvent = $pdo->prepare('DELETE FROM events WHERE id = ?');
      foreach ($eventIds as $eventId) $deleteEvent->execute([$eventId]);
      $deleteEnrollment = $pdo->prepare('DELETE FROM enrollments WHERE id = ?');
      foreach ($enrollmentIds as $enrollmentId) $deleteEnrollment->execute([$enrollmentId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    out('  - Убраны побочные данные автосида: 19 зачислений, 4 демо-пробника, 5 событий');
  },

  // v70 — базовое ДЗ к 5-му блоку июльской летней школы по математике:
  // 20 задач по теории вероятностей с автопроверкой, формулами и SVG-схемами.
  70 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/math_module5_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND (c.title = 'Летняя школа по Профильной Математике' OR c.title LIKE 'Летняя школа по%математик%')
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 5 OR b.title LIKE '%вероятност%')
       ORDER BY CASE WHEN b.n = 5 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Профильная математика' AND c.title LIKE 'Летняя школа по%математик%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 5 летней школы по математике');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/вероятност/u', mb_strtolower((string)$candidate['title']))) { $lesson = $candidate; break; }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];

    $description = 'Разберём классическую вероятность, противоположные и совместные события, независимые испытания, деревья вероятностей и задачи на объединение событий.';
    if (!$lesson) {
      $position = $lessons ? ((int)max(array_column($lessons, 'position')) + 1) : 0;
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Теория вероятностей с нуля', 'Видео и тест', '', '', 0, null, $description, $position]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Теория вероятностей с нуля';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Теория вероятностей с нуля (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Математика июль, блок 5: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%вероятност%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/вероятност|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши только число. Дробную часть можно отделить запятой или точкой.';
    $questions = math_module5_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Математика июль, блок 5: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v71 — заменяем дуги в решениях задач 10 и 16 на прямые сегментированные
  // шкалы. Новые имена файлов обходят кэш уже открывавших решение браузеров.
  71 => function (PDO $pdo, string $driver) {
    $update10 = $pdo->prepare(
      "UPDATE questions SET solution = REPLACE(solution, '/img/math-block5/interval-10.svg', '/img/math-block5/interval-10-v2.svg')
       WHERE solution LIKE '%/img/math-block5/interval-10.svg%'"
    );
    $update16 = $pdo->prepare(
      "UPDATE questions SET solution = REPLACE(solution, '/img/math-block5/interval-16.svg', '/img/math-block5/interval-16-v2.svg')
       WHERE solution LIKE '%/img/math-block5/interval-16.svg%'"
    );
    $update10->execute();
    $update16->execute();
    out('  + Схемы задач 10 и 16 заменены: ' . ($update10->rowCount() + $update16->rowCount()));
  },

  // v72 — базовое ДЗ к 6-му блоку июльской летней школы по математике:
  // 11 задач по стереометрии с автопроверкой, KaTeX и чистыми SVG-чертежами.
  72 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/math_module6_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND (c.title = 'Летняя школа по Профильной Математике' OR c.title LIKE 'Летняя школа по%математик%')
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 6 OR b.title LIKE '%стереометр%')
       ORDER BY CASE WHEN b.n = 6 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Профильная математика' AND c.title LIKE 'Летняя школа по%математик%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 6 летней школы по математике');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/стереометр/u', mb_strtolower((string)$candidate['title']))) { $lesson = $candidate; break; }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];

    $description = 'Повторим основные формулы стереометрии: объёмы и площади поверхностей, пространственные диагонали, сечения, призмы, пирамиды, цилиндры, конусы и шары.';
    if (!$lesson) {
      $position = $lessons ? ((int)max(array_column($lessons, 'position')) + 1) : 0;
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Стереометрия с нуля', 'Видео и тест', '', '', 0, null, $description, $position]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Стереометрия с нуля';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Стереометрия с нуля (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Математика июль, блок 6: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%стереометр%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/стереометр|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши только число. Для угла знак градуса добавлять необязательно.';
    $questions = math_module6_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Математика июль, блок 6: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v73 — заменяем авторские SVG в 6-м математическом блоке точными
  // фрагментами чертежей из исходного бланка по просьбе владельца.
  73 => function (PDO $pdo, string $driver) {
    $replacements = [
      0 => ['/img/math-block6/cone-cylinder.svg', '/img/math-block6/source/q01.jpg'],
      1 => ['/img/math-block6/triangular-prism.svg', '/img/math-block6/source/q02.jpg'],
      2 => ['/img/math-block6/prism-midline.svg', '/img/math-block6/source/q03.jpg'],
      3 => ['/img/math-block6/cone-height.svg', '/img/math-block6/source/q04.jpg'],
      4 => ['/img/math-block6/sphere-cylinder.svg', '/img/math-block6/source/q05.jpg'],
      5 => ['/img/math-block6/square-prism-diagonals.svg', '/img/math-block6/source/q06.jpg'],
      6 => ['/img/math-block6/cuboid-diagonal.svg', '/img/math-block6/source/q07.jpg'],
      7 => ['/img/math-block6/cylinder-in-cuboid.svg', '/img/math-block6/source/q08.jpg'],
      8 => ['/img/math-block6/hexagonal-prism.svg', '/img/math-block6/source/q09.jpg'],
      9 => ['/img/math-block6/square-prism-angle.svg', '/img/math-block6/source/q10.jpg'],
      10 => ['/img/math-block6/cone-cylinder.svg', '/img/math-block6/source/q11.jpg'],
    ];
    $update = $pdo->prepare(
      "UPDATE questions SET text = REPLACE(text, ?, ?)
       WHERE position = ? AND text LIKE ?
         AND homework_id IN (SELECT id FROM homeworks WHERE title = 'Стереометрия с нуля (база)')"
    );
    $changed = 0;
    foreach ($replacements as $position => [$from, $to]) {
      $update->execute([$from, $to, $position, '%' . $from . '%']);
      $changed += $update->rowCount();
    }
    out('  + Стереометрия: исходные чертежи установлены в заданиях: ' . $changed);
  },

  // v74 — в задании 7 используем чистое точное воспроизведение чертежа,
  // чтобы не показывать попавший в исходную обрезку водяной знак.
  74 => function (PDO $pdo, string $driver) {
    $update = $pdo->prepare(
      "UPDATE questions SET text = REPLACE(text, '/img/math-block6/source/q07.jpg', '/img/math-block6/source/q07-clean.svg')
       WHERE position = 6 AND text LIKE '%/img/math-block6/source/q07.jpg%'
         AND homework_id IN (SELECT id FROM homeworks WHERE title = 'Стереометрия с нуля (база)')"
    );
    $update->execute();
    out('  + Стереометрия, задание 7: чистый чертёж установлен: ' . $update->rowCount());
  },

  // v75 — возвращаем в задание 7 исходный фрагмент из бланка без ретуши.
  75 => function (PDO $pdo, string $driver) {
    $update = $pdo->prepare(
      "UPDATE questions SET text = REPLACE(text, '/img/math-block6/source/q07-clean.svg', '/img/math-block6/source/q07.jpg')
       WHERE position = 6 AND text LIKE '%/img/math-block6/source/q07-clean.svg%'
         AND homework_id IN (SELECT id FROM homeworks WHERE title = 'Стереометрия с нуля (база)')"
    );
    $update->execute();
    out('  + Стереометрия, задание 7: исходный скрин возвращён: ' . $update->rowCount());
  },

  // v76 — базовое ДЗ к 1-му блоку июльской летней школы по обществознанию:
  // 16 заданий по темам «Человек», «Деятельность» и «Познание» с автопроверкой.
  76 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_module1_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 1 OR b.title LIKE '%человек%' OR b.title LIKE '%обществ%')
       ORDER BY CASE WHEN b.n = 1 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 1 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/человек|обществ/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    // В первом модуле прикрепляем ДЗ к уже созданному первому уроку, даже если
    // администратор дал ему нестандартное название.
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $description = 'Разберём биосоциальную природу человека, понятия индивида и личности, потребности, мотивы и структуру деятельности, а также чувственное и рациональное познание.';
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Человек и общество', 'Видео и тест', '', '', 0, null, $description, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Человек и общество';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Человек и общество (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Обществознание июль, блок 1: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%человек%' OR h.title LIKE '%обществ%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/человек|обществ|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши последовательность цифр без пояснений. Пробелы или запятые между цифрами допустимы.';
    $questions = social_module1_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Обществознание июль, блок 1: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v77 — базовое ДЗ к 2-му блоку июльской летней школы по обществознанию:
  // 17 заданий по экономике, включая точные графики спроса и предложения из бланка.
  77 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_module2_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 2 OR b.title LIKE '%эконом%')
       ORDER BY CASE WHEN b.n = 2 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 2 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/эконом/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $description = 'Разберём факторы производства и факторные доходы, типы экономических систем, а также причины сдвига кривых спроса и предложения.';
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Экономика', 'Видео и тест', '', '', 0, null, $description, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Экономика';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Экономика (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Обществознание июль, блок 2: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%эконом%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/эконом|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши последовательность цифр без пояснений. Пробелы или запятые между цифрами допустимы.';
    $questions = social_module2_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Обществознание июль, блок 2: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v78 — базовое ДЗ к 3-му блоку июльской летней школы по обществознанию:
  // 18 заданий по социальным нормам, социальному контролю, семье и браку;
  // последние задания закрепляют материал прошлого экономического блока.
  78 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_module3_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 3 OR b.title LIKE '%социолог%' OR b.title LIKE '%семь%')
       ORDER BY CASE WHEN b.n = 3 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 3 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/социолог|социальн|семь|брак/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $description = 'Разберём социальные нормы и санкции, механизм социального контроля, виды и функции семьи, а затем закрепим материал прошлого блока по экономике.';
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Социология', 'Видео и тест', '', '', 0, null, $description, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Социология';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Социология (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Обществознание июль, блок 3: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%социолог%' OR h.title LIKE '%семь%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/социолог|семь|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши последовательность цифр без пояснений. Пробелы или запятые между цифрами допустимы.';
    $questions = social_module3_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Обществознание июль, блок 3: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v79 — ДЗ среднего уровня к 4-му июльскому блоку летней школы
  // по обществознанию: 20 заданий по государству, демократии и политическим режимам.
  79 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_module4_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 4 OR b.title LIKE '%политик%' OR b.title LIKE '%государств%')
       ORDER BY CASE WHEN b.n = 4 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 4 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/политик|государств|демократ|режим/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $description = 'Разберём признаки и функции государства, форму государства, политические режимы, демократию и принципы правового государства.';
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Политика', 'Видео и тест', '', '', 0, null, $description, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Политика';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Политика (средний)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Обществознание июль, блок 4: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ?
           AND (h.level = 'medium' OR h.title = 'Средний уровень' OR h.title LIKE '%политик%')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%политик%' OR h.title = 'Средний уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'medium' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'medium', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши последовательность цифр без пояснений. Пробелы или запятые между цифрами допустимы.';
    $questions = social_module4_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Обществознание июль, блок 4: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v80 — английский, август, блок 1: Inventions.
  // Базовый уровень: аудирование, лексика и грамматика (29 вопросов).
  // Средний уровень: определения и скрипт видео (18 вопросов).
  // MP3 хранится в приватном S3; видео преподаватель добавляет ссылкой Kinescope
  // через новый тип вложения question_attachments.kind = video.
  80 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/english_august_module1_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND b.n = 1
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Английский язык' AND c.title LIKE 'Летняя школа%англ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок 1 летней школы по английскому');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/invention|изобрет|технолог/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $quizletUrl = 'https://telegra.ph/Inventions-19-24-08-11';
    $descriptionBlock = "Изучаем лексику по теме «Современные изобретения» и закрепляем Present Simple, Present Continuous, степени сравнения и словообразование.\n\n### Лексика урока\n[**Квизлет · Inventions 19-24**]($quizletUrl)";
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Inventions: современные технологии', 'Видео и тест', '', '', 0, null, $descriptionBlock, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Inventions: современные технологии';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $description = trim((string)($lesson['description'] ?? ''));
      if (strpos($description, $quizletUrl) === false) {
        $description = $description === '' ? $descriptionBlock : $description . "\n\n### Лексика урока\n[**Квизлет · Inventions 19-24**]($quizletUrl)";
        $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
      }
    }

    $insertQuestion = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
    );

    $ensureHomework = function (string $level, string $title, array $questions) use ($pdo, $lessonId, $insertQuestion): array {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);
      if ($homeworkId <= 0) {
        $blankSt = $pdo->prepare(
          "SELECT h.id FROM homeworks h
           WHERE h.lesson_id = ? AND (h.level = ? OR h.title = ?)
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
           ORDER BY h.id LIMIT 1"
        );
        $blankTitle = $level === 'medium' ? 'Средний уровень' : 'Базовый уровень';
        $blankSt->execute([$lessonId, $level, $blankTitle]);
        $homeworkId = (int)($blankSt->fetchColumn() ?: 0);
        if ($homeworkId > 0) {
          $pdo->prepare('UPDATE homeworks SET title = ?, level = ? WHERE id = ?')->execute([$title, $level, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
              ->execute([$lessonId, $level, $title, '', (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      $created = false;
      if ((int)$countSt->fetchColumn() === 0) {
        foreach ($questions as $position => $q) {
          $insertQuestion->execute([
            $homeworkId,
            $q['type'],
            $q['text'],
            $q['options'] !== null ? json_encode($q['options'], JSON_UNESCAPED_UNICODE) : null,
            json_encode($q['correct'], JSON_UNESCAPED_UNICODE),
            $q['solution'],
            $q['hint'],
            $position,
          ]);
        }
        $created = true;
      }
      return [$homeworkId, $created];
    };

    [$baseId, $baseCreated] = $ensureHomework('normal', 'Inventions: технологии (база)', english_august_module1_base_questions());
    [$mediumId, $mediumCreated] = $ensureHomework('medium', 'Inventions: технологии (средний)', english_august_module1_medium_questions());

    // Одна и та же запись доступна на каждом из 11 пропусков аудирования.
    $audioUrl = '/api/file.php?path=questions%2Fenglish-august-block1-base-audio.mp3';
    $audioQs = $pdo->prepare('SELECT id FROM questions WHERE homework_id = ? AND position BETWEEN 0 AND 10 ORDER BY position, id');
    $audioQs->execute([$baseId]);
    $hasAudio = $pdo->prepare("SELECT 1 FROM question_attachments WHERE question_id = ? AND kind = 'audio' AND url = ? LIMIT 1");
    $insertAudio = $pdo->prepare("INSERT INTO question_attachments (question_id, kind, name, size, url, position) VALUES (?,'audio','Аудиозапись','451 КБ',?,0)");
    $audioCount = 0;
    foreach ($audioQs->fetchAll(PDO::FETCH_COLUMN) as $questionId) {
      $hasAudio->execute([(int)$questionId, $audioUrl]);
      if (!$hasAudio->fetchColumn()) { $insertAudio->execute([(int)$questionId, $audioUrl]); $audioCount++; }
    }

    out('  + Английский август, блок 1: базовый — ' . ($baseCreated ? 'создан' : 'уже был')
      . ', средний — ' . ($mediumCreated ? 'создан' : 'уже был')
      . ', аудио-вложений добавлено: ' . $audioCount . '; урок «' . $lessonTitle . '»');
  },

  // v81 — базовое ДЗ к 1-му блоку августа летней школы по математике:
  // 6 задач урока «Степени и корни с нуля» с автопроверкой и KaTeX-разборами.
  81 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/math_august_module1_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Профильная математика'
         AND (c.title = 'Летняя школа по Профильной Математике' OR c.title LIKE 'Летняя школа по%математик%')
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND b.n = 1
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Профильная математика'
           AND (c.title = 'Летняя школа по Профильной Математике' OR c.title LIKE 'Летняя школа по%математик%')"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок 1 летней школы по математике');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/степен|корн/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $description = 'Повторяем свойства степеней с рациональным показателем, перевод корней в степени и решаем простейшие степенные уравнения.';
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Степени и корни с нуля', 'Теория и практика', '', '', 0, null, $description, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Степени и корни с нуля';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      if (trim((string)($lesson['description'] ?? '')) === '') {
        $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
      }
    }

    $title = 'Степени и корни с нуля (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId > 0) {
      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      if ((int)$countSt->fetchColumn() > 0) {
        out("  = Математика август, блок 1: ДЗ уже заполнено у урока «$lessonTitle» — пропуск");
        return;
      }
    } else {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%степен%' OR h.title LIKE '%корн%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/степен|корн|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $insert = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
    );
    $hint = 'Запиши только число. Дробную часть можно отделить запятой или точкой.';
    $questions = math_august_module1_homework_questions();
    foreach ($questions as $position => [$text, $answers, $solution]) {
      $insert->execute([
        $homeworkId,
        $text,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $solution,
        $hint,
        $position,
      ]);
    }
    out('  + Математика август, блок 1: ДЗ «' . $title . '» создано у урока «' . $lessonTitle . '»; вопросов: ' . count($questions));
  },

  // v82 — базовое ДЗ к 5-му июльскому блоку летней школы
  // по обществознанию: 17 заданий по семейному, трудовому и уголовному праву
  // с повторением экономики и социального контроля.
  82 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_module5_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Июл%' OR m.name LIKE '%июл%')
         AND (b.n = 5 OR b.title LIKE '%прав%')
       ORDER BY CASE WHEN b.n = 5 THEN 0 ELSE 1 END, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден июльский блок 5 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/прав|семейн|трудов|уголов/ui', (string)$candidate['title'])) { $lesson = $candidate; break; }
    }
    if (!$lesson && $lessons) $lesson = $lessons[0];

    $description = 'Разберём семейное, трудовое и уголовное право: права и обязанности супругов, заключение трудового договора, уголовную ответственность и принципы уголовного права.';
    if (!$lesson) {
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Право', 'Видео и тест', '', '', 0, null, $description, 0]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Право';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Право (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId <= 0) {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%прав%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/прав|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $countSt->execute([$homeworkId]);
    $created = false;
    if ((int)$countSt->fetchColumn() === 0) {
      $insert = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
      );
      $hint = 'Запиши последовательность цифр без пояснений. Пробелы или запятые между цифрами допустимы.';
      $questions = social_module5_homework_questions();
      foreach ($questions as $position => [$text, $answers, $solution]) {
        $insert->execute([
          $homeworkId,
          $text,
          json_encode($answers, JSON_UNESCAPED_UNICODE),
          $solution,
          $hint,
          $position,
        ]);
      }
      $created = true;
    }

    // В задании 15 нужен исходный график сдвига предложения. Храним чистую SVG-схему
    // в статике, а не загружаем страницу PDF с водяным знаком.
    $q15St = $pdo->prepare('SELECT id FROM questions WHERE homework_id = ? AND position = 14 ORDER BY id LIMIT 1');
    $q15St->execute([$homeworkId]);
    $q15Id = (int)($q15St->fetchColumn() ?: 0);
    if ($q15Id > 0) {
      $imageUrl = '/img/social-block5/supply-shift.svg';
      $imageExists = $pdo->prepare("SELECT 1 FROM question_attachments WHERE question_id = ? AND kind = 'image' AND url = ? LIMIT 1");
      $imageExists->execute([$q15Id, $imageUrl]);
      if (!$imageExists->fetchColumn()) {
        $pdo->prepare(
          "INSERT INTO question_attachments (question_id, kind, name, size, url, position)
           VALUES (?,'image','Сдвиг предложения охранных услуг',NULL,?,0)"
        )->execute([$q15Id, $imageUrl]);
      }
    }

    out('  + Обществознание июль, блок 5: ДЗ «' . $title . '» ' . ($created ? 'создано' : 'уже было')
      . ' у урока «' . $lessonTitle . '»; вопросов: ' . (int)$pdo->query('SELECT COUNT(*) FROM questions WHERE homework_id = ' . $homeworkId)->fetchColumn());
  },

  // v83 — вводное базовое ДЗ к 1-му августовскому блоку летней школы
  // по обществознанию: 13 вопросов об устройстве ЕГЭ и критериях второй части.
  83 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_august_module1_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND b.n = 1
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок 1 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/как устроен.*егэ|егэ.*общаг|структур.*егэ|экзамен/ui', (string)$candidate['title'])) {
        $lesson = $candidate;
        break;
      }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];

    $description = 'Знакомимся со структурой ЕГЭ по обществознанию: количеством заданий, распределением первичных баллов и особенностями заданий второй части.';
    if (!$lesson) {
      $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM lessons WHERE block_id = ?');
      $positionSt->execute([$blockId]);
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Как устроен ЕГЭ по общаге', 'Теория и тест', '', '', 0, null, $description, (int)$positionSt->fetchColumn()]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Как устроен ЕГЭ по общаге';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ? AND (description IS NULL OR description = '')")
          ->execute([$description, $lessonId]);
    }

    $title = 'Как устроен ЕГЭ по общаге (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);
    if ($homeworkId <= 0) {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%ЕГЭ%' OR h.title LIKE '%общаг%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/егэ|общаг|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $countSt->execute([$homeworkId]);
    $created = false;
    if ((int)$countSt->fetchColumn() === 0) {
      $insert = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
      );
      $questions = social_august_module1_homework_questions();
      foreach ($questions as $position => $question) {
        $insert->execute([
          $homeworkId,
          $question['type'],
          $question['text'],
          json_encode($question['options'], JSON_UNESCAPED_UNICODE),
          json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
          $question['solution'],
          $question['hint'],
          $position,
        ]);
      }
      $created = true;
    }

    out('  + Обществознание август, блок 1: ДЗ «' . $title . '» ' . ($created ? 'создано' : 'уже было')
      . ' у урока «' . $lessonTitle . '»; вопросов: ' . (int)$pdo->query('SELECT COUNT(*) FROM questions WHERE homework_id = ' . $homeworkId)->fetchColumn());
  },

  // v84 — английский, август, урок 6 «Studying. 3УЧ».
  // Базовый уровень: словообразование, устная часть и идиомы (23 вопроса).
  // Сложный уровень: лексика, словообразование и видео 3-9 (26 вопросов).
  84 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/english_august_lesson6_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
       ORDER BY CASE WHEN b.n = 6 THEN 0 ELSE 1 END, b.position, b.id LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Английский язык' AND c.title LIKE 'Летняя школа%англ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок летней школы по английскому');
      return;
    }
    $blockId = (int)$block['id'];

    // Урок 6 может физически находиться внутри первого августовского блока,
    // поэтому ищем по названию во всём августовском месяце этого курса.
    $lessonSt = $pdo->query(
      "SELECT l.id, l.title, l.description, l.position, b.id AS block_id
       FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND (l.title LIKE '%Studying%' OR l.title LIKE '%3УЧ%' OR l.title LIKE '%ЗУЧ%')
       ORDER BY l.id DESC LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;

    $resources = "### Тренировка лексики\n"
      . "1. [Слово - перевод](https://wordwall.net/ru/resource/95332444)\n"
      . "2. [Коллокации](https://wordwall.net/ru/resource/95333574)\n"
      . "3. [Кроссворд](https://wordwall.net/ru/resource/95335896)";
    $descriptionBlock = "Разбираем лексику по теме подготовки к экзаменам: эффективное обучение, повторение, устная часть и способы поддерживать концентрацию.\n\n" . $resources;

    if (!$lesson) {
      $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM lessons WHERE block_id = ?');
      $positionSt->execute([$blockId]);
      $pdo->prepare(
        'INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
      )->execute([$blockId, 'theory', 'Studying. 3УЧ', 'Теория и практика', '', '', 0, null, $descriptionBlock, (int)$positionSt->fetchColumn()]);
      $lessonId = (int)$pdo->lastInsertId();
      $lessonTitle = 'Studying. 3УЧ';
    } else {
      $lessonId = (int)$lesson['id'];
      $lessonTitle = (string)$lesson['title'];
      $description = trim((string)($lesson['description'] ?? ''));
      if (strpos($description, '95332444') === false) {
        $description = $description === '' ? $descriptionBlock : $description . "\n\n" . $resources;
        $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
      }
    }

    $insertQuestion = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
    );

    $ensureHomework = function (string $level, string $title, array $questions) use ($pdo, $lessonId, $insertQuestion): array {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);

      if ($homeworkId <= 0) {
        $blankTitle = $level === 'hard' ? 'Сложный уровень' : 'Базовый уровень';
        $blankSt = $pdo->prepare(
          "SELECT h.id FROM homeworks h
           WHERE h.lesson_id = ?
             AND (h.level = ? OR h.title = ? OR (h.title = 'Продвинутый уровень' AND ? = 'hard'))
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
           ORDER BY CASE WHEN h.level = ? THEN 0 ELSE 1 END, h.id LIMIT 1"
        );
        $blankSt->execute([$lessonId, $level, $blankTitle, $level, $level]);
        $homeworkId = (int)($blankSt->fetchColumn() ?: 0);
        if ($homeworkId > 0) {
          $pdo->prepare('UPDATE homeworks SET title = ?, level = ? WHERE id = ?')->execute([$title, $level, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
              ->execute([$lessonId, $level, $title, '', (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      $created = false;
      if ((int)$countSt->fetchColumn() === 0) {
        foreach ($questions as $position => $question) {
          $insertQuestion->execute([
            $homeworkId,
            $question['type'],
            $question['text'],
            $question['options'] !== null ? json_encode($question['options'], JSON_UNESCAPED_UNICODE) : null,
            json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
            $question['solution'],
            $question['hint'],
            $position,
          ]);
        }
        $created = true;
      }
      return [$homeworkId, $created];
    };

    [$baseId, $baseCreated] = $ensureHomework('normal', 'Studying. 3УЧ (база)', english_august_lesson6_base_questions());
    [$hardId, $hardCreated] = $ensureHomework('hard', 'Studying. 3УЧ (сложный)', english_august_lesson6_hard_questions());

    // Вопросы 3-9 сложного уровня основаны на одном видео с носителем.
    // Добавляем плеер к каждому из семи вопросов, чтобы он оставался доступен при переключении.
    $videoUrl = 'https://kinescope.io/embed/qTY6VZ9XppSFzpvaW1CZmm';
    $videoQs = $pdo->prepare('SELECT id FROM questions WHERE homework_id = ? AND position BETWEEN 19 AND 25 ORDER BY position, id');
    $videoQs->execute([$hardId]);
    $hasVideo = $pdo->prepare("SELECT 1 FROM question_attachments WHERE question_id = ? AND kind = 'video' AND url = ? LIMIT 1");
    $insertVideo = $pdo->prepare(
      "INSERT INTO question_attachments (question_id, kind, name, size, url, position)
       VALUES (?,'video','Видео к заданию','Kinescope',?,0)"
    );
    $videoCount = 0;
    foreach ($videoQs->fetchAll(PDO::FETCH_COLUMN) as $questionId) {
      $hasVideo->execute([(int)$questionId, $videoUrl]);
      if (!$hasVideo->fetchColumn()) {
        $insertVideo->execute([(int)$questionId, $videoUrl]);
        $videoCount++;
      }
    }

    out('  + Английский август, урок 6 «' . $lessonTitle . '»: база - ' . ($baseCreated ? 'создана' : 'уже была')
      . ' (' . count(english_august_lesson6_base_questions()) . ' вопросов), сложный - ' . ($hardCreated ? 'создан' : 'уже был')
      . ' (' . count(english_august_lesson6_hard_questions()) . ' вопросов), видео-вложений добавлено: ' . $videoCount);
  },

  // v85 — исправляет старую опечатку в названии августовского урока английского.
  85 => function (PDO $pdo, string $driver) {
    $lessonIds = $pdo->query(
      "SELECT l.id FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND l.title IN ('Studying. ЗУЧ', 'Studying. 3УЧ')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $rename = $pdo->prepare("UPDATE lessons SET title = 'Studying. 3УЧ' WHERE id = ?");
    foreach ($lessonIds as $lessonId) $rename->execute([(int)$lessonId]);
    out('  + Английский август: название урока приведено к «Studying. 3УЧ»');
  },

  // v86 — видеочасть является отдельным блоком из семи вопросов.
  // Убираем сквозную нумерацию 3-9 из PDF и возможный HTML-код пробела.
  86 => function (PDO $pdo, string $driver) {
    $homeworkSt = $pdo->query(
      "SELECT h.id FROM homeworks h
       JOIN lessons l ON l.id = h.lesson_id
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND l.title = 'Studying. 3УЧ'
         AND h.level = 'hard'
       ORDER BY h.id DESC LIMIT 1"
    );
    $homeworkId = (int)($homeworkSt ? $homeworkSt->fetchColumn() : 0);
    if ($homeworkId <= 0) return;

    $questions = $pdo->prepare('SELECT id, text FROM questions WHERE homework_id = ? AND position BETWEEN 19 AND 25 ORDER BY position, id');
    $questions->execute([$homeworkId]);
    $update = $pdo->prepare('UPDATE questions SET text = ? WHERE id = ?');
    foreach ($questions->fetchAll() as $index => $question) {
      $text = html_entity_decode((string)$question['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $text = preg_replace('/^Задание 3 · Видео · вопрос \d+\/\d+[\h\x{00A0}]*/u', 'Задание 3 · Видео · вопрос ' . ($index + 1) . '/7', $text);
      $update->execute([$text, (int)$question['id']]);
    }
    out('  + Английский август: видеовопросы перенумерованы 1/7-7/7');
  },

  // v87 — Quizlet к уроку «Studying. 3УЧ».
  87 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->query(
      "SELECT l.id, l.description FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND l.title = 'Studying. 3УЧ'
       ORDER BY l.id DESC LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) return;

    $quizletUrl = 'https://quizlet.com/ru/1064172384/class-2-effective-study-techniques-flash-cards/';
    $description = trim((string)($lesson['description'] ?? ''));
    if (strpos($description, '1064172384') === false) {
      $description .= ($description === '' ? '' : "\n\n") . '👨‍🎓 [Квизлет](' . $quizletUrl . ')';
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, (int)$lesson['id']]);
    }
    out('  + Английский август: Quizlet добавлен в описание «Studying. 3УЧ»');
  },

  // v88 — английский, август, урок 7 «Studying. 3УЧ»: Hobbies.
  88 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/english_august_lesson7_homework.php';

    // В этой базе b.n — номер модуля, а не сквозной номер урока. Сначала
    // находим второй одноимённый Studying без ДЗ предыдущего урока №6.
    $lessonSt = $pdo->query(
      "SELECT l.id, l.title, l.description, l.block_id FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND (l.title LIKE '%Studying%' OR l.title LIKE '%3УЧ%' OR l.title LIKE '%ЗУЧ%')
         AND NOT EXISTS (
           SELECT 1 FROM homeworks old_h
           WHERE old_h.lesson_id = l.id AND old_h.title LIKE 'Studying. 3УЧ%'
         )
       ORDER BY b.position, b.id, l.position, l.id LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;

    // Если название у заготовки ещё не заполнено, берём седьмой урок августа
    // по фактическому порядку блоков и уроков.
    if (!$lesson) {
      $ordered = $pdo->query(
        "SELECT l.id, l.title, l.description, l.block_id FROM lessons l
         JOIN blocks b ON b.id = l.block_id
         JOIN months m ON m.id = b.month_id
         JOIN courses c ON c.id = b.course_id
         JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Английский язык'
           AND c.title LIKE 'Летняя школа%англ%'
           AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         ORDER BY b.position, b.id, l.position, l.id"
      );
      $orderedLessons = $ordered ? $ordered->fetchAll() : [];
      $lesson = $orderedLessons[6] ?? false;
    }

    // В августе пока заведено меньше семи уроков: создаём урок №7 сразу
    // после уже заполненного урока №6 в том же блоке.
    if (!$lesson) {
      $previousSt = $pdo->query(
        "SELECT l.block_id FROM lessons l
         JOIN blocks b ON b.id = l.block_id
         JOIN months m ON m.id = b.month_id
         JOIN courses c ON c.id = b.course_id
         JOIN subjects s ON s.id = c.subject_id
         JOIN homeworks h ON h.lesson_id = l.id
         WHERE s.name = 'Английский язык'
           AND c.title LIKE 'Летняя школа%англ%'
           AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
           AND h.title LIKE 'Studying. 3УЧ%'
         ORDER BY l.id DESC LIMIT 1"
      );
      $blockId = (int)($previousSt ? $previousSt->fetchColumn() : 0);
      if ($blockId <= 0) throw new RuntimeException('Не найден блок предыдущего августовского урока 6 по английскому');
      $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM lessons WHERE block_id = ?');
      $positionSt->execute([$blockId]);
      $pdo->prepare('INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, description, position) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$blockId, 'theory', 'Studying. 3УЧ', 'Теория и практика', '', '', 0, null, '', (int)$positionSt->fetchColumn()]);
      $lesson = ['id' => (int)$pdo->lastInsertId(), 'title' => 'Studying. 3УЧ', 'description' => '', 'block_id' => $blockId];
    }

    $lessonId = (int)$lesson['id'];
    $description = trim((string)($lesson['description'] ?? ''));
    $pdo->prepare("UPDATE lessons SET title = 'Studying. 3УЧ' WHERE id = ?")->execute([$lessonId]);

    $quizletUrl = 'https://telegra.ph/Urok-7-Studying-3UCH-08-18';
    $resources = "### Лексика урока\n"
      . "👨‍🎓 [Квизлет]($quizletUrl)\n"
      . "- [Play / do / go: тренажёр](https://wordwall.net/ru/resource/78657255)\n"
      . "- [Лексика урока: карточки](https://wordwall.net/ru/resource/95806832)";
    if (strpos($description, $quizletUrl) === false) {
      $description = $description === ''
        ? "Тренируем лексику по теме хобби, сочетания play / do / go и устную часть.\n\n" . $resources
        : $description . "\n\n" . $resources;
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
    }

    $insertQuestion = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,?,?,?,?,?,?,?,?,0,0,'full',1,?)"
    );
    $ensureHomework = function (string $level, string $title, array $questions) use ($pdo, $lessonId, $insertQuestion): array {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);
      if ($homeworkId <= 0) {
        $blankTitle = $level === 'medium' ? 'Средний уровень' : 'Базовый уровень';
        $blank = $pdo->prepare(
          "SELECT h.id FROM homeworks h WHERE h.lesson_id = ? AND (h.level = ? OR h.title = ?)
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
           ORDER BY h.id LIMIT 1"
        );
        $blank->execute([$lessonId, $level, $blankTitle]);
        $homeworkId = (int)($blank->fetchColumn() ?: 0);
        if ($homeworkId > 0) {
          $pdo->prepare('UPDATE homeworks SET title = ?, level = ? WHERE id = ?')->execute([$title, $level, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, $level, $title, '', (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      }
      $count = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $count->execute([$homeworkId]);
      $created = false;
      if ((int)$count->fetchColumn() === 0) {
        foreach ($questions as $position => $question) {
          $insertQuestion->execute([
            $homeworkId,
            $question['type'],
            $question['text'],
            $question['options'] !== null ? json_encode($question['options'], JSON_UNESCAPED_UNICODE) : null,
            json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
            $question['solution'],
            $question['hint'],
            (int)($question['points'] ?? 1),
            !empty($question['optional']) ? 1 : 0,
            $position,
          ]);
        }
        $created = true;
      }
      return [$homeworkId, $created];
    };

    [$baseId, $baseCreated] = $ensureHomework('normal', 'Hobbies (база)', english_august_lesson7_base_questions());
    [$mediumId, $mediumCreated] = $ensureHomework('medium', 'Hobbies (средний)', english_august_lesson7_medium_questions());
    out('  + Английский август, урок 7 «Studying. 3УЧ»: база - ' . ($baseCreated ? 'создана' : 'уже была')
      . ' (' . count(english_august_lesson7_base_questions()) . '), средний - ' . ($mediumCreated ? 'создан' : 'уже был')
      . ' (' . count(english_august_lesson7_medium_questions()) . '), Quizlet добавлен');
  },

  // v89 — урок 7 в исходниках относится к существующему уроку Hobbies.
  // Переносим ошибочно созданные v88 ДЗ и удаляем лишний урок Studying.
  89 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/delete_helpers.php';

    $sourceSt = $pdo->query(
      "SELECT DISTINCT l.id, l.description FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       JOIN homeworks h ON h.lesson_id = l.id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND h.title IN ('Hobbies (база)', 'Hobbies (средний)')
       ORDER BY l.id DESC LIMIT 1"
    );
    $source = $sourceSt ? $sourceSt->fetch() : false;
    if (!$source) throw new RuntimeException('Не найден ошибочно созданный урок с ДЗ Hobbies');
    $sourceId = (int)$source['id'];

    $targetSt = $pdo->prepare(
      "SELECT l.id, l.title, l.description FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND l.id <> ?
         AND (l.title LIKE '%Hobbies%' OR l.title LIKE '%Hobby%' OR l.title LIKE '%4УЧ%')
       ORDER BY b.position, b.id, l.position, l.id LIMIT 1"
    );
    $targetSt->execute([$sourceId]);
    $target = $targetSt->fetch();
    if (!$target) throw new RuntimeException('Не найден существующий августовский урок Hobbies');
    $targetId = (int)$target['id'];

    // Удаляем только пустые заготовки уровней, чтобы в Hobbies не появились дубли.
    $removeBlank = $pdo->prepare(
      "DELETE FROM homeworks
       WHERE lesson_id = ?
         AND title IN ('Базовый уровень', 'Средний уровень', 'Hobbies (база)', 'Hobbies (средний)')
         AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = homeworks.id)
         AND NOT EXISTS (SELECT 1 FROM submissions sub WHERE sub.homework_id = homeworks.id)"
    );
    $removeBlank->execute([$targetId]);

    $pdo->prepare("UPDATE homeworks SET lesson_id = ?, position = CASE WHEN title = 'Hobbies (база)' THEN 0 ELSE 1 END WHERE lesson_id = ? AND title IN ('Hobbies (база)', 'Hobbies (средний)')")
      ->execute([$targetId, $sourceId]);

    $targetDescription = trim((string)($target['description'] ?? ''));
    $sourceDescription = trim((string)($source['description'] ?? ''));
    if ($sourceDescription !== '' && strpos($targetDescription, 'Urok-7-Studying-3UCH-08-18') === false) {
      $targetDescription .= ($targetDescription === '' ? '' : "\n\n") . $sourceDescription;
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$targetDescription, $targetId]);
    }

    $remaining = $pdo->prepare('SELECT COUNT(*) FROM homeworks WHERE lesson_id = ?');
    $remaining->execute([$sourceId]);
    if ((int)$remaining->fetchColumn() === 0) {
      $visited = [];
      delete_row_cascade($pdo, $driver, 'lessons', 'id', $sourceId, $visited);
    }

    out('  + Английский август: ДЗ урока 7 перенесены в «' . (string)$target['title'] . '», лишний Studying удалён');
  },

  // v90 — гарантированно добавляет ресурсы именно в урок с ДЗ Hobbies.
  90 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->query(
      "SELECT DISTINCT l.id, l.title, l.description FROM lessons l
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       JOIN homeworks h ON h.lesson_id = l.id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND h.title IN ('Hobbies (база)', 'Hobbies (средний)')
       ORDER BY l.id DESC LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) throw new RuntimeException('Не найден урок с домашними заданиями Hobbies');

    $description = trim((string)($lesson['description'] ?? ''));
    $links = [
      'https://telegra.ph/Urok-7-Studying-3UCH-08-18' => '👨‍🎓 [Квизлет](https://telegra.ph/Urok-7-Studying-3UCH-08-18)',
      'https://wordwall.net/ru/resource/78657255' => '[Play / do / go: тренажёр](https://wordwall.net/ru/resource/78657255)',
      'https://wordwall.net/ru/resource/95806832' => '[Лексика урока: карточки](https://wordwall.net/ru/resource/95806832)',
    ];
    $missing = [];
    foreach ($links as $url => $markdown) if (strpos($description, $url) === false) $missing[] = '- ' . $markdown;
    if ($missing) {
      $description .= ($description === '' ? '' : "\n\n") . "### Материалы к уроку\n" . implode("\n", $missing);
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, (int)$lesson['id']]);
    }
    out('  + Английский август: Квизлет и Wordwall закреплены в описании «' . (string)$lesson['title'] . '»');
  },

  // v91 — необязательное устное задание оценивается вручную по шкале 0-10.
  91 => function (PDO $pdo, string $driver) {
    $questionIds = $pdo->query(
      "SELECT q.id FROM questions q
       JOIN homeworks h ON h.id = q.homework_id
       JOIN lessons l ON l.id = h.lesson_id
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND h.title = 'Hobbies (средний)'
         AND q.type = 'textarea'
         AND q.text LIKE 'Задание 5 · Устная часть%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare('UPDATE questions SET points = 10, optional = 1 WHERE id = ?');
    foreach ($questionIds as $questionId) $update->execute([(int)$questionId]);
    out('  + Hobbies: устная часть теперь оценивается вручную от 0 до 10 баллов');
  },

  // v92 — синхронизирует максимум старых сдач Hobbies после изменения устной части с 0 до 10 баллов.
  // Заодно исправляет уже созданное уведомление, чтобы оно совпадало со страницей результата.
  92 => function (PDO $pdo, string $driver) {
    $submissions = $pdo->query(
      "SELECT sub.id, sub.user_id, sub.score, sub.max_score, sub.status
       FROM submissions sub
       JOIN homeworks h ON h.id = sub.homework_id
       JOIN lessons l ON l.id = h.lesson_id
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND h.title = 'Hobbies (средний)'"
    )->fetchAll();

    $sumMax = $pdo->prepare(
      "SELECT COALESCE(SUM(q.points),0)
       FROM answers a
       JOIN questions q ON q.id = a.question_id
       WHERE a.submission_id = ?"
    );
    $updateSubmission = $pdo->prepare('UPDATE submissions SET max_score = ? WHERE id = ?');
    $findNotification = $pdo->prepare(
      "SELECT id FROM notifications
       WHERE user_id = ? AND type = 'review' AND text = ?
       ORDER BY id DESC LIMIT 1"
    );
    $updateNotification = $pdo->prepare('UPDATE notifications SET text = ? WHERE id = ?');
    $fixed = 0;

    foreach ($submissions as $submission) {
      $sumMax->execute([(int)$submission['id']]);
      $actualMax = (int)$sumMax->fetchColumn();
      $oldMax = (int)$submission['max_score'];
      if ($actualMax === $oldMax) continue;

      $updateSubmission->execute([$actualMax, (int)$submission['id']]);
      if ((string)$submission['status'] === 'graded') {
        $oldText = "Домашняя работа проверена: {$submission['score']} из {$oldMax} баллов";
        $findNotification->execute([(int)$submission['user_id'], $oldText]);
        $notificationId = $findNotification->fetchColumn();
        if ($notificationId !== false) {
          $newText = "Домашняя работа проверена: {$submission['score']} из {$actualMax} баллов";
          $updateNotification->execute([$newText, (int)$notificationId]);
        }
      }
      $fixed++;
    }

    out("  + Hobbies: максимум и уведомления синхронизированы; исправлено сдач: {$fixed}");
  },

  // v93 - базовое ДЗ к 1-му августовскому блоку летней школы по русскому языку:
  // 9 вопросов об устройстве сочинения ЕГЭ-2026 с автопроверкой.
  93 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/ru_august_module1_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Русский язык'
         AND c.title LIKE 'Летняя школа%русск%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND b.n = 1
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Русский язык' AND c.title LIKE 'Летняя школа%русск%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок 1 летней школы по русскому языку');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/сочинен|егэ.?2026|что было на егэ/ui', (string)$candidate['title'])) {
        $lesson = $candidate;
        break;
      }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];
    if (!$lesson) {
      $available = implode(', ', array_map(static fn(array $row): string => '«' . (string)$row['title'] . '»', $lessons));
      throw new RuntimeException('Не найден существующий урок про сочинение в русском блоке 1. Уроки блока: ' . ($available ?: '(нет)'));
    }

    $lessonId = (int)$lesson['id'];
    $lessonTitle = (string)$lesson['title'];
    $description = 'Разбираем сочинение ЕГЭ-2026: максимальные баллы, структуру комментария, авторскую и собственную позиции, примеры и требования к объёму.';
    if (trim((string)($lesson['description'] ?? '')) === '') {
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
    }

    $title = 'Сочинение: что было на ЕГЭ 2026? (база)';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);

    if ($homeworkId <= 0) {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%сочинен%' OR h.title LIKE '%ЕГЭ 2026%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/сочинен|егэ.?2026|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal' WHERE id = ?")->execute([$title, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, '', (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    }

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $countSt->execute([$homeworkId]);
    $created = false;
    if ((int)$countSt->fetchColumn() === 0) {
      $insert = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
      );
      $questions = ru_august_module1_homework_questions();
      foreach ($questions as $position => $question) {
        $insert->execute([
          $homeworkId,
          $question['type'],
          $question['text'],
          json_encode($question['options'], JSON_UNESCAPED_UNICODE),
          json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
          $question['solution'],
          $question['hint'],
          $position,
        ]);
      }
      $created = true;
    }

    out('  + Русский август, блок 1: ДЗ «' . $title . '» ' . ($created ? 'создано' : 'уже было')
      . ' у урока «' . $lessonTitle . '»; вопросов: ' . (int)$pdo->query('SELECT COUNT(*) FROM questions WHERE homework_id = ' . $homeworkId)->fetchColumn());
  },

  // v94 — обязательный контакт VK для связи с персональным менеджером в сентябре.
  // Ученик отправляет ссылку, администратор вручную подтверждает или отклоняет её.
  94 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS user_vk_contacts (
      user_id INT PRIMARY KEY,
      vk_url VARCHAR(255) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      rejection_reason VARCHAR(255),
      submitted_at VARCHAR(30),
      reviewed_at VARCHAR(30),
      reviewed_by INT,
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (reviewed_by) REFERENCES admins(id)
    ) {ENGINE}", $driver));
  },

  // v95 - базовое ДЗ к августовскому блоку 2 летней школы по русскому языку.
  // Задание 12: личные окончания глаголов и суффиксы причастий, 10 вопросов.
  95 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/ru_august_module2_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Русский язык'
         AND c.title LIKE 'Летняя школа%русск%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND b.n = 2
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Русский язык' AND c.title LIKE 'Летняя школа%русск%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок 2 летней школы по русскому языку');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/задани[ея]\s*12|окончани.*глагол|суффикс.*причаст/ui', (string)$candidate['title'])) {
        $lesson = $candidate;
        break;
      }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];
    if (!$lesson) {
      $available = implode(', ', array_map(static fn(array $row): string => '«' . (string)$row['title'] . '»', $lessons));
      throw new RuntimeException('Не найден существующий урок «Задание 12» в русском блоке 2. Уроки блока: ' . ($available ?: '(нет)'));
    }

    $lessonId = (int)$lesson['id'];
    $lessonTitle = (string)$lesson['title'];
    if (trim((string)($lesson['description'] ?? '')) === '') {
      $description = 'Отрабатываем задание 12 ЕГЭ: личные окончания глаголов, суффиксы причастий настоящего и прошедшего времени.';
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
    }

    $title = 'Задание 12: окончания глаголов и суффиксы причастий (база)';
    $deadline = '2026-08-31 23:59:00';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);

    if ($homeworkId <= 0) {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%12%' OR h.title LIKE '%окончан%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/задани[ея]\s*12|окончан|причаст|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal', deadline = ? WHERE id = ?")
            ->execute([$title, $deadline, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, $deadline, (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    } else {
      $pdo->prepare("UPDATE homeworks SET level = 'normal', deadline = ? WHERE id = ?")
          ->execute([$deadline, $homeworkId]);
    }

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $countSt->execute([$homeworkId]);
    $created = false;
    if ((int)$countSt->fetchColumn() === 0) {
      $insert = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
      );
      foreach (ru_august_module2_homework_questions() as $position => $question) {
        $insert->execute([
          $homeworkId,
          $question['type'],
          $question['text'],
          json_encode($question['options'], JSON_UNESCAPED_UNICODE),
          json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
          $question['solution'],
          $question['hint'],
          $position,
        ]);
      }
      $created = true;
    }

    live_content_touch();
    $questionCountSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $questionCountSt->execute([$homeworkId]);
    out('  + Русский август, блок 2: ДЗ «' . $title . '» ' . ($created ? 'создано' : 'уже было')
      . ' у урока «' . $lessonTitle . '»; дедлайн: 31.08.2026 23:59; вопросов: ' . (int)$questionCountSt->fetchColumn());
  },

  // v96 - полное базовое ДЗ «Теория на порог» к августовскому блоку 2
  // летней школы по обществознанию: 42 задания и 4 исходных графика из PDF.
  96 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_august_module2_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.title AS block_title
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Обществознание'
         AND c.title LIKE 'Летняя школа%обществ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND b.n = 2
       ORDER BY b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Обществознание' AND c.title LIKE 'Летняя школа%обществ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден августовский блок 2 летней школы по обществознанию');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/теори.*порог|на порог/ui', (string)$candidate['title'])) {
        $lesson = $candidate;
        break;
      }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];
    if (!$lesson) {
      $available = implode(', ', array_map(static fn(array $row): string => '«' . (string)$row['title'] . '»', $lessons));
      throw new RuntimeException('Не найден существующий урок «Теория на порог» в августовском блоке 2. Уроки блока: ' . ($available ?: '(нет)'));
    }

    $lessonId = (int)$lesson['id'];
    $lessonTitle = (string)$lesson['title'];
    if (trim((string)($lesson['description'] ?? '')) === '') {
      $description = 'Повторяем ключевые темы первой части ЕГЭ: человек и потребности, экономика, социальный контроль, семья, политика, трудовое и уголовное право.';
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
    }

    $title = 'Теория на порог (база)';
    $deadline = '2026-08-31 23:59:00';
    $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $title]);
    $homeworkId = (int)($find->fetchColumn() ?: 0);

    if ($homeworkId <= 0) {
      $blankSt = $pdo->prepare(
        "SELECT h.id, h.title FROM homeworks h
         WHERE h.lesson_id = ? AND (h.level = 'normal' OR h.level IS NULL OR h.level = '')
           AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
         ORDER BY CASE WHEN h.title LIKE '%порог%' OR h.title = 'Базовый уровень' THEN 0 ELSE 1 END, h.id"
      );
      $blankSt->execute([$lessonId]);
      $blanks = $blankSt->fetchAll();
      if ($blanks && (count($blanks) === 1 || preg_match('/порог|базовый уровень/ui', (string)$blanks[0]['title']))) {
        $homeworkId = (int)$blanks[0]['id'];
        $pdo->prepare("UPDATE homeworks SET title = ?, level = 'normal', deadline = ? WHERE id = ?")
            ->execute([$title, $deadline, $homeworkId]);
      } else {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
        $positionSt->execute([$lessonId]);
        $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, 'normal', $title, $deadline, (int)$positionSt->fetchColumn()]);
        $homeworkId = (int)$pdo->lastInsertId();
      }
    } else {
      $pdo->prepare("UPDATE homeworks SET level = 'normal', deadline = ? WHERE id = ?")
          ->execute([$deadline, $homeworkId]);
    }

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $countSt->execute([$homeworkId]);
    $created = false;
    if ((int)$countSt->fetchColumn() === 0) {
      $insert = $pdo->prepare(
        "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
         VALUES (?,?,?,?,?,?,?,1,0,0,0,'full',1,?)"
      );
      $attach = $pdo->prepare(
        "INSERT INTO question_attachments (question_id, kind, name, size, url, position)
         VALUES (?,'image',?,NULL,?,0)"
      );
      foreach (social_august_module2_homework_questions() as $position => $question) {
        $insert->execute([
          $homeworkId,
          $question['type'],
          $question['text'],
          json_encode($question['options'], JSON_UNESCAPED_UNICODE),
          json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
          $question['solution'],
          $question['hint'],
          $position,
        ]);
        if (!empty($question['image'])) {
          $attach->execute([
            (int)$pdo->lastInsertId(),
            'График к заданию ' . (int)$question['source_number'],
            $question['image'],
          ]);
        }
      }
      $created = true;
    }

    live_content_touch();
    $questionCountSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
    $questionCountSt->execute([$homeworkId]);
    out('  + Обществознание август, блок 2: ДЗ «' . $title . '» ' . ($created ? 'создано' : 'уже было')
      . ' у урока «' . $lessonTitle . '»; дедлайн: 31.08.2026 23:59; вопросов: ' . (int)$questionCountSt->fetchColumn());
  },

  // v97 - последний августовский блок английского: Communication 25-29.
  // База - словообразование и общие суффиксы; средний - аудирование и задание 38.
  97 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/english_august_final_homework.php';

    $blockSt = $pdo->query(
      "SELECT b.id, b.n, b.title AS block_title, b.position
       FROM blocks b
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
       ORDER BY b.position DESC, b.id DESC LIMIT 1"
    );
    $block = $blockSt ? $blockSt->fetch() : false;
    if (!$block) {
      $hasCourse = (int)$pdo->query(
        "SELECT COUNT(*) FROM courses c JOIN subjects s ON s.id = c.subject_id
         WHERE s.name = 'Английский язык' AND c.title LIKE 'Летняя школа%англ%'"
      )->fetchColumn();
      if ($hasCourse) throw new RuntimeException('Не найден последний августовский блок летней школы по английскому');
      return;
    }
    $blockId = (int)$block['id'];

    $lessonsSt = $pdo->prepare('SELECT id, title, description, position FROM lessons WHERE block_id = ? ORDER BY position, id');
    $lessonsSt->execute([$blockId]);
    $lessons = $lessonsSt->fetchAll();
    $lesson = false;
    foreach ($lessons as $candidate) {
      if (preg_match('/communication|общени|25\s*[-–—]\s*29/ui', (string)$candidate['title'])) {
        $lesson = $candidate;
        break;
      }
    }
    if (!$lesson && count($lessons) === 1) $lesson = $lessons[0];
    if (!$lesson) {
      $available = implode(', ', array_map(static fn(array $row): string => '«' . (string)$row['title'] . '»', $lessons));
      throw new RuntimeException('Не найден существующий урок Communication в последнем августовском блоке английского. Уроки блока: ' . ($available ?: '(нет)'));
    }

    $lessonId = (int)$lesson['id'];
    $lessonTitle = (string)$lesson['title'];
    $quizletUrl = 'https://wordwall.net/resource/96077712';
    $description = trim((string)($lesson['description'] ?? ''));
    if (strpos($description, $quizletUrl) === false) {
      $resources = "### Лексика урока\n[**Квизлет**]($quizletUrl)";
      $description = $description === ''
        ? "Тренируем лексику и словообразование по теме общения, а также задания 1 и 38 ЕГЭ.\n\n" . $resources
        : $description . "\n\n" . $resources;
      $pdo->prepare('UPDATE lessons SET description = ? WHERE id = ?')->execute([$description, $lessonId]);
    }

    $deadline = '2026-08-31 23:59:00';
    $insertQuestion = $pdo->prepare(
      "INSERT INTO questions (homework_id, type, text, options, correct, solution, hint, points, optional, hard, case_sensitive, grade_mode, file_limit, position)
       VALUES (?,?,?,?,?,?,?,?,?,0,0,'full',1,?)"
    );
    $ensureHomework = function (string $level, string $title, array $questions) use ($pdo, $lessonId, $deadline, $insertQuestion): array {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id = ? AND title = ? ORDER BY id LIMIT 1');
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);
      if ($homeworkId <= 0) {
        $blankTitle = $level === 'medium' ? 'Средний уровень' : 'Базовый уровень';
        $blankSt = $pdo->prepare(
          "SELECT h.id, h.title FROM homeworks h
           WHERE h.lesson_id = ? AND (h.level = ? OR h.title = ?)
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id = h.id)
           ORDER BY CASE WHEN h.title = ? THEN 0 ELSE 1 END, h.id"
        );
        $blankSt->execute([$lessonId, $level, $blankTitle, $blankTitle]);
        $blanks = $blankSt->fetchAll();
        if ($blanks && (count($blanks) === 1 || (string)$blanks[0]['title'] === $blankTitle)) {
          $homeworkId = (int)$blanks[0]['id'];
          $pdo->prepare('UPDATE homeworks SET title = ?, level = ?, deadline = ? WHERE id = ?')
              ->execute([$title, $level, $deadline, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM homeworks WHERE lesson_id = ?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare('INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)')
              ->execute([$lessonId, $level, $title, $deadline, (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      } else {
        $pdo->prepare('UPDATE homeworks SET level = ?, deadline = ? WHERE id = ?')
            ->execute([$level, $deadline, $homeworkId]);
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id = ?');
      $countSt->execute([$homeworkId]);
      $created = false;
      if ((int)$countSt->fetchColumn() === 0) {
        foreach ($questions as $position => $question) {
          $insertQuestion->execute([
            $homeworkId,
            $question['type'],
            $question['text'],
            $question['options'] !== null ? json_encode($question['options'], JSON_UNESCAPED_UNICODE) : null,
            json_encode($question['correct'], JSON_UNESCAPED_UNICODE),
            $question['solution'],
            $question['hint'],
            (int)($question['points'] ?? 1),
            !empty($question['optional']) ? 1 : 0,
            $position,
          ]);
        }
        $created = true;
      }
      return [$homeworkId, $created];
    };

    [$baseId, $baseCreated] = $ensureHomework('normal', 'Communication. 25-29 (база)', english_august_final_base_questions());
    [$mediumId, $mediumCreated] = $ensureHomework('medium', 'Communication. 25-29 (средний)', english_august_final_medium_questions());

    $audioUrl = '/api/file.php?path=questions%2Fenglish-august-final-listening.mp3';
    $audioQuestionSt = $pdo->prepare('SELECT id FROM questions WHERE homework_id = ? AND position = 0 ORDER BY id LIMIT 1');
    $audioQuestionSt->execute([$mediumId]);
    $audioQuestionId = (int)($audioQuestionSt->fetchColumn() ?: 0);
    $audioAdded = false;
    if ($audioQuestionId > 0) {
      $hasAudio = $pdo->prepare("SELECT 1 FROM question_attachments WHERE question_id = ? AND kind = 'audio' AND url = ? LIMIT 1");
      $hasAudio->execute([$audioQuestionId, $audioUrl]);
      if (!$hasAudio->fetchColumn()) {
        $pdo->prepare(
          "INSERT INTO question_attachments (question_id, kind, name, size, url, position)
           VALUES (?,'audio','Аудиозапись','9,9 МБ',?,0)"
        )->execute([$audioQuestionId, $audioUrl]);
        $audioAdded = true;
      }
    }

    $chartUrl = '/img/english-august-final/task38-chart.svg';
    $chartQuestionSt = $pdo->prepare('SELECT id FROM questions WHERE homework_id = ? AND position = 1 ORDER BY id LIMIT 1');
    $chartQuestionSt->execute([$mediumId]);
    $chartQuestionId = (int)($chartQuestionSt->fetchColumn() ?: 0);
    if ($chartQuestionId > 0) {
      $hasChart = $pdo->prepare("SELECT 1 FROM question_attachments WHERE question_id = ? AND kind = 'image' AND url = ? LIMIT 1");
      $hasChart->execute([$chartQuestionId, $chartUrl]);
      if (!$hasChart->fetchColumn()) {
        $pdo->prepare(
          "INSERT INTO question_attachments (question_id, kind, name, size, url, position)
           VALUES (?,'image','Диаграмма к заданию 38',NULL,?,0)"
        )->execute([$chartQuestionId, $chartUrl]);
      }
    }

    live_content_touch();
    out('  + Английский август, последний блок «' . (string)$block['block_title'] . '»: база - '
      . ($baseCreated ? 'создана' : 'уже была') . ' (' . count(english_august_final_base_questions()) . ' вопросов), средний - '
      . ($mediumCreated ? 'создан' : 'уже был') . ' (' . count(english_august_final_medium_questions()) . ' вопросов), аудио - '
      . ($audioAdded ? 'добавлено' : 'уже было') . '; урок «' . $lessonTitle . '»; дедлайн: 31.08.2026 23:59');
  },

  // v98 - заменяет только PDF «Список лексики» последнего августовского
  // урока английского на точечно очищенную копию без центрального водяного знака.
  98 => function (PDO $pdo, string $driver) {
    $find = $pdo->query(
      "SELECT mt.id
       FROM materials mt
       JOIN lessons l ON l.id = mt.lesson_id
       JOIN blocks b ON b.id = l.block_id
       JOIN months m ON m.id = b.month_id
       JOIN courses c ON c.id = b.course_id
       JOIN subjects s ON s.id = c.subject_id
       WHERE s.name = 'Английский язык'
         AND c.title LIKE 'Летняя школа%англ%'
         AND (m.name LIKE '%Август%' OR m.name LIKE '%август%')
         AND (l.title LIKE '%Communication%' OR b.title LIKE '%Communication%')
         AND mt.name = 'Список лексики'
       ORDER BY mt.id DESC LIMIT 1"
    );
    $materialId = (int)($find ? $find->fetchColumn() : 0);
    if ($materialId <= 0) {
      throw new RuntimeException('Не найден материал «Список лексики» в августовском уроке Communication');
    }

    $url = '/api/file.php?path=materials%2Fm81_5601f10bf09b2701_clean.pdf';
    $pdo->prepare('UPDATE materials SET url = ?, size = ? WHERE id = ?')
        ->execute([$url, '879 КБ', $materialId]);
    live_content_touch();
    out('  + «Список лексики» в уроке Communication заменён на очищенный PDF');
  },

  // v99 — собственная видеоплатформа: общая админская видеотека, очередь
  // транскодирования HLS и персональный прогресс просмотра ученика.
  99 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS videos (
      id {PK},
      lesson_id INT,
      title VARCHAR(255) NOT NULL,
      original_name VARCHAR(255),
      source_key VARCHAR(500),
      master_key VARCHAR(500),
      poster_key VARCHAR(500),
      mime VARCHAR(150),
      size_bytes BIGINT DEFAULT 0,
      status VARCHAR(24) NOT NULL DEFAULT 'uploading',
      process_percent INT NOT NULL DEFAULT 0,
      process_stage VARCHAR(160),
      duration_sec INT DEFAULT 0,
      width INT DEFAULT 0,
      height INT DEFAULT 0,
      qualities TEXT,
      error TEXT,
      created_at {TS},
      updated_at VARCHAR(30)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS video_progress (
      id {PK},
      user_id INT NOT NULL,
      video_id INT NOT NULL,
      position_sec INT NOT NULL DEFAULT 0,
      duration_sec INT NOT NULL DEFAULT 0,
      completed INT NOT NULL DEFAULT 0,
      updated_at VARCHAR(30),
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (video_id) REFERENCES videos(id)
    ) {ENGINE}", $driver));

    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS video_events (
      id {PK},
      user_id INT NOT NULL,
      video_id INT NOT NULL,
      event VARCHAR(24) NOT NULL,
      position_sec INT NOT NULL DEFAULT 0,
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (video_id) REFERENCES videos(id)
    ) {ENGINE}", $driver));

    if (!column_exists($pdo, $driver, 'lessons', 'video_source'))
      $pdo->exec("ALTER TABLE lessons ADD COLUMN video_source VARCHAR(20) DEFAULT 'kinescope'");
    if (!column_exists($pdo, $driver, 'lessons', 'video_id'))
      $pdo->exec("ALTER TABLE lessons ADD COLUMN video_id INT");

    // Старые уроки без Kinescope не должны выглядеть так, будто для них уже
    // выбран источник. Сам kinescope_id сохраняем: при переключении на наш
    // сервис администратор сможет в любой момент вернуться к старому ролику.
    $pdo->exec("UPDATE lessons
                SET video_source = CASE
                  WHEN kinescope_id IS NOT NULL AND TRIM(kinescope_id) <> '' THEN 'kinescope'
                  ELSE 'none'
                END");

    try { $pdo->exec('CREATE UNIQUE INDEX uq_video_progress_user_video ON video_progress(user_id, video_id)'); } catch (Throwable $e) { /* уже есть */ }
    try { $pdo->exec('CREATE INDEX idx_videos_lesson ON videos(lesson_id)'); } catch (Throwable $e) { /* уже есть */ }
    try { $pdo->exec('CREATE INDEX idx_videos_status ON videos(status)'); } catch (Throwable $e) { /* уже есть */ }
    try { $pdo->exec('CREATE INDEX idx_video_events_lookup ON video_events(user_id, video_id, created_at)'); } catch (Throwable $e) { /* уже есть */ }
    out('  + Видеотека, очередь HLS и прогресс просмотра готовы');
  },

  // v100 — расширенный каталог достижений. Прогресс по ним пересчитывается
  // из реальных сдач/уроков/серии, поэтому работает и для старых аккаунтов.
  100 => function (PDO $pdo, string $driver) {
    $items = [
      ['homeworks_3','Хорошее начало','Сдай 3 разных домашних задания','pencil',40,3,1],
      ['homeworks_25','На полном ходу','Сдай 25 разных домашних заданий','rocket',180,25,3],
      ['homeworks_50','Железная дисциплина','Сдай 50 разных домашних заданий','crown',300,50,4],
      ['perfect_5','Точно в цель','Реши 5 домашних заданий на максимум','target',120,5,6],
      ['perfect_10','Безошибочный','Реши 10 домашних заданий на максимум','star',220,10,7],
      ['mocks_3','Экзаменационный ритм','Пройди 3 разных пробника','clipboard',80,3,9],
      ['mocks_10','Ветеран пробников','Пройди 10 разных пробников','medal',250,10,11],
      ['lessons_5','Вошёл во вкус','Заверши 5 уроков','book',50,5,12],
      ['lessons_15','Знаток маршрута','Заверши 15 уроков','layers',120,15,13],
      ['lessons_30','Большой путь','Заверши 30 уроков','crown',250,30,14],
      ['streak_3','Три дня подряд','Занимайся 3 дня подряд','sunrise',45,3,15],
      ['streak_14','Две недели в строю','Занимайся 14 дней подряд','flame',180,14,17],
      ['streak_30','Месяц дисциплины','Занимайся 30 дней подряд','crown',400,30,18],
      ['crystals_2000','Кристальный запас','Заработай 2000 кристаллов','gem',300,2000,20],
      ['early_homeworks_5','Раньше дедлайна','Сдай 5 домашних заданий до дедлайна','calendar',100,5,21],
    ];
    $exists = $pdo->prepare('SELECT 1 FROM achievements WHERE code=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO achievements(code,title,description,icon,reward,target,position) VALUES(?,?,?,?,?,?,?)');
    foreach ($items as $item) { $exists->execute([$item[0]]); if (!$exists->fetchColumn()) $insert->execute($item); }
    $positions = ['first_homework'=>0,'homeworks_10'=>2,'perfect_test'=>5,'mock_done'=>8,'mocks_5'=>10,'streak_7'=>16,'crystals_500'=>19];
    $pos = $pdo->prepare('UPDATE achievements SET position=? WHERE code=?');
    foreach ($positions as $code=>$position) $pos->execute([$position,$code]);
    $pdo->exec("UPDATE achievements SET description='Отправь первое домашнее задание',target=1 WHERE code='first_homework'");
    $pdo->exec("UPDATE achievements SET description='Заработай 500 кристаллов' WHERE code='crystals_500'");
    out('  + Добавлены достижения за ДЗ, уроки, пробники, серию и дедлайны');
  },

  // v101 — пользовательское название уровня course → month → blocks меняется
  // с «блоков» на «разделы». Технические таблицы и ключи сохраняем прежними,
  // чтобы не ломать API и накопленные связи.
  101 => function (PDO $pdo, string $driver) {
    $rows = $pdo->query("SELECT id, title FROM blocks WHERE title = 'Новый блок' OR title LIKE 'Блок %'")->fetchAll();
    $renameBlock = $pdo->prepare('UPDATE blocks SET title = ? WHERE id = ?');
    $renamed = 0;
    foreach ($rows as $row) {
      $title = trim((string)$row['title']);
      $next = $title === 'Новый блок' ? 'Новый раздел' : null;
      if ($next === null && preg_match('/^Блок\s+(\d+)$/ui', $title, $m)) $next = 'Раздел ' . $m[1];
      if ($next === null) continue;
      $renameBlock->execute([$next, (int)$row['id']]);
      $renamed++;
    }

    $category = $pdo->prepare("SELECT id FROM help_categories WHERE name IN ('Курсы, месяцы, разделы и уроки','Курсы, месяцы, блоки и уроки') ORDER BY (name = 'Курсы, месяцы, разделы и уроки') DESC, id LIMIT 1");
    $category->execute();
    $categoryId = (int)($category->fetchColumn() ?: 0);
    if ($categoryId > 0) {
      $pdo->prepare('UPDATE help_categories SET name = ? WHERE id = ?')->execute(['Курсы, месяцы, разделы и уроки', $categoryId]);
      $answers = [
        'Что открывается при входе в курс?' => 'Сначала открывается обзор текущего месяца: его даты, количество разделов и уроков, прогресс и кнопка просмотра расписания. Урок выбирается отдельно в программе справа.',
        'Как открыть урок?' => 'Раскрой нужный раздел в программе курса и нажми на урок. Прямая ссылка из расписания также может сразу открыть конкретный урок.',
      ];
      $updateAnswer = $pdo->prepare('UPDATE help_qa SET answer = ? WHERE category_id = ? AND question = ?');
      foreach ($answers as $question => $answer) $updateAnswer->execute([$answer, $categoryId, $question]);
    }

    live_content_touch();
    out("  + Термин «блок» заменён на «раздел»; переименовано стандартных названий: $renamed");
  },

  // v102 — верхний уровень программы может быть календарным месяцем или
  // учебным блоком. В основных курсах существующие периоды считаем блоками,
  // во всех остальных сохраняем привычные месяцы.
  102 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'months', 'kind'))
      $pdo->exec("ALTER TABLE months ADD COLUMN kind VARCHAR(12) DEFAULT 'month'");
    $pdo->exec("UPDATE months SET kind = 'month' WHERE kind IS NULL OR kind NOT IN ('month','block')");
    $pdo->exec("UPDATE months SET kind = 'block' WHERE course_id IN (SELECT id FROM courses WHERE type = 'Основные')");

    $findCategory = $pdo->prepare("SELECT id FROM help_categories WHERE name IN ('Курсы, периоды, разделы и уроки','Курсы, месяцы, разделы и уроки') ORDER BY id LIMIT 1");
    $findCategory->execute();
    $categoryId = (int)($findCategory->fetchColumn() ?: 0);
    if ($categoryId > 0) {
      $pdo->prepare('UPDATE help_categories SET name = ? WHERE id = ?')->execute(['Курсы, периоды, разделы и уроки', $categoryId]);
      $answers = [
        'Что такое период курса?' => 'В летней школе периодом обычно является месяц, а в основном курсе — учебный блок. Внутри каждого периода находятся разделы и уроки.',
        'Что открывается при входе в курс?' => 'Сначала открывается обзор текущего периода: его название, даты, количество разделов и уроков, прогресс и кнопка просмотра расписания. Урок выбирается отдельно в программе справа.',
        'Как переключить период курса?' => 'Используй стрелки возле названия периода в правой части страницы. После переключения откроется обзор выбранного месяца или блока, а не случайный урок из другого периода.',
        'Как вернуться от урока к информации о периоде?' => 'Нажми «К обзору месяца» или «К обзору блока» в верхней строке рядом с кнопкой «Все курсы».',
      ];
      $findQuestion = $pdo->prepare('SELECT id FROM help_qa WHERE category_id = ? AND question = ? LIMIT 1');
      $insertQuestion = $pdo->prepare('INSERT INTO help_qa(category_id,question,answer,position) VALUES(?,?,?,?)');
      $updateQuestion = $pdo->prepare('UPDATE help_qa SET answer = ? WHERE id = ?');
      $position = (int)$pdo->query("SELECT COALESCE(MAX(position),-1)+1 FROM help_qa WHERE category_id = $categoryId")->fetchColumn();
      foreach ($answers as $question => $answer) {
        $findQuestion->execute([$categoryId, $question]);
        $questionId = (int)($findQuestion->fetchColumn() ?: 0);
        if ($questionId > 0) $updateQuestion->execute([$answer, $questionId]);
        else $insertQuestion->execute([$categoryId, $question, $answer, $position++]);
      }
      $pdo->prepare("DELETE FROM help_qa WHERE category_id = ? AND question IN ('Как переключить месяц курса?','Как вернуться от урока к информации о месяце?')")
          ->execute([$categoryId]);
      $pdo->prepare("UPDATE help_qa SET answer = ? WHERE category_id = ? AND question = 'Где посмотреть расписание?'")
          ->execute(['Открой раздел «Расписание». В курсе также есть кнопка «Посмотреть расписание» на обзоре периода, если администратор прикрепил его изображение.', $categoryId]);
    }
    $pdo->prepare("UPDATE help_qa SET answer = ? WHERE question = 'Как найти нужную домашку среди большого списка?'")
        ->execute(['Используй поиск и фильтры по предмету, периоду, уровню и статусу. Периодом может быть месяц или учебный блок. Поиск работает по названию работы.']);
    live_content_touch();
    out('  + Периоды курса поддерживают месяцы и блоки; основные курсы переведены на блоки');
  },

  // v103 — справка для новой библиотеки: она автоматически собирается
  // из материалов доступных уроков, а не заполняется отдельным каталогом.
  103 => function (PDO $pdo, string $driver) {
    $findCategory = $pdo->prepare("SELECT id FROM help_categories WHERE name = 'Библиотека и банк заданий' ORDER BY id LIMIT 1");
    $findCategory->execute();
    $categoryId = (int)($findCategory->fetchColumn() ?: 0);
    if ($categoryId > 0) {
      $answers = [
        'Как устроена библиотека?' => 'Библиотека автоматически собирает материалы из доступных и уже открытых уроков. Сначала выбери предмет, затем нужный урок: внутри будут все файлы и ссылки, которые прикреплены к этому уроку.',
        'Почему в библиотеке нет материала?' => 'Проверь доступ к курсу и дату открытия урока. Материал появляется только после того, как администратор прикрепил его внутри урока; отдельно загружать копию в библиотеку не нужно.',
        'Как найти материал?' => 'Выбери предмет или используй поиск по названию урока, курса, периода либо файла. Название группы материалов совпадает с названием урока.',
      ];
      $findQuestion = $pdo->prepare('SELECT id FROM help_qa WHERE category_id = ? AND question = ? LIMIT 1');
      $insertQuestion = $pdo->prepare('INSERT INTO help_qa(category_id,question,answer,position) VALUES(?,?,?,?)');
      $updateQuestion = $pdo->prepare('UPDATE help_qa SET answer = ? WHERE id = ?');
      $position = (int)$pdo->query("SELECT COALESCE(MAX(position),-1)+1 FROM help_qa WHERE category_id = $categoryId")->fetchColumn();
      foreach ($answers as $question => $answer) {
        $findQuestion->execute([$categoryId, $question]);
        $questionId = (int)($findQuestion->fetchColumn() ?: 0);
        if ($questionId > 0) $updateQuestion->execute([$answer, $questionId]);
        else $insertQuestion->execute([$categoryId, $question, $answer, $position++]);
      }
      $pdo->prepare("DELETE FROM help_qa WHERE category_id = ? AND question IN ('Когда откроются библиотека и банк заданий?','Что будет в библиотеке?','Почему замок остаётся после 1 сентября?')")
          ->execute([$categoryId]);
    }

    $findAiCategory = $pdo->prepare("SELECT id FROM help_categories WHERE name = 'ИИ-чат и ИИ-тренажёр' ORDER BY id LIMIT 1");
    $findAiCategory->execute();
    $aiCategoryId = (int)($findAiCategory->fetchColumn() ?: 0);
    if ($aiCategoryId > 0) {
      $pdo->prepare("DELETE FROM help_qa WHERE category_id = ? AND question = 'Когда откроется ИИ-тренажёр?'")->execute([$aiCategoryId]);
      $question = 'Как работает ИИ-тренажёр?';
      $answer = 'В режиме решения задач ученик получает добавленную администратором задачу, отправляет ответ, а ИИ сравнивает его с эталоном и объясняет результат. В режиме вопрос-ответ ИИ создаёт новые вопросы по темам и примерам, заданным администратором.';
      $findQuestion = $pdo->prepare('SELECT id FROM help_qa WHERE category_id = ? AND question = ? LIMIT 1');
      $findQuestion->execute([$aiCategoryId, $question]);
      $questionId = (int)($findQuestion->fetchColumn() ?: 0);
      if ($questionId > 0) $pdo->prepare('UPDATE help_qa SET answer = ? WHERE id = ?')->execute([$answer, $questionId]);
      else {
        $position = (int)$pdo->query("SELECT COALESCE(MAX(position),-1)+1 FROM help_qa WHERE category_id = $aiCategoryId")->fetchColumn();
        $pdo->prepare('INSERT INTO help_qa(category_id,question,answer,position) VALUES(?,?,?,?)')->execute([$aiCategoryId, $question, $answer, $position]);
      }
    }

    live_content_touch();
    out('  + Справка обновлена под автоматическую библиотеку уроков');
  },

  // v104 — сентябрь наступил: убираем из базы ИИ-поддержки уже неверные
  // ответы о временной блокировке разделов.
  104 => function (PDO $pdo, string $driver) {
    $obsoleteCategory = $pdo->prepare("SELECT id FROM help_categories WHERE name = 'Разделы, закрытые до 1 сентября' LIMIT 1");
    $obsoleteCategory->execute();
    $obsoleteCategoryId = (int)($obsoleteCategory->fetchColumn() ?: 0);
    if ($obsoleteCategoryId > 0) {
      $pdo->prepare('DELETE FROM help_qa WHERE category_id = ?')->execute([$obsoleteCategoryId]);
      $pdo->prepare('DELETE FROM help_categories WHERE id = ?')->execute([$obsoleteCategoryId]);
    }

    $updates = [
      'Когда откроется раздел «Пробники»?' => ['Почему я не вижу раздел «Пробники»?', 'Раздел уже открыт. Конкретный пробник может не отображаться без доступа к связанному курсу или персонального назначения ученику.'],
      'Когда откроется Стобалльный диск?' => ['Почему я не вижу Стобалльный диск?', 'Раздел уже открыт. Если он не появился после обновления, проверь вход в аккаунт и обратись в поддержку.'],
    ];
    foreach ($updates as $oldQuestion => [$newQuestion, $answer]) {
      $pdo->prepare('UPDATE help_qa SET question = ?, answer = ? WHERE question = ?')->execute([$newQuestion, $answer, $oldQuestion]);
    }
    $pdo->prepare("UPDATE help_qa SET answer = ? WHERE question = 'Не работает кнопка или раздел.'")
        ->execute(['Сначала обнови страницу с очисткой кэша. Если кнопка должна работать, пришли в поддержку скриншот и адрес страницы.']);

    live_content_touch();
    out('  + Устаревшие ответы о закрытии разделов до сентября удалены');
  },

  // v105 — библиотека показывает материалы только основных курсов.
  105 => function (PDO $pdo, string $driver) {
    $pdo->prepare("UPDATE help_qa SET answer = ? WHERE question = 'Как устроена библиотека?'")
        ->execute(['Библиотека автоматически собирает материалы из доступных и уже открытых уроков основных курсов. Материалы летней школы, вводных и других типов курсов здесь не показываются. Сначала выбери предмет, затем нужный урок: внутри будут все прикреплённые файлы и ссылки.']);
    live_content_touch();
    out('  + Справка библиотеки ограничена материалами основных курсов');
  },

  // v106 — одна глобальная ИИ-модель, полноценная база тем ЕГЭ и история
  // формулировок для защиты генератора от повторов в точном тематическом срезе.
  106 => function (PDO $pdo, string $driver) {
    $pdo->exec(ddl("CREATE TABLE IF NOT EXISTS trainer_qa_history (
      id {PK},
      user_id INT NOT NULL,
      subject_id INT NOT NULL,
      level INT NOT NULL,
      topic_id INT NOT NULL,
      question_text TEXT NOT NULL,
      created_at {TS},
      FOREIGN KEY (user_id) REFERENCES users(id),
      FOREIGN KEY (subject_id) REFERENCES subjects(id),
      FOREIGN KEY (topic_id) REFERENCES trainer_qa_topics(id)
    ) {ENGINE}", $driver));
    $pdo->exec('CREATE INDEX idx_trainer_qa_history_scope ON trainer_qa_history(subject_id, level, topic_id, id)');

    if ($driver === 'mysql') {
      $pdo->exec("INSERT INTO app_settings(k,v) VALUES ('ai_model','z-ai/glm-5.3-flash') ON DUPLICATE KEY UPDATE v=VALUES(v)");
    } else {
      $pdo->exec("INSERT INTO app_settings(k,v) VALUES ('ai_model','z-ai/glm-5.3-flash') ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    }

    // [slug, level, topic, examples/instructions]. Каждый элемент — отдельный
    // тематический пул, поэтому история повторов никогда не смешивает предметы.
    $catalog = [
      // Профильная математика
      ['math',1,'Вычисления, дроби и проценты','Короткие вычислительные задачи с обыкновенными и десятичными дробями, процентами, отношениями и пропорциями. Четыре правдоподобных числовых варианта.'],
      ['math',1,'Простейшие уравнения','Линейные уравнения, простые квадратные уравнения и уравнения, сводящиеся к ним одним-двумя действиями.'],
      ['math',1,'Чтение графиков и таблиц','Определение значения величины, максимума, минимума или промежутка по словесно описанному графику либо небольшой таблице.'],
      ['math',1,'Базовая планиметрия','Углы, треугольники, четырёхугольники, окружность, площади и теорема Пифагора на один основной факт.'],
      ['math',2,'Степени, корни и логарифмы','Преобразования степенных, иррациональных, показательных и простых логарифмических выражений.'],
      ['math',2,'Квадратные уравнения и системы','Корни квадратного уравнения, теорема Виета, простые системы двух уравнений и отбор корней.'],
      ['math',2,'Вероятность и комбинаторика','Классическая вероятность, противоположные события, независимые испытания и простой подсчёт вариантов.'],
      ['math',2,'Функции и их свойства','Область определения, нули, промежутки знакопостоянства, монотонность и преобразования графиков элементарных функций.'],
      ['math',3,'Тригонометрия','Значения и преобразования тригонометрических выражений, простые уравнения, формулы приведения и отбор решений.'],
      ['math',3,'Производная и исследование функции','Геометрический и физический смысл производной, экстремумы, монотонность и наибольшее значение.'],
      ['math',3,'Неравенства','Рациональные, показательные и логарифмические неравенства с обязательным учётом ОДЗ.'],
      ['math',3,'Стереометрия','Расстояния, углы, площади и объёмы многогранников и тел вращения в стандартной конфигурации.'],
      ['math',4,'Экономические задачи','Кредиты, вклады, платежи, проценты и оптимизация с полноценной математической моделью.'],
      ['math',4,'Задачи с параметром','Число корней, взаимное расположение графиков и условия существования решений при разных параметрах.'],
      ['math',4,'Сложная планиметрия','Подобие, окружности, касательные, вписанные конструкции, отношения площадей и доказательные рассуждения.'],
      ['math',4,'Сложные вероятностные модели','Условная вероятность, формула полной вероятности, Бернулли и многошаговые случайные эксперименты.'],
      ['math',5,'Смешанная задача высокого уровня','Комбинация алгебры, функций и ограничений; требуется выбрать метод и проверить все условия.'],
      ['math',5,'Олимпиадная арифметика и делимость','Целые числа, остатки, делимость, оценки и перебор с доказательным обоснованием.'],
      ['math',5,'Продвинутая геометрия','Нетипичная планиметрическая или стереометрическая конфигурация с несколькими связанными этапами.'],
      ['math',5,'Параметр и оптимизация','Сложная задача с параметром, исследованием функции, касанием либо поиском экстремального значения.'],

      // Русский язык
      ['rus',1,'Орфоэпические нормы','Ударение в общеупотребительных словах и формах слов; один верный вариант среди типичных ловушек ЕГЭ.'],
      ['rus',1,'Лексическое значение слова','Выбор значения слова в контексте, устранение плеоназма или неверной сочетаемости.'],
      ['rus',1,'Корни и приставки','Проверяемые, непроверяемые и чередующиеся гласные; приставки ПРЕ-/ПРИ-, З-/С-.'],
      ['rus',1,'Окончания и суффиксы','Личные окончания глаголов, суффиксы причастий, прилагательных и существительных.'],
      ['rus',2,'Грамматические нормы','Формы числительных, местоимений, степеней сравнения, согласование и управление.'],
      ['rus',2,'Слитное, раздельное и дефисное написание','НЕ/НИ, производные предлоги, союзы, частицы, наречия и сложные слова.'],
      ['rus',2,'Н и НН','Н/НН в прилагательных, причастиях, наречиях и кратких формах с контекстом.'],
      ['rus',2,'Пунктуация в простом предложении','Однородные члены, обращения, вводные слова, обособленные определения и обстоятельства.'],
      ['rus',3,'Синтаксические нормы','Нарушения согласования, управления, связи подлежащего со сказуемым, причастных и деепричастных оборотов.'],
      ['rus',3,'Пунктуация в сложном предложении','ССП, СПП, БСП и предложения с разными видами связи; расстановка всех знаков.'],
      ['rus',3,'Средства связи предложений','Лексические повторы, местоимения, союзы, наречия и формы слов как средства связи текста.'],
      ['rus',3,'Функционально-смысловые типы речи','Повествование, описание, рассуждение и их сочетание по небольшому фрагменту.'],
      ['rus',4,'Средства выразительности','Тропы, синтаксические средства, приёмы и фигуры речи в формате задания 26.'],
      ['rus',4,'Авторская позиция и проблема текста','Определение проблемы, позиции автора и смысловой связи между примерами-иллюстрациями.'],
      ['rus',4,'Сложные случаи пунктуации','Стык союзов, неоднородное подчинение, присоединительные конструкции, тире и двоеточие.'],
      ['rus',4,'Сложные случаи орфографии','Исключения и контекстные различия НЕ/НИ, ПОЛ-/ПОЛУ-, наречий, причастий и омонимичных частей речи.'],
      ['rus',5,'Комплексный языковой анализ','Один вопрос объединяет морфологию, синтаксис, лексику и орфографию; требуется точное обоснование.'],
      ['rus',5,'Редактирование сложного текста','Найти и исправить несколько типов речевых и грамматических ошибок без искажения смысла.'],
      ['rus',5,'Логика комментария к тексту','Выбрать сильную пару примеров, корректно объяснить их и определить смысловую связь.'],
      ['rus',5,'Экспертная оценка сочинения','Определить наиболее существенный недочёт фрагмента сочинения по критериям ЕГЭ и лучший способ исправления.'],

      // Обществознание
      ['social',1,'Человек и общество','Природное и общественное в человеке, деятельность, познание, мировоззрение, культура и типы обществ.'],
      ['social',1,'Базовые понятия экономики','Потребности, ресурсы, факторы производства, экономические системы, спрос и предложение.'],
      ['social',1,'Социальные отношения','Социальные группы, статусы и роли, социализация, мобильность, нормы и отклоняющееся поведение.'],
      ['social',1,'Основы политики и права','Власть, государство, политическая система, право, норма права и основные отрасли.'],
      ['social',2,'Рынок и конкуренция','Равновесная цена, эластичность на качественном уровне, конкуренция, издержки и поведение фирмы.'],
      ['social',2,'Политические институты','Формы государства, партии, выборы, политическое участие, гражданское общество и СМИ.'],
      ['social',2,'Конституционный строй России','Основы строя, права и обязанности, федеративное устройство и органы публичной власти.'],
      ['social',2,'Правовые отношения','Правоспособность, дееспособность, юридические факты, правонарушение и юридическая ответственность.'],
      ['social',3,'Финансы и макроэкономика','Банки, ценные бумаги, инфляция, безработица, ВВП, налоги, бюджет и денежно-кредитная политика.'],
      ['social',3,'Социальная структура и конфликты','Стратификация, этнос, семья, демография, молодёжь, социальный контроль и разрешение конфликтов.'],
      ['social',3,'Гражданское и трудовое право','Сделки, собственность, обязательства, защита потребителя, трудовой договор и трудовые споры.'],
      ['social',3,'Административное и уголовное право','Состав правонарушения, виды наказаний, участники процесса и особенности ответственности несовершеннолетних.'],
      ['social',4,'Анализ социальной ситуации','Развёрнутый кейс: распознать явление по признакам и применить несколько обществоведческих понятий.'],
      ['social',4,'Аргументация и примеры','Выбрать корректные факты общественной жизни для подтверждения теоретического положения.'],
      ['social',4,'Полномочия органов власти','Разграничить полномочия Президента, Федерального Собрания, Правительства, судов и субъектов РФ.'],
      ['social',4,'Налоговое, семейное и процессуальное право','Сложные правовые ситуации с определением отрасли, участников, прав и последствий.'],
      ['social',5,'План по обществоведческой теме','Определить, какие пункты и подпункты раскрывают тему по существу и соответствуют требованиям задания 24.'],
      ['social',5,'Мини-исследование источника','Извлечь идеи из сложного текста, применить теорию и сделать обоснованный вывод без подмены тезиса.'],
      ['social',5,'Междисциплинарный кейс','Ситуация на стыке экономики, политики, социальных отношений и права с несколькими верными связями.'],
      ['social',5,'Оценка сложной аргументации','Найти логическую или фактическую ошибку в рассуждении и выбрать точное обществоведческое исправление.'],

      // Английский язык
      ['english',1,'Базовая лексика в контексте','Выбор общеупотребительного слова по контексту: семья, учёба, досуг, путешествия, здоровье и технологии.'],
      ['english',1,'Базовая грамматика','Present/Past/Future forms, pronouns, articles, plurals и простые степени сравнения.'],
      ['english',1,'Словообразование','Образовать подходящую часть речи с типичными приставками и суффиксами уровня ЕГЭ.'],
      ['english',1,'Короткое чтение','Небольшой текст и вопрос на явно выраженную основную мысль или конкретную деталь.'],
      ['english',2,'Видо-временные формы','Выбор времени, согласование времён, passive voice и формы неличных глаголов в связном тексте.'],
      ['english',2,'Лексическая сочетаемость','Collocations, dependent prepositions, phrasal verbs и близкие по значению слова.'],
      ['english',2,'Логика текста','Средства связи, референция местоимений и выбор пропущенного предложения в небольшом тексте.'],
      ['english',2,'Понимание позиции автора','Вывод по абзацу, отношение автора и различие между фактом и мнением.'],
      ['english',3,'Сложная грамматика','Conditionals, wish, reported speech, modal verbs, inversion и complex object/subject.'],
      ['english',3,'Продвинутая лексика','Устойчивые выражения, фразовые глаголы и смысловые оттенки слов уровня B2.'],
      ['english',3,'Развёрнутое чтение','Inference, цель автора, значение слова по контексту и соответствие утверждений большому тексту.'],
      ['english',3,'Электронное письмо','Структура, полнота ответов, вопросы адресату, регистр и языковое оформление задания 37.'],
      ['english',4,'Письменное высказывание по таблице или диаграмме','Введение, сравнения, проблема и решение, мнение автора и логика задания 38.'],
      ['english',4,'Устная часть: интервью','Точный, грамматически полный и естественный ответ на вопрос интервьюера.'],
      ['english',4,'Устная часть: описание и сравнение фото','Организация монолога, сравнение, преимущества и недостатки, предпочтение и связность.'],
      ['english',4,'Редактирование текста уровня B2+','Найти наиболее существенную грамматическую, лексическую или логическую ошибку в абзаце.'],
      ['english',5,'Академическое чтение C1','Сложные выводы, импликации, авторская ирония, структура аргумента и значение редкой лексики.'],
      ['english',5,'Лексико-грамматическая трансформация','Переформулировать смысл с заданным словом, сохранив грамматику и оттенок исходной фразы.'],
      ['english',5,'Экспертная оценка письменной работы','Определить нарушение содержания, организации или языка по критериям ЕГЭ и лучший вариант правки.'],
      ['english',5,'Смешанный экзаменационный кейс','Один сложный вопрос объединяет понимание текста, лексику и грамматику без неоднозначности.'],

      // История
      ['history',1,'Хронология истории России','Определение последовательности ключевых событий IX–XXI веков и соотнесение события с веком.'],
      ['history',1,'Исторические личности','Соотнести правителя, государственного деятеля, полководца или деятеля культуры с событием и результатом.'],
      ['history',1,'Термины и понятия','Узнать исторический термин по точному определению или подобрать определение к термину.'],
      ['history',1,'Культура России','Произведения архитектуры, живописи, литературы и науки, их авторы и эпохи.'],
      ['history',2,'Древняя Русь и раздробленность','Государственность, принятие христианства, Русская Правда, княжества, монгольское нашествие и Орда.'],
      ['history',2,'Московское государство XV–XVII веков','Объединение земель, реформы Ивана IV, Смутное время, первые Романовы и Соборное уложение.'],
      ['history',2,'Российская империя XVIII–XIX веков','Реформы, войны, общественное движение, модернизация и внешняя политика от Петра I до Николая II.'],
      ['history',2,'Россия и СССР в XX веке','Революции, Гражданская война, СССР, Великая Отечественная война, оттепель, застой и перестройка.'],
      ['history',3,'Работа с историческим источником','Определить эпоху, автора, обстоятельства и положения фрагмента документа по внутренним признакам.'],
      ['history',3,'Историческая карта','Определить войну, поход, территорию, направление движения и связанные события по описанию карты.'],
      ['history',3,'Причины и последствия событий','Выбрать корректную причинно-следственную связь и отличить причину, повод, ход и итог.'],
      ['history',3,'Всеобщая история в контексте России','Синхронизировать российские события с важнейшими событиями Европы, Азии и Америки.'],
      ['history',4,'Аргументация исторической точки зрения','Подобрать конкретные факты за и против тезиса, не подменяя аргумент общим рассуждением.'],
      ['history',4,'Сравнение исторических процессов','Сходства и различия реформ, войн, политических режимов и общественных движений разных эпох.'],
      ['history',4,'Сложный анализ источника','Выявить позицию автора, достоверность, ограничения источника и связь с исторической ситуацией.'],
      ['history',4,'Историческая культура и атрибуция','Определить памятник, автора, стиль, время создания и связанного правителя по нескольким признакам.'],
      ['history',5,'Комплексный период истории','Многофакторный вопрос объединяет хронологию, личности, внутреннюю и внешнюю политику одного периода.'],
      ['history',5,'Дискуссионная проблема истории','Оценка конкурирующих трактовок с опорой на конкретные проверяемые факты.'],
      ['history',5,'Нетипичная причинно-следственная задача','Построить длинную цепочку причин и последствий, учитывая альтернативные факторы и хронологию.'],
      ['history',5,'Экспертная проверка исторического ответа','Найти фактическую или логическую ошибку в развёрнутом ответе и выбрать точное исправление.'],
    ];

    // Полный утверждённый кодификатор: каждый пункт — самостоятельная тема,
    // а не объединённая группа. Так выбор действительно разнообразен.
    $topicNames = [
      'math' => [
        1 => ['Числа и вычисления','Дроби и проценты','Степени и корни','Округление','Преобразование простых выражений','Линейные уравнения','Квадратные уравнения','Чтение графиков и таблиц','Базовая планиметрия','Простейшая вероятность и статистика'],
        2 => ['Рациональные уравнения и неравенства','Системы уравнений','Свойства функций','Область определения и множество значений','Арифметическая прогрессия','Геометрическая прогрессия','Тригонометрические значения','Комбинаторика','Координаты и векторы','Базовая стереометрия'],
        3 => ['Иррациональные уравнения','Показательные уравнения','Логарифмические уравнения','Тригонометрические уравнения','Производная и её геометрический смысл','Экстремумы функции','Прикладные задачи','Финансовые модели','Условная вероятность','Окружность, треугольники и четырёхугольники'],
        4 => ['Показательные неравенства','Логарифмические неравенства','Тригонометрические неравенства','Смешанные системы','Исследование функций','Первообразная и интеграл','Площади','Сложная планиметрия','Сечения и углы в пространстве','Объёмы','Геометрические доказательства','Комплексные числа','Матрицы и определители'],
        5 => ['Задачи с параметром','Оптимизация','Сложные финансовые задачи','Теория чисел и делимость','Нестандартная вероятность и комбинаторика','Сложные геометрические конструкции','Функциональные уравнения и неравенства','Задачи на доказательство','Комбинированные задачи из нескольких разделов'],
      ],
      'rus' => [
        1 => ['Основная и дополнительная информация текста','Смысловая связь предложений','Лексическое значение слова','Синонимы и антонимы','Морфемный состав','Части речи','Ударение','Простые орфографические правила','Однородные члены','Вводные слова'],
        2 => ['Паронимы','Лексическая сочетаемость','Речевая избыточность','Формы существительных','Формы прилагательных','Формы числительных','Формы местоимений','Формы глаголов','Корни, приставки и суффиксы','Н и НН','НЕ и НИ','Слитное, раздельное и дефисное написание','Обособленные члены предложения'],
        3 => ['Синтаксические нормы','Согласование и управление','Причастные и деепричастные обороты','Сложносочинённые предложения','Сложноподчинённые предложения','Бессоюзные конструкции','Прямая речь','Средства связи предложений','Функциональные стили','Тропы и выразительные средства'],
        4 => ['Комплексная орфография','Сложная пунктуация','Предложения с разными видами связи','Редактирование лексических и грамматических ошибок','Выразительный синтаксис','Информационная обработка текста, таблиц и инфографики','Проблема текста','Позиция автора'],
        5 => ['Комплексное редактирование текста','Спорные случаи языковой нормы','Авторская пунктуация','Анализ логики и композиции','Комментарий к проблеме','Обоснование собственного отношения','Работа с несколькими источниками','Создание связного аргументированного текста','Речевая точность и стилистика'],
      ],
      'social' => [
        1 => ['Человек и личность','Потребности и способности','Деятельность','Мировоззрение','Познание и истина','Общество как система','Социальные институты','Культура','Мораль','Образование','Наука','Религия','Искусство'],
        2 => ['Экономические системы','Собственность','Спрос и предложение','Рыночное равновесие','Конкуренция','Факторы производства','Фирма, издержки и прибыль','Деньги и банки','Инфляция','Рынок труда и безработица','Налоги','Государственный бюджет'],
        3 => ['Социальные группы','Неравенство и стратификация','Статус и роль','Социальная мобильность','Семья','Социализация','Молодёжь','Этнические общности','Социальные нормы','Отклоняющееся поведение','Социальный контроль','Конфликты','Социальная политика'],
        4 => ['Политическая власть','Политическая система','Государство и его формы','Политические режимы','Демократия','Гражданское общество','Федерализм','Органы власти РФ','Партии и идеологии','Выборы','Политическое участие','СМИ','Политические элиты и лидерство'],
        5 => ['Право, нормы и источники права','Конституция РФ','Гражданство','Права и обязанности','Гражданское право','Семейное право','Трудовое право','Налоговое право','Административное право','Экологическое право','Уголовное право','Виды судопроизводства','Сложные правовые ситуации','Мировая экономика','Финансовые риски','Междисциплинарные кейсы','Сложный план и аргументация'],
      ],
      'english' => [
        1 => ['Семья и повседневная жизнь','Внешность и характер','Школа','Увлечения','Покупки','Еда','Present Simple','Past Simple','Future Simple','Местоимения','Существительные','Числительные','Артикли','Предлоги','Понимание основной мысли текста и аудиозаписи'],
        2 => ['Здоровье','Спорт','Путешествия','Город и деревня','Отношения с друзьями','Continuous','Perfect','Страдательный залог','Модальные глаголы','Степени сравнения','Количественные слова','Базовое словообразование','Поиск конкретной информации'],
        3 => ['Образование и профессия','Молодёжь','Дружба и конфликты','СМИ и социальные сети','Perfect Continuous','Инфинитив и герундий','Причастия','Условные предложения I–II','Придаточные предложения','Фразовые глаголы','Устойчивые сочетания','Подробное понимание текста'],
        4 => ['Экология','Технологии','Интернет-безопасность','Деловое общение','Культура России и англоязычных стран','Косвенная речь','Согласование времён','I wish','Used to и be used to','Условные предложения III типа','Инверсия','Письменная аргументация','Сравнение фотографий и проект в устной части'],
        5 => ['Глобальные проблемы','Наука и цивилизация','Выдающиеся люди','Межкультурное общение','Сложное словообразование','Оттенки лексического значения','Регистр речи','Имплицитная информация','Авторская позиция','Сложное аудирование','Формальное письмо','Полноценное проектное высказывание'],
      ],
      'history' => [
        1 => ['Древняя Русь','Раздробленность и монгольское нашествие','Образование единого государства','Россия XVI–XVII веков','Россия XVIII века','Российская империя XIX века','Россия начала XX века','СССР','Великая Отечественная война','Поздний СССР','Российская Федерация','Основные периоды всеобщей истории'],
        2 => ['Система власти в Древней Руси','Возвышение Москвы','Иван III и Иван IV','Смута','Первые Романовы','Реформы Петра I','Дворцовые перевороты','Екатерина II','Александр I и Николай I','Великие реформы','Контрреформы','Революция 1905 года','Революции 1917 года','Образование СССР','НЭП','Индустриализация и коллективизация','Оттепель','Застой','Перестройка','Реформы России после 1991 года'],
        3 => ['Походы Древней Руси','Борьба с Ордой','Ливонская война','Русско-польские войны','Русско-турецкие войны','Северная война','Войны второй половины XVIII века','Отечественная война 1812 года','Крымская война','Кавказская война','Русско-японская война','Первая мировая война','Гражданская война','Внешняя политика СССР','Великая Отечественная и Вторая мировая войны','Холодная война','Современная международная политика','Войны и революции всеобщей истории'],
        4 => ['Социальный строй Руси','Крепостное право','Сословия','Экономика и торговля','Промышленный переворот','Общественные движения','Политические партии','Национальная политика','Повседневность','Образование и наука','Культура Руси','Культура Московского государства','Культура Российской империи','Культура СССР','Культура современной России','Памятники культуры','История религии','Мировая культура'],
        5 => ['Работа с письменным источником','Анализ карты и схемы','Изображения и памятники культуры','Установление причин и последствий','Сравнение процессов разных эпох','Роль личности','Историческая аргументация','Россия и мировая история в одном периоде','Анализ нескольких источников','Оценка достоверности информации','Сложная хронология','Дискуссионные исторические проблемы'],
      ],
    ];
    $subjectLabels = ['math' => 'профильной математике', 'rus' => 'русскому языку', 'social' => 'обществознанию', 'english' => 'английскому языку', 'history' => 'истории'];
    $levelGuidance = [
      1 => 'Проверяй один базовый факт или одно действие, без скрытой ловушки.',
      2 => 'Используй стандартную экзаменационную ситуацию и 1–2 шага рассуждения.',
      3 => 'Требуй уверенного применения темы, анализа контекста или нескольких связанных шагов.',
      4 => 'Дай сложный экзаменационный контекст с правдоподобными ловушками в вариантах.',
      5 => 'Дай вопрос высокого уровня: нужен анализ, точное различение близких ответов и проверка условий.',
    ];
    $catalog = [];
    foreach ($topicNames as $slug => $levels) {
      foreach ($levels as $level => $names) {
        foreach ($names as $name) {
          $examples = "Генерируй разные задания ЕГЭ по {$subjectLabels[$slug]} строго по теме «{$name}». {$levelGuidance[$level]} "
            . 'Например, предложи конкретную задачу, текст, ситуацию или набор утверждений по этой теме и четыре самостоятельных варианта ответа. Не делай вопросом простое определение темы, если можно проверить применение знания.';
          $catalog[] = [$slug, $level, $name, $examples];
        }
      }
    }

    $subjectRows = $pdo->query("SELECT id, slug FROM subjects WHERE slug IN ('math','rus','social','english','history')")->fetchAll();
    $subjectIds = [];
    foreach ($subjectRows as $row) $subjectIds[(string)$row['slug']] = (int)$row['id'];

    $pdo->beginTransaction();
    try {
      $pdo->exec('DELETE FROM trainer_qa_history');
      $pdo->exec('DELETE FROM trainer_qa_topics');
      $insert = $pdo->prepare('INSERT INTO trainer_qa_topics(subject_id,level,name,examples,position) VALUES(?,?,?,?,?)');
      $positions = [];
      foreach ($catalog as [$slug, $level, $name, $examples]) {
        if (!isset($subjectIds[$slug])) continue;
        $position = $positions[$slug] ?? 0;
        $insert->execute([$subjectIds[$slug], $level, $name, $examples, $position]);
        $positions[$slug] = $position + 1;
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    live_content_touch();
    out('  + Глобальная модель GLM 5.3 Flash, ' . count($catalog) . ' тем ЕГЭ и история антидублей');
  },

  // v107 — более надёжная глобальная модель после контрольной проверки
  // математических ответов. Настройка остаётся единой для всей платформы.
  107 => function (PDO $pdo, string $driver) {
    if ($driver === 'mysql') {
      $pdo->exec("INSERT INTO app_settings(k,v) VALUES ('ai_model','openai/gpt-5.4-mini') ON DUPLICATE KEY UPDATE v=VALUES(v)");
    } else {
      $pdo->exec("INSERT INTO app_settings(k,v) VALUES ('ai_model','openai/gpt-5.4-mini') ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    }
    live_content_touch();
    out('  + Глобальная ИИ-модель переключена на OpenAI GPT-5.4 mini');
  },

  // v108 — добивает мусор в kinescope_id, который не спасти парсингом: колонка VARCHAR(80),
  // и длинный embed-код мог обрезаться ЕЩЁ ДО ссылки (например «<div style=…<iframe src="»).
  // Всё, что не похоже на валидный id ролика ([A-Za-z0-9_-]), обнуляем — админ введёт заново.
  108 => function (PDO $pdo, string $driver) {
    $rows = $pdo->query("SELECT id, kinescope_id FROM lessons WHERE kinescope_id IS NOT NULL AND kinescope_id != ''")->fetchAll();
    $upd = $pdo->prepare("UPDATE lessons SET kinescope_id = NULL WHERE id = ?");
    foreach ($rows as $r) {
      if (!preg_match('/^[\w-]+$/', (string)$r['kinescope_id'])) $upd->execute([(int)$r['id']]);
    }
  },

  // v109 — закрытый канал преподавателя на курсе и временная внешняя
  // Restream-трансляция на уроке. Ссылка Restream показывается до появления
  // готовой записи, но не дольше длительности эфира + 30 минут после air_at.
  109 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'courses', 'teacher_channel_url'))
      $pdo->exec("ALTER TABLE courses ADD COLUMN teacher_channel_url VARCHAR(500)");
    if (!column_exists($pdo, $driver, 'lessons', 'restream_url'))
      $pdo->exec("ALTER TABLE lessons ADD COLUMN restream_url VARCHAR(500)");
    if (!column_exists($pdo, $driver, 'lessons', 'restream_duration'))
      $pdo->exec("ALTER TABLE lessons ADD COLUMN restream_duration INT DEFAULT 120");
  },

  // v110 — первые блоки годовых курсов русского, истории и английского
  // по расписаниям 2026/27. Не удаляет ручной контент: существующие уроки
  // узнаёт по точному названию, обновляет расписание и добавляет только недостающее.
  110 => function (PDO $pdo, string $driver) {
    $courseCreated = 0;
    $lessonCreated = 0;

    $ensureCourse = function (string $slug, string $subjectName, string $icon, string $title, string $color) use ($pdo, &$courseCreated): int {
      $st = $pdo->prepare('SELECT id FROM subjects WHERE slug = ? LIMIT 1');
      $st->execute([$slug]);
      $subjectId = (int)($st->fetchColumn() ?: 0);
      if ($subjectId <= 0) {
        $position = (int)$pdo->query('SELECT COALESCE(MAX(position),-1)+1 FROM subjects')->fetchColumn();
        $pdo->prepare('INSERT INTO subjects(name,slug,color,icon,show_features,position) VALUES(?,?,?,?,1,?)')
            ->execute([$subjectName, $slug, $color, $icon, $position]);
        $subjectId = (int)$pdo->lastInsertId();
      }

      $st = $pdo->prepare("SELECT id FROM courses WHERE subject_id = ? AND type = 'Основные' ORDER BY (title = ?) DESC, position, id LIMIT 1");
      $st->execute([$subjectId, $title]);
      $courseId = (int)($st->fetchColumn() ?: 0);
      if ($courseId <= 0) {
        $position = (int)$pdo->query('SELECT COALESCE(MAX(position),-1)+1 FROM courses')->fetchColumn();
        $pdo->prepare("INSERT INTO courses(subject_id,title,type,color,price,position) VALUES(?,?,'Основные',?,NULL,?)")
            ->execute([$subjectId, $title, $color, $position]);
        $courseId = (int)$pdo->lastInsertId();
        $courseCreated++;
      } else {
        $pdo->prepare('UPDATE courses SET title = ?, color = ? WHERE id = ?')->execute([$title, $color, $courseId]);
      }
      return $courseId;
    };

    $ensurePeriod = function (int $courseId, string $start, string $end) use ($pdo): int {
      $st = $pdo->prepare("SELECT id FROM months WHERE course_id = ? AND (LOWER(name) IN ('1 блок','блок 1','первый блок') OR position = 0) ORDER BY (LOWER(name) = '1 блок') DESC, position, id LIMIT 1");
      $st->execute([$courseId]);
      $monthId = (int)($st->fetchColumn() ?: 0);
      if ($monthId <= 0) {
        $pdo->prepare("INSERT INTO months(course_id,name,kind,starts_on,ends_on,gallery_label,position) VALUES(?,'1 блок','block',?,?,?,0)")
            ->execute([$courseId, $start, $end, 'Расписание 1 блока']);
        $monthId = (int)$pdo->lastInsertId();
      } else {
        $pdo->prepare("UPDATE months SET name='1 блок', kind='block', starts_on=?, ends_on=?, gallery_label=? WHERE id=?")
            ->execute([$start, $end, 'Расписание 1 блока', $monthId]);
      }
      return $monthId;
    };

    $ensurePhoto = function (int $monthId, string $url, bool $onlyWhenEmpty = false) use ($pdo): void {
      if ($onlyWhenEmpty) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM month_photos WHERE month_id = ?');
        $count->execute([$monthId]);
        if ((int)$count->fetchColumn() > 0) return;
      }
      $st = $pdo->prepare('SELECT 1 FROM month_photos WHERE month_id = ? AND url = ? LIMIT 1');
      $st->execute([$monthId, $url]);
      if ($st->fetchColumn()) return;
      $pos = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM month_photos WHERE month_id = ?');
      $pos->execute([$monthId]);
      $pdo->prepare('INSERT INTO month_photos(month_id,url,position) VALUES(?,?,?)')
          ->execute([$monthId, $url, (int)$pos->fetchColumn()]);
    };

    $ensureBlock = function (int $courseId, int $monthId, int $n, string $title, int $position) use ($pdo): int {
      $st = $pdo->prepare('SELECT id FROM blocks WHERE month_id = ? AND title = ? LIMIT 1');
      $st->execute([$monthId, $title]);
      $blockId = (int)($st->fetchColumn() ?: 0);
      if ($blockId <= 0) {
        $pdo->prepare('INSERT INTO blocks(course_id,month_id,n,title,position) VALUES(?,?,?,?,?)')
            ->execute([$courseId, $monthId, $n, $title, $position]);
        $blockId = (int)$pdo->lastInsertId();
      } else {
        $pdo->prepare('UPDATE blocks SET n = ?, position = ? WHERE id = ?')->execute([$n, $position, $blockId]);
      }
      return $blockId;
    };

    $ensureLesson = function (int $monthId, int $blockId, array $lesson, int $position) use ($pdo, &$lessonCreated): void {
      [$type, $title, $meta, $airAt, $opensAt] = $lesson;
      $st = $pdo->prepare(
        'SELECT l.id FROM lessons l JOIN blocks b ON b.id=l.block_id WHERE b.month_id=? AND l.title=? ORDER BY l.id LIMIT 1'
      );
      $st->execute([$monthId, $title]);
      $lessonId = (int)($st->fetchColumn() ?: 0);
      $air = $airAt ? 'Эфир ' . date('j.m, H:i', strtotime($airAt)) : null;
      if ($lessonId <= 0) {
        $pdo->prepare("INSERT INTO lessons(block_id,type,title,meta,air,air_at,duration,opens_at,video_source,restream_duration,position) VALUES(?,?,?,?,?,?,?,?,'none',120,?)")
            ->execute([$blockId, $type, $title, $meta, $air, $airAt, '120 мин', $opensAt, $position]);
        $lessonCreated++;
      } else {
        // Видео, материалы, описания и ДЗ не трогаем — только календарные поля.
        $pdo->prepare('UPDATE lessons SET type=?, meta=?, air=?, air_at=?, opens_at=? WHERE id=?')
            ->execute([$type, $meta, $air, $airAt, $opensAt, $lessonId]);
      }
    };

    $programs = [
      [
        'slug' => 'rus', 'subject' => 'Русский язык', 'icon' => 'feather',
        'title' => 'Годовой курс ЕГЭ по русскому языку', 'color' => '#ef3745',
        'start' => '2026-09-14', 'end' => '2026-10-10',
        'photo' => '/img/course-schedules/russian-block-1.png',
        'blocks' => [
          ['Лексика: задания 4–6', [
            ['theory', 'Установочный стрим. Задание 4', 'Онлайн-занятие', '2026-09-14 18:00', '2026-09-14 18:00'],
            ['theory', 'Задание 5. Паронимы', 'Онлайн-занятие', '2026-09-16 16:00', '2026-09-16 16:00'],
            ['theory', 'Задание 6. Плеоназмы, тавтология', 'Онлайн-занятие', '2026-09-21 18:00', '2026-09-21 18:00'],
          ]],
          ['Грамматические нормы', [
            ['theory', 'Задание 7. Грамматические нормы. Часть 1', 'Онлайн-занятие', '2026-09-23 18:00', '2026-09-23 18:00'],
            ['theory', 'Задание 7. Грамматические нормы. Часть 2', 'Онлайн-занятие', '2026-09-28 18:00', '2026-09-28 18:00'],
          ]],
          ['Итоговое сочинение', [
            ['theory', 'Что такое ИС? Структура экзамена, критерии, примеры', 'Занятие 19 сентября', null, '2026-09-19 00:00'],
            ['theory', 'ИС2: универсальное произведение и сочинение', 'Занятие 26 сентября', null, '2026-09-26 00:00'],
            ['theory', 'ИС3: направление № 1. Необходимая литература', 'Занятие 3 октября', null, '2026-10-03 00:00'],
            ['theory', 'ИС4: направление № 1 и сочинение', 'Занятие 10 октября', null, '2026-10-10 00:00'],
          ]],
          ['Задание 8', [
            ['theory', 'Задание 8. Часть 1', 'Онлайн-занятие', '2026-09-30 16:00', '2026-09-30 16:00'],
            ['theory', 'Задание 8. Часть 2', 'Онлайн-занятие', '2026-10-05 18:00', '2026-10-05 18:00'],
          ]],
          ['Практика', [
            ['practice', 'Практика. Задания 4–8', 'Практикум', '2026-10-07 16:00', '2026-10-07 16:00'],
          ]],
        ],
      ],
      [
        'slug' => 'history', 'subject' => 'История', 'icon' => 'landmark',
        'title' => 'Годовой курс ЕГЭ по истории', 'color' => '#ff9f2f',
        'start' => '2026-09-15', 'end' => '2026-10-11',
        'photo' => '/img/course-schedules/history-block-1.png',
        'blocks' => [
          ['Древняя Русь: становление государства', [
            ['theory', 'Восточные славяне в древности', 'Онлайн-занятие', '2026-09-15 16:00', '2026-09-15 16:00'],
            ['theory', 'Первые князья Древней Руси: от Рюрика до Святослава', 'Онлайн-занятие', '2026-09-19 14:00', '2026-09-19 14:00'],
            ['theory', 'Правление Владимира Святого и Ярослава Мудрого', 'Онлайн-занятие', '2026-09-22 16:00', '2026-09-22 16:00'],
            ['theory', 'Культура Древней Руси. Характеристика княжеств и земель', 'Онлайн-занятие', '2026-09-29 16:00', '2026-09-29 16:00'],
          ]],
          ['Раздробленность и русские земли', [
            ['theory', 'Ярославичи и Владимир Мономах. Начало раздробленности', 'Онлайн-занятие', '2026-09-26 14:00', '2026-09-26 14:00'],
            ['theory', 'Владимиро-Суздальское княжество. Юрий Долгорукий и Андрей Боголюбский', 'Онлайн-занятие', '2026-10-03 14:00', '2026-10-03 14:00'],
          ]],
          ['Монгольское нашествие', [
            ['theory', 'Монгольское нашествие на Русь', 'Онлайн-занятие', '2026-10-06 16:00', '2026-10-06 16:00'],
            ['theory', 'Итоги монгольского нашествия. Александр Невский', 'Онлайн-занятие', '2026-10-10 14:00', '2026-10-10 14:00'],
          ]],
          ['Практика', [
            ['practice', 'Практика № 1: учимся оформлять вторую часть', 'Практикум', '2026-09-20 15:00', '2026-09-20 15:00'],
            ['practice', 'Практика № 2', 'Практикум', '2026-09-27 15:00', '2026-09-27 15:00'],
            ['practice', 'Практика № 3', 'Практикум', '2026-10-04 15:00', '2026-10-04 15:00'],
            ['practice', 'Практика № 4', 'Практикум', '2026-10-11 15:00', '2026-10-11 15:00'],
          ]],
        ],
      ],
      [
        'slug' => 'english', 'subject' => 'Английский язык', 'icon' => 'english',
        'title' => 'Годовой курс ЕГЭ по английскому языку', 'color' => '#a92cf0',
        'start' => '2026-09-15', 'end' => '2026-10-12',
        'photo' => '/img/course-schedules/english-block-1.png',
        'blocks' => [
          ['Онлайн-занятия', [
            ['theory', 'Present Simple', 'Онлайн-занятие', '2026-09-15 18:00', '2026-09-15 18:00'],
            ['theory', 'Family. Friends. Personal Pronouns.', 'Онлайн-занятие', '2026-09-22 18:00', '2026-09-22 18:00'],
            ['theory', 'Present Continuous vs Present Simple.', 'Онлайн-занятие', '2026-09-24 18:00', '2026-09-24 18:00'],
            ['theory', 'Present Perfect. Free time', 'Онлайн-занятие', '2026-10-01 18:00', '2026-10-01 18:00'],
            ['theory', "Teenagers' Pastimes. Technology", 'Онлайн-занятие', '2026-10-06 18:00', '2026-10-06 18:00'],
          ]],
          ['Практикумы', [
            ['practice', 'Практикум установочной части', 'Практикум', '2026-09-17 18:00', '2026-09-17 18:00'],
            ['practice', 'Практикум. Задание 37', 'Практикум', '2026-09-29 18:00', '2026-09-29 18:00'],
          ]],
          ['Записи теории', [
            ['theory', 'Запись. Теория — 18 сентября', 'Запись', null, '2026-09-18 00:00'],
            ['theory', 'Запись. Теория — 25 сентября', 'Запись', null, '2026-09-25 00:00'],
            ['theory', 'Запись. Теория — 2 октября', 'Запись', null, '2026-10-02 00:00'],
            ['theory', 'Запись. Теория — 9 октября', 'Запись', null, '2026-10-09 00:00'],
          ]],
          ['Дополнительные вебинары 90+', [
            ['webinar', 'ДОП Веб 90+ — 21 сентября', 'Дополнительный урок', null, '2026-09-21 00:00'],
            ['webinar', 'ДОП Веб 90+ — 28 сентября', 'Дополнительный урок', null, '2026-09-28 00:00'],
            ['webinar', 'ДОП Веб 90+ — 5 октября', 'Дополнительный урок', null, '2026-10-05 00:00'],
            ['webinar', 'ДОП Веб 90+ — 12 октября', 'Дополнительный урок', null, '2026-10-12 00:00'],
          ]],
          ['Повторение', [
            ['webinar', 'МЕГАРЕВИЖН', 'Онлайн-занятие', '2026-10-08 18:00', '2026-10-08 18:00'],
          ]],
        ],
      ],
    ];

    $targetCourseIds = [];
    $pdo->beginTransaction();
    try {
      foreach ($programs as $program) {
        $courseId = $ensureCourse($program['slug'], $program['subject'], $program['icon'], $program['title'], $program['color']);
        $targetCourseIds[] = $courseId;
        $monthId = $ensurePeriod($courseId, $program['start'], $program['end']);
        // Для русского фотография уже могла быть загружена вручную при создании
        // курса; проверочное изображение не должно дублировать её в галерее.
        $ensurePhoto($monthId, $program['photo'], $program['slug'] === 'rus');
        foreach ($program['blocks'] as $bpos => [$blockTitle, $lessons]) {
          $blockId = $ensureBlock($courseId, $monthId, $bpos + 1, $blockTitle, $bpos);
          foreach ($lessons as $lpos => $lesson) $ensureLesson($monthId, $blockId, $lesson, $lpos);
        }
      }

      // Новые предметы получают те же ученики, у которых уже есть хотя бы один
      // основной курс. Так новые курсы появляются у действующего ученика, но не
      // раздаются пустым/служебным аккаунтам без текущего обучения.
      $eligible = $pdo->query(
        "SELECT DISTINCT en.user_id FROM enrollments en JOIN courses c ON c.id=en.course_id WHERE c.type='Основные'"
      )->fetchAll(PDO::FETCH_COLUMN);
      $has = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? LIMIT 1');
      $grant = $pdo->prepare("INSERT INTO enrollments(user_id,course_id,source,expires_at) VALUES(?,?,'manual',NULL)");
      foreach ($eligible as $userId) foreach ($targetCourseIds as $courseId) {
        $has->execute([(int)$userId, $courseId]);
        if (!$has->fetchColumn()) $grant->execute([(int)$userId, $courseId]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out("  + Годовые курсы: создано курсов $courseCreated, добавлено уроков $lessonCreated");
  },

  // v111 — аккуратно объединяет старую частично заполненную структуру русского
  // с программой v110: переносит установочный урок в лексический раздел и
  // убирает только опустевшие заготовки. Ручной контент никогда не удаляется.
  111 => function (PDO $pdo, string $driver) {
    $st = $pdo->query(
      "SELECT m.id FROM months m
       JOIN courses c ON c.id=m.course_id JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='rus' AND c.type='Основные'
       ORDER BY (LOWER(m.name)='1 блок') DESC,m.position,m.id LIMIT 1"
    );
    $monthId = (int)($st ? $st->fetchColumn() : 0);
    if ($monthId <= 0) return;

    $pdo->beginTransaction();
    try {
      $findBlock = $pdo->prepare('SELECT id FROM blocks WHERE month_id=? AND title=? LIMIT 1');
      $findBlock->execute([$monthId, 'Лексика: задания 4–6']);
      $lexBlockId = (int)($findBlock->fetchColumn() ?: 0);
      if ($lexBlockId > 0) {
        $pdo->prepare(
          "UPDATE lessons SET block_id=?,position=0 WHERE title='Установочный стрим. Задание 4'
           AND block_id IN (SELECT id FROM blocks WHERE month_id=?)"
        )->execute([$lexBlockId, $monthId]);
      }

      $deleteEmpty = $pdo->prepare(
        "DELETE FROM blocks WHERE month_id=? AND title=?
         AND NOT EXISTS (SELECT 1 FROM lessons l WHERE l.block_id=blocks.id)"
      );
      foreach (['Вводное занятие и старт блока', 'Тестовая часть ЕГЭ'] as $oldTitle) {
        $deleteEmpty->execute([$monthId, $oldTitle]);
      }

      $titles = ['Лексика: задания 4–6','Грамматические нормы','Итоговое сочинение','Задание 8','Практика'];
      $reorder = $pdo->prepare('UPDATE blocks SET n=?,position=? WHERE month_id=? AND title=?');
      foreach ($titles as $position => $title) $reorder->execute([$position + 1, $position, $monthId, $title]);

      // Если в русском уже было вручную загруженное расписание, убираем только
      // добавленную v110 статическую копию. Единственное фото всегда сохраняем.
      $countOther = $pdo->prepare("SELECT COUNT(*) FROM month_photos WHERE month_id=? AND url<>'/img/course-schedules/russian-block-1.png'");
      $countOther->execute([$monthId]);
      if ((int)$countOther->fetchColumn() > 0) {
        $pdo->prepare("DELETE FROM month_photos WHERE month_id=? AND url='/img/course-schedules/russian-block-1.png'")->execute([$monthId]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Первый блок русского объединён с существующей заготовкой без дублей');
  },

  // v112 — приводит новые годовые курсы к реальному шаблону уже работающих
  // основных курсов: только practice, пустой meta, длительность 120, открытие
  // накануне в 00:00 и тематические (а не форматные) названия разделов.
  112 => function (PDO $pdo, string $driver) {
    $programs = [
      'rus' => [
        ['Лексика: задания 4–6', [
          ['Установочный стрим. Задание 4', '2026-09-14'],
          ['Задание 5. Паронимы', '2026-09-16'],
          ['Задание 6. Плеоназмы, тавтология', '2026-09-21'],
        ]],
        ['Грамматические нормы', [
          ['Задание 7. Грамматические нормы. Часть 1', '2026-09-23'],
          ['Задание 7. Грамматические нормы. Часть 2', '2026-09-28'],
        ]],
        ['Итоговое сочинение', [
          ['Что такое ИС? Структура экзамена, критерии, примеры', '2026-09-19'],
          ['ИС2: универсальное произведение и сочинение', '2026-09-26'],
          ['ИС3: направление № 1. Необходимая литература', '2026-10-03'],
          ['ИС4: направление № 1 и сочинение', '2026-10-10'],
        ]],
        ['Задание 8', [
          ['Задание 8. Часть 1', '2026-09-30'],
          ['Задание 8. Часть 2', '2026-10-05'],
        ]],
        ['Работа с заданиями и экзаменационная практика', [
          ['Практика. Задания 4–8', '2026-10-07'],
        ]],
      ],
      'history' => [
        ['Древняя Русь', [
          ['Восточные славяне в древности', '2026-09-15'],
          ['Первые князья Древней Руси: от Рюрика до Святослава', '2026-09-19'],
          ['Правление Владимира Святого и Ярослава Мудрого', '2026-09-22'],
          ['Ярославичи и Владимир Мономах. Начало раздробленности', '2026-09-26'],
          ['Культура Древней Руси. Характеристика княжеств и земель', '2026-09-29'],
        ]],
        ['Раздробленность и монгольское нашествие', [
          ['Владимиро-Суздальское княжество. Юрий Долгорукий и Андрей Боголюбский', '2026-10-03'],
          ['Монгольское нашествие на Русь', '2026-10-06'],
          ['Итоги монгольского нашествия. Александр Невский', '2026-10-10'],
        ]],
        ['Работа с заданиями и экзаменационная практика', [
          ['Практика № 1: учимся оформлять вторую часть', '2026-09-20'],
          ['Практика № 2', '2026-09-27'],
          ['Практика № 3', '2026-10-04'],
          ['Практика № 4', '2026-10-11'],
        ]],
      ],
      'english' => [
        ['Грамматика: времена группы Present', [
          ['Present Simple', '2026-09-15'],
          ['Present Continuous vs Present Simple.', '2026-09-24'],
          ['Present Perfect. Free time', '2026-10-01'],
        ]],
        ['Лексика и устная часть', [
          ['Практикум установочной части', '2026-09-17'],
          ['Family. Friends. Personal Pronouns.', '2026-09-22'],
          ["Teenagers' Pastimes. Technology", '2026-10-06'],
        ]],
        ['Письменная часть', [
          ['Практикум. Задание 37', '2026-09-29'],
        ]],
        ['Записи занятий', [
          ['Запись. Теория — 18 сентября', '2026-09-18'],
          ['Запись. Теория — 25 сентября', '2026-09-25'],
          ['Запись. Теория — 2 октября', '2026-10-02'],
          ['Запись. Теория — 9 октября', '2026-10-09'],
        ]],
        ['Дополнительные занятия', [
          ['ДОП Веб 90+ — 21 сентября', '2026-09-21'],
          ['ДОП Веб 90+ — 28 сентября', '2026-09-28'],
          ['ДОП Веб 90+ — 5 октября', '2026-10-05'],
          ['МЕГАРЕВИЖН', '2026-10-08'],
          ['ДОП Веб 90+ — 12 октября', '2026-10-12'],
        ]],
      ],
    ];

    $pdo->beginTransaction();
    try {
      foreach ($programs as $slug => $blocks) {
        $findPeriod = $pdo->prepare(
          "SELECT m.id,c.id AS course_id FROM months m
           JOIN courses c ON c.id=m.course_id JOIN subjects s ON s.id=c.subject_id
           WHERE s.slug=? AND c.type='Основные'
           ORDER BY (LOWER(m.name) IN ('блок 1','1 блок')) DESC,m.position,m.id LIMIT 1"
        );
        $findPeriod->execute([$slug]);
        $period = $findPeriod->fetch();
        if (!$period) continue;
        $monthId = (int)$period['id'];
        $courseId = (int)$period['course_id'];
        $pdo->prepare("UPDATE months SET name='БЛОК 1',kind='block' WHERE id=?")->execute([$monthId]);

        $targetBlockIds = [];
        foreach ($blocks as $bpos => [$blockTitle, $lessons]) {
          $findBlock = $pdo->prepare('SELECT id FROM blocks WHERE month_id=? AND title=? LIMIT 1');
          $findBlock->execute([$monthId, $blockTitle]);
          $blockId = (int)($findBlock->fetchColumn() ?: 0);
          if ($blockId <= 0) {
            $pdo->prepare('INSERT INTO blocks(course_id,month_id,n,title,position) VALUES(?,?,?,?,?)')
                ->execute([$courseId, $monthId, $bpos + 1, $blockTitle, $bpos]);
            $blockId = (int)$pdo->lastInsertId();
          } else {
            $pdo->prepare('UPDATE blocks SET n=?,position=? WHERE id=?')->execute([$bpos + 1, $bpos, $blockId]);
          }
          $targetBlockIds[] = $blockId;

          foreach ($lessons as $lpos => [$lessonTitle, $lessonDate]) {
            $opensAt = date('Y-m-d 00:00', strtotime($lessonDate . ' -1 day'));
            $findLesson = $pdo->prepare(
              'SELECT l.id FROM lessons l JOIN blocks b ON b.id=l.block_id WHERE b.month_id=? AND l.title=? LIMIT 1'
            );
            $findLesson->execute([$monthId, $lessonTitle]);
            $lessonId = (int)($findLesson->fetchColumn() ?: 0);
            if ($lessonId <= 0) continue;
            $pdo->prepare("UPDATE lessons SET block_id=?,type='practice',meta=NULL,duration='120',opens_at=?,position=? WHERE id=?")
                ->execute([$blockId, $opensAt, $lpos, $lessonId]);
          }
        }

        // После переноса удаляем только полностью пустые старые разделы.
        $oldBlocks = $pdo->prepare('SELECT id FROM blocks WHERE month_id=?');
        $oldBlocks->execute([$monthId]);
        $hasLessons = $pdo->prepare('SELECT 1 FROM lessons WHERE block_id=? LIMIT 1');
        $deleteBlock = $pdo->prepare('DELETE FROM blocks WHERE id=?');
        foreach ($oldBlocks->fetchAll(PDO::FETCH_COLUMN) as $blockId) {
          if (in_array((int)$blockId, $targetBlockIds, true)) continue;
          $hasLessons->execute([(int)$blockId]);
          if (!$hasLessons->fetchColumn()) $deleteBlock->execute([(int)$blockId]);
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Русский, история и английский приведены к шаблону основных курсов');
  },

  // v113 — точная сверка карточек уроков с изображениями расписаний.
  // У записей без указанного на макете времени временно используется 18:00,
  // чтобы они участвовали в календаре; доступ открывается накануне в 00:00.
  113 => function (PDO $pdo, string $driver) {
    $programs = [
      'math' => [
        ['№6, 7. Логарифмы: действия + уравнения', '№6, 7. Логарифмы действия + Уравнения', '2026-09-25', '16:00'],
      ],
      'rus' => [
        ['Установочный стрим. Задание 4', 'Установочный стрим. Задание 4', '2026-09-14', '18:00'],
        ['Задание 5. Паронимы', 'Задание 5. Паронимы', '2026-09-16', '16:00'],
        ['Что такое ИС? Структура экзамена, критерии, примеры', 'Что такое ИС? Структура экзамена, критерии, примеры', '2026-09-19', '18:00'],
        ['Задание 6. Плеоназмы, тавтология', 'Задание 6. Плеоназмы, тавтология', '2026-09-21', '18:00'],
        ['Задание 7. Грамматические нормы. Часть 1', 'Задание 7. Грамматические нормы. Часть 1', '2026-09-23', '18:00'],
        ['ИС2: универсальное произведение и сочинение', 'ИС2: Разбираем универсальное произведение, пишем сочинение', '2026-09-26', '18:00'],
        ['Задание 7. Грамматические нормы. Часть 2', 'Задание 7. Грамматические нормы. Часть 2', '2026-09-28', '18:00'],
        ['Задание 8. Часть 1', 'Задание 8. Часть 1', '2026-09-30', '16:00'],
        ['ИС3: направление № 1. Необходимая литература', 'ИС3: Разбираем направление № 1. Необходимая литература', '2026-10-03', '18:00'],
        ['Задание 8. Часть 2', 'Задание 8. Часть 2', '2026-10-05', '18:00'],
        ['Практика. Задания 4–8', 'Практика. Задания 4–8', '2026-10-07', '16:00'],
        ['ИС4: направление № 1 и сочинение', 'ИС4: разбираем литру к 1 направлению, пишем сочинение', '2026-10-10', '18:00'],
      ],
      'history' => [
        ['Восточные славяне в древности', 'Восточные славяне в древности', '2026-09-15', '16:00'],
        ['Первые князья Древней Руси: от Рюрика до Святослава', 'Первые князья Древней Руси (от Рюрика до Святослава)', '2026-09-19', '14:00'],
        ['Практика № 1: учимся оформлять вторую часть', 'Практика № 1: учимся оформлять вторую часть', '2026-09-20', '15:00'],
        ['Правление Владимира Святого и Ярослава Мудрого', 'Правление Владимира Святого и Ярослава Мудрого', '2026-09-22', '16:00'],
        ['Ярославичи и Владимир Мономах. Начало раздробленности', 'Ярославичи и Владимир Мономах. Начинаем изучать раздробленность', '2026-09-26', '14:00'],
        ['Практика № 2', 'Практика №2', '2026-09-27', '15:00'],
        ['Культура Древней Руси. Характеристика княжеств и земель', 'Культура Древней Руси. Характеристика княжеств и земель', '2026-09-29', '16:00'],
        ['Владимиро-Суздальское княжество. Юрий Долгорукий и Андрей Боголюбский', 'Характеристика Владимиро-Суздальского княжества. Калка', '2026-10-03', '14:00'],
        ['Практика № 3', 'Практика №3', '2026-10-04', '15:00'],
        ['Монгольское нашествие на Русь', 'Монгольское нашествие на Русь', '2026-10-06', '16:00'],
        ['Итоги монгольского нашествия. Александр Невский', 'Итоги монгольского нашествия. Александр Невский', '2026-10-10', '14:00'],
        ['Практика № 4', 'Практика №4', '2026-10-11', '15:00'],
      ],
      'english' => [
        ['Present Simple', 'Present Simple', '2026-09-15', '18:00'],
        ['Практикум установочной части', 'Практикум устной части', '2026-09-17', '18:00'],
        ['Запись. Теория — 18 сентября', 'Запись. Теория', '2026-09-18', '18:00'],
        ['ДОП Веб 90+ — 21 сентября', 'ДОП Веб 90+', '2026-09-21', '18:00'],
        ['Family. Friends. Personal Pronouns.', 'Family. Friends. Personal Pronouns.', '2026-09-22', '18:00'],
        ['Present Continuous vs Present Simple.', 'Present Continuous vs Present Simple.', '2026-09-24', '18:00'],
        ['Запись. Теория — 25 сентября', 'Запись. Теория', '2026-09-25', '18:00'],
        ['ДОП Веб 90+ — 28 сентября', 'ДОП Веб 90+', '2026-09-28', '18:00'],
        ['Практикум. Задание 37', 'Практикум. Задание 37', '2026-09-29', '18:00'],
        ['Present Perfect. Free time', 'Present Perfect. Free time', '2026-10-01', '18:00'],
        ['Запись. Теория — 2 октября', 'Запись. Теория', '2026-10-02', '18:00'],
        ['ДОП Веб 90+ — 5 октября', 'ДОП Веб 90+', '2026-10-05', '18:00'],
        ["Teenagers' Pastimes. Technology", "Teenagers' Pastimes. Technology", '2026-10-06', '18:00'],
        ['МЕГАРЕВИЖН', 'МЕГАРЕВИЖН', '2026-10-08', '18:00'],
        ['Запись. Теория — 9 октября', 'Запись. Теория', '2026-10-09', '18:00'],
        ['ДОП Веб 90+ — 12 октября', 'ДОП Веб 90+', '2026-10-12', '18:00'],
      ],
    ];

    $pdo->beginTransaction();
    try {
      $findCourse = $pdo->prepare(
        "SELECT c.id FROM courses c JOIN subjects s ON s.id=c.subject_id
         WHERE s.slug=? AND c.type='Основные' ORDER BY c.position,c.id LIMIT 1"
      );
      $findLesson = $pdo->prepare(
        'SELECT l.id FROM lessons l JOIN blocks b ON b.id=l.block_id WHERE b.course_id=? AND l.title=? LIMIT 1'
      );
      $updateLesson = $pdo->prepare(
        "UPDATE lessons SET title=?,type='practice',meta=NULL,duration='120',air=?,air_at=?,opens_at=? WHERE id=?"
      );

      foreach ($programs as $slug => $lessons) {
        $findCourse->execute([$slug]);
        $courseId = (int)($findCourse->fetchColumn() ?: 0);
        if ($courseId <= 0) continue;
        foreach ($lessons as [$lookupTitle, $title, $date, $time]) {
          $findLesson->execute([$courseId, $lookupTitle]);
          $lessonId = (int)($findLesson->fetchColumn() ?: 0);
          if ($lessonId <= 0) continue;
          $airAt = $date . ' ' . $time;
          $opensAt = date('Y-m-d 00:00', strtotime($date . ' -1 day'));
          $air = 'Эфир ' . date('j.n', strtotime($date)) . ', ' . $time;
          $updateLesson->execute([$title, $air, $airAt, $opensAt, $lessonId]);
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Даты, время и названия пяти основных курсов сверены с расписаниями');
  },

  // v114 — первый сентябрьский пробник основного курса английского.
  // Устная часть сдаётся настоящими аудиозаписями и уходит эксперту на проверку.
  114 => function (PDO $pdo, string $driver) {
    $courseSt = $pdo->query(
      "SELECT c.id FROM courses c JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='english' AND c.type='Основные' ORDER BY c.position,c.id LIMIT 1"
    );
    $courseId = (int)($courseSt ? $courseSt->fetchColumn() : 0);
    if ($courseId <= 0) {
      out('  ! Сентябрьский пробник английского пропущен: основной курс не найден');
      return;
    }

    $title = 'Входной пробник. Устная часть';
    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id FROM mock_exams WHERE course_id=? AND title=? LIMIT 1');
      $find->execute([$courseId, $title]);
      $mockId = (int)($find->fetchColumn() ?: 0);
      if ($mockId <= 0) {
        $pdo->prepare('UPDATE mock_exams SET position=position+1 WHERE course_id=?')->execute([$courseId]);
        $pdo->prepare("INSERT INTO mock_exams(course_id,month,title,format,access_mode,position) VALUES(?,'Сентябрь',?,'normal','general',0)")
            ->execute([$courseId, $title]);
        $mockId = (int)$pdo->lastInsertId();
      } else {
        $pdo->prepare("UPDATE mock_exams SET month='Сентябрь',format='normal',access_mode='general',position=0 WHERE id=?")
            ->execute([$mockId]);
      }

      $submissions = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE mock_id=?');
      $submissions->execute([$mockId]);
      $existingQuestions = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE mock_id=?');
      $existingQuestions->execute([$mockId]);
      if ((int)$submissions->fetchColumn() === 0 && (int)$existingQuestions->fetchColumn() > 0) {
        $ids = $pdo->prepare('SELECT id FROM questions WHERE mock_id=?');
        $ids->execute([$mockId]);
        $questionIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($questionIds) {
          $ph = implode(',', array_fill(0, count($questionIds), '?'));
          $pdo->prepare("DELETE FROM question_attachments WHERE question_id IN ($ph)")->execute($questionIds);
        }
        $pdo->prepare('DELETE FROM questions WHERE mock_id=?')->execute([$mockId]);
      }

      $existingQuestions->execute([$mockId]);
      if ((int)$existingQuestions->fetchColumn() === 0) {
        $task1 = <<<'TEXT'
**Task 1.** Imagine that you are preparing a project with your friend. You have found some interesting material for the presentation and you want to read this text to your friend. You have 1.5 minutes to read the text silently, then be ready to read it out aloud. You will not have more than 1.5 minutes to read it.

With final exams coming up, we could all use an extra boost while studying. Your brain is one of the most critical organs of your body, not to mention it is actually still developing throughout your teenage years! It is essential to choose the best foods for your brain to improve your focus and memory. Without nutritious foods, your brain’s ability to learn new skills and tasks can decrease. Protein is important for growth and maintenance of the body. Depending on your age and size, teens usually need between 40–60 grams of protein each day. Fruits and vegetables are essential for memory. They are nutrient packed foods that can help protect against memory loss later in life. Now that you know these great tips for improving brain health, get started on fueling your brain by eating these real nutritious foods! The school year is almost over, push through and finish strong!
TEXT;
        $solution1 = <<<'TEXT'
Слова, на которые стоит обратить внимание при проверке произношения:

1. final exams /ˈfaɪ.nəl ɪgˈzæmz/ — выпускные экзамены;
2. organs /ˈɔːr.gənz/ — органы;
3. throughout /θruːˈaʊt/ — на протяжении;
4. essential /ɪˈsen.ʃəl/ — необходимый, важный;
5. focus /ˈfoʊ.kəs/ — концентрация;
6. nutritious /njuːˈtrɪʃ.əs/ — питательный;
7. decrease /dɪˈkriːs/ — уменьшать;
8. maintenance /ˈmeɪn.tən.əns/ — поддержание;
9. nutrient /ˈnjuː.tri.ənt/ — питательное вещество;
10. health /helθ/ — здоровье;
11. through /θruː/ — через, сквозь.
TEXT;
        $task2 = <<<'TEXT'
**Task 2.** You are considering visiting a fishing camp site and you'd like to get more information. In 1.5 minutes you are to ask four direct questions to find out the following:

1. location;
2. types of accommodation;
3. first-aid post availability;
4. boat rental.
TEXT;
        $solution2 = <<<'TEXT'
Возможные вопросы:

1. Where is the fishing camp located? / What is the location of your fishing camp?
2. What types of accommodation do you provide? / What types of accommodation are available?
3. Is there a first-aid post at your fishing camp?
4. Is it possible to rent a boat? / Do you offer boat rental services?
TEXT;
        $task3 = <<<'TEXT'
**Task 3.** You are going to give an interview. You have to answer five questions. Give full answers to the questions (2–3 sentences). Remember that you have 40 seconds to answer each question.

1. Are environmental problems important to you? Why?
2. What is the most dangerous environmental problem today? Why?
3. Are there any ecological problems in your area? What are they?
4. Are there any ecological activist groups in your region? What do they do?
5. Do you agree that everyone should contribute to saving the planet? How can people help?
TEXT;
        $solution3 = <<<'TEXT'
Пример ответов:

1. Environmental problems are important to me. I think that they impact our health, biodiversity, and future generations. A healthy environment is essential for our well-being and quality of life.
2. I believe climate change is the most dangerous environmental problem today. It leads to extreme weather, rising sea levels and disruption of ecosystems, threatening food security and human livelihoods globally.
3. There are ecological problems in my area, such as air pollution and waste management issues. They negatively affect citizens' health and local wildlife. Increased urbanisation has led to smog and reduced air quality.
4. There are several ecological activist groups in my region. They raise awareness about environmental issues, organise community clean-up events and advocate for sustainable practices and policies.
5. I absolutely agree that everyone should contribute to saving the planet. We can help by reducing waste, conserving energy, using public transport, recycling and supporting sustainable businesses. Every small action counts.
TEXT;
        $task4 = <<<'TEXT'
**Task 4.** Imagine that you and your friend are doing a school project “Keeping in touch”. You have found some photos to illustrate it but for technical reasons you cannot send them now. Leave a voice message to your friend explaining your choice of the photos and sharing some ideas about the project.

In 2.5 minutes be ready to:

- explain the choice of the illustrations for the project by briefly describing them and noting the differences;
- mention the advantages (1–2) of the two ways of keeping in touch;
- mention the disadvantages (1–2) of the two ways of keeping in touch;
- express your opinion on the subject of the project — which way of keeping in touch you prefer and why.

You will speak for not more than 3 minutes (12–15 sentences). You have to talk continuously.
TEXT;
        $solution4 = <<<'TEXT'
Hi Tanya! I've found two photos for our project “Keeping in touch”. I’d like to describe them to you.

One photo shows a woman and her daughter sitting on a couch with a laptop. They look happy and the woman is greeting someone during a video call. The other photo depicts a girl who is walking along a city street and texting someone on her phone.

These pictures are a good choice for our project because both of them illustrate common ways of communicating. The key difference is the type of communication: a video call lets people see and hear each other, whereas text messaging allows them to talk even when they are busy.

The main benefit of a video call is that you can observe the other person's emotions and reactions and establish a closer connection. Text messages can be read and answered at a convenient time, and the conversation can be saved for future reference.

On the other hand, it can be difficult to find a time for a video call that is convenient for everyone. Text messages can be interpreted in different ways, which may cause confusion or misunderstanding.

Personally, I prefer texting because I can communicate without disturbing other people or being overheard in public places. Well, that's all I wanted to tell you. Call me back as soon as possible.
TEXT;

        $insert = $pdo->prepare(
          "INSERT INTO questions(mock_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,'audio',?,NULL,NULL,?,NULL,?,0,0,0,'full',1,?)"
        );
        $questionIds = [];
        foreach ([[$task1,$solution1,1],[$task2,$solution2,4],[$task3,$solution3,5],[$task4,$solution4,10]] as $position => [$text,$solution,$points]) {
          $insert->execute([$mockId, $text, $solution, $points, $position]);
          $questionIds[] = (int)$pdo->lastInsertId();
        }
        $attach = $pdo->prepare("INSERT INTO question_attachments(question_id,kind,name,size,url,position) VALUES(?,'image',?,?,?,?)");
        $attach->execute([$questionIds[3], 'Видеозвонок', '2,1 МБ', '/img/mocks/english-september-oral-video-call.png', 0]);
        $attach->execute([$questionIds[3], 'Переписка в телефоне', '2,0 МБ', '/img/mocks/english-september-oral-texting.png', 1]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Английский, сентябрь: создан входной пробник с 4 устными заданиями');
  },

  // v115 — «СПРИНТ»: наполненный вводный курс по обществознанию по расписанию
  // 7–11 сентября. В исходнике время не указано, поэтому air_at не выдумываем.
  115 => function (PDO $pdo, string $driver) {
    $subjectSt = $pdo->query("SELECT id FROM subjects WHERE slug='social' LIMIT 1");
    $subjectId = (int)($subjectSt ? $subjectSt->fetchColumn() : 0);
    if ($subjectId <= 0) {
      out('  ! Вводный СПРИНТ пропущен: обществознание не найдено');
      return;
    }

    $pdo->beginTransaction();
    try {
      $findCourse = $pdo->prepare("SELECT id FROM courses WHERE subject_id=? AND type='Вводные' ORDER BY (title='Вводный курс по обществознанию') DESC,position,id LIMIT 1");
      $findCourse->execute([$subjectId]);
      $courseId = (int)($findCourse->fetchColumn() ?: 0);
      if ($courseId <= 0) {
        $position = (int)$pdo->query('SELECT COALESCE(MAX(position),-1)+1 FROM courses')->fetchColumn();
        $pdo->prepare("INSERT INTO courses(subject_id,title,type,color,price,position) VALUES(?,?,'Вводные','#3567d6',NULL,?)")
            ->execute([$subjectId, 'СПРИНТ — вводный курс по обществознанию', $position]);
        $courseId = (int)$pdo->lastInsertId();
      } else {
        $pdo->prepare("UPDATE courses SET title=?,type='Вводные',color='#3567d6' WHERE id=?")
            ->execute(['СПРИНТ — вводный курс по обществознанию', $courseId]);
      }

      $findMonth = $pdo->prepare("SELECT id FROM months WHERE course_id=? AND LOWER(name) LIKE '%сентябр%' ORDER BY position,id LIMIT 1");
      $findMonth->execute([$courseId]);
      $monthId = (int)($findMonth->fetchColumn() ?: 0);
      if ($monthId <= 0) {
        $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM months WHERE course_id=?');
        $positionSt->execute([$courseId]);
        $pdo->prepare("INSERT INTO months(course_id,name,kind,starts_on,ends_on,gallery_label,position) VALUES(?,'Сентябрь','month','2026-09-07','2026-09-11','Расписание интенсива',?)")
            ->execute([$courseId, (int)$positionSt->fetchColumn()]);
        $monthId = (int)$pdo->lastInsertId();
      } else {
        $pdo->prepare("UPDATE months SET name='Сентябрь',kind='month',starts_on='2026-09-07',ends_on='2026-09-11',gallery_label='Расписание интенсива' WHERE id=?")
            ->execute([$monthId]);
      }

      $photo = '/img/course-schedules/social-intro-sprint.png';
      $photoSt = $pdo->prepare('SELECT 1 FROM month_photos WHERE month_id=? AND url=? LIMIT 1');
      $photoSt->execute([$monthId, $photo]);
      if (!$photoSt->fetchColumn()) {
        $photoPos = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM month_photos WHERE month_id=?');
        $photoPos->execute([$monthId]);
        $pdo->prepare('INSERT INTO month_photos(month_id,url,position) VALUES(?,?,?)')
            ->execute([$monthId, $photo, (int)$photoPos->fetchColumn()]);
      }

      $ensureBlock = function (string $title, int $number, int $position) use ($pdo, $courseId, $monthId): int {
        $st = $pdo->prepare('SELECT id FROM blocks WHERE month_id=? AND title=? LIMIT 1');
        $st->execute([$monthId, $title]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id <= 0) {
          $pdo->prepare('INSERT INTO blocks(course_id,month_id,n,title,position) VALUES(?,?,?,?,?)')
              ->execute([$courseId, $monthId, $number, $title, $position]);
          return (int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE blocks SET n=?,position=? WHERE id=?')->execute([$number, $position, $id]);
        return $id;
      };
      $topicBlock = $ensureBlock('Экономика, политика и социология', 1, 0);
      $examBlock = $ensureBlock('Старт подготовки к ЕГЭ', 2, 1);

      $lessons = [
        [$topicBlock, 'Вся экономика за 90 минут', '2026-09-07', '90 мин', 0],
        [$topicBlock, 'Вся политика за 60 минут', '2026-09-08', '60 мин', 1],
        [$topicBlock, 'Вся социология за 30 минут', '2026-09-09', '30 мин', 2],
        [$examBlock, 'Как устроен ЕГЭ по обществознанию', '2026-09-10', null, 0],
        [$examBlock, 'Как учиться на Ликбезе?', '2026-09-11', null, 1],
      ];
      $findLesson = $pdo->prepare('SELECT l.id FROM lessons l JOIN blocks b ON b.id=l.block_id WHERE b.course_id=? AND l.title=? LIMIT 1');
      $insertLesson = $pdo->prepare("INSERT INTO lessons(block_id,type,title,meta,air,air_at,duration,opens_at,video_source,position) VALUES(?,'practice',?,NULL,?,NULL,?,?,'none',?)");
      $updateLesson = $pdo->prepare("UPDATE lessons SET block_id=?,type='practice',meta=NULL,air=?,air_at=NULL,duration=?,opens_at=?,video_source='none',position=? WHERE id=?");
      // Пять стандартных демо-уроков вводного курса переиспользуем один к одному:
      // так уже прикреплённые администратором материалы не потеряются.
      $demoTitles = ['Экономика: теория','Типовые задания','Человек и общество: теория','Разбор примеров','Практикум по теме'];
      foreach ($lessons as $index => [$blockId,$lessonTitle,$date,$duration,$position]) {
        $findLesson->execute([$courseId, $lessonTitle]);
        if ($findLesson->fetchColumn()) continue;
        $findLesson->execute([$courseId, $demoTitles[$index]]);
        $demoId = (int)($findLesson->fetchColumn() ?: 0);
        if ($demoId <= 0) continue;
        $opensAt = date('Y-m-d 00:00', strtotime($date . ' -1 day'));
        $air = date('d.m', strtotime($date));
        $pdo->prepare("UPDATE lessons SET block_id=?,type='practice',title=?,meta=NULL,air=?,air_at=NULL,duration=?,opens_at=?,video_source='none',position=? WHERE id=?")
            ->execute([$blockId, $lessonTitle, $air, $duration, $opensAt, $position, $demoId]);
      }
      foreach ($lessons as [$blockId,$lessonTitle,$date,$duration,$position]) {
        $findLesson->execute([$courseId, $lessonTitle]);
        $lessonId = (int)($findLesson->fetchColumn() ?: 0);
        $opensAt = date('Y-m-d 00:00', strtotime($date . ' -1 day'));
        $air = date('d.m', strtotime($date));
        if ($lessonId <= 0) $insertLesson->execute([$blockId, $lessonTitle, $air, $duration, $opensAt, $position]);
        else $updateLesson->execute([$blockId, $air, $duration, $opensAt, $position, $lessonId]);
      }

      // После переноса удаляем лишь опустевшие старые демо-разделы.
      $pdo->prepare("DELETE FROM blocks WHERE month_id=? AND title IN ('Человек и общество','Экономика') AND NOT EXISTS (SELECT 1 FROM lessons l WHERE l.block_id=blocks.id)")
          ->execute([$monthId]);

      // Вводный курс видят ученики, которым уже открыт основной курс общества.
      $students = $pdo->query(
        "SELECT DISTINCT e.user_id FROM enrollments e JOIN courses c ON c.id=e.course_id
         WHERE c.subject_id=$subjectId AND c.type='Основные'"
      )->fetchAll(PDO::FETCH_COLUMN);
      $has = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? LIMIT 1');
      $grant = $pdo->prepare("INSERT INTO enrollments(user_id,course_id,source,expires_at) VALUES(?,?,'manual',NULL)");
      foreach ($students as $userId) {
        $has->execute([(int)$userId, $courseId]);
        if (!$has->fetchColumn()) $grant->execute([(int)$userId, $courseId]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Обществознание: вводный курс «СПРИНТ» настроен по расписанию 7–11 сентября');
  },

  // v116 — индексы для самых частых ученических выборок. В первую очередь
  // ускоряют поиск последней сдачи в списках ДЗ/пробников и проверки доступа.
  116 => function (PDO $pdo, string $driver) {
    $indexes = [
      'CREATE INDEX idx_submissions_homework_user_id ON submissions(homework_id,user_id,id)',
      'CREATE INDEX idx_submissions_mock_user_id ON submissions(mock_id,user_id,id)',
      'CREATE INDEX idx_enrollments_user_expiry_course ON enrollments(user_id,expires_at,course_id)',
      'CREATE INDEX idx_enrollments_course_user_expiry ON enrollments(course_id,user_id,expires_at)',
      'CREATE INDEX idx_questions_homework_position ON questions(homework_id,position,id)',
      'CREATE INDEX idx_questions_mock_position ON questions(mock_id,position,id)',
      'CREATE INDEX idx_answers_submission_question ON answers(submission_id,question_id)',
      'CREATE INDEX idx_deadline_extensions_user_homework ON deadline_extensions(user_id,homework_id)',
      'CREATE INDEX idx_lesson_progress_user_lesson ON lesson_progress(user_id,lesson_id)',
      'CREATE INDEX idx_mock_exams_course_position ON mock_exams(course_id,position,id)',
      'CREATE INDEX idx_months_course_position ON months(course_id,position,id)',
      'CREATE INDEX idx_blocks_course_month_position ON blocks(course_id,month_id,position,id)',
      'CREATE INDEX idx_lessons_block_position ON lessons(block_id,position,id)',
      'CREATE INDEX idx_homeworks_lesson_level_position ON homeworks(lesson_id,level,position,id)',
      'CREATE INDEX idx_materials_lesson_position ON materials(lesson_id,position,id)',
      'CREATE INDEX idx_lesson_prereqs_lesson_required ON lesson_prereqs(lesson_id,required_lesson_id)',
      'CREATE INDEX idx_purchases_user_status_name ON purchases(user_id,status,item_name)',
      'CREATE INDEX idx_notifications_user_read_id ON notifications(user_id,is_read,id)',
      'CREATE INDEX idx_events_course_starts ON events(course_id,starts_at)',
      'CREATE INDEX idx_question_attachments_question_position ON question_attachments(question_id,position,id)',
    ];
    foreach ($indexes as $sql) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* индекс уже есть */ }
    }
    out('  + Добавлены индексы быстрых выборок кабинета');
  },

  // v117 — сентябрьский входной пробник по английскому, письменная часть.
  // 38 экзаменационных заданий, три дорожки аудирования и чистая диаграмма 38.2.
  117 => function (PDO $pdo, string $driver) {
    $courseSt = $pdo->query(
      "SELECT c.id FROM courses c JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='english' AND c.type='Основные' ORDER BY c.position,c.id LIMIT 1"
    );
    $courseId = (int)($courseSt ? $courseSt->fetchColumn() : 0);
    if ($courseId <= 0) {
      out('  ! Письменный пробник английского пропущен: основной курс не найден');
      return;
    }

    $title = 'Входной пробник. Письменная часть';
    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id FROM mock_exams WHERE course_id=? AND title=? LIMIT 1');
      $find->execute([$courseId, $title]);
      $mockId = (int)($find->fetchColumn() ?: 0);
      if ($mockId <= 0) {
        $pdo->prepare('UPDATE mock_exams SET position=position+1 WHERE course_id=? AND position>=1')->execute([$courseId]);
        $pdo->prepare("INSERT INTO mock_exams(course_id,month,title,format,access_mode,position) VALUES(?,'Сентябрь',?,'normal','general',1)")
            ->execute([$courseId, $title]);
        $mockId = (int)$pdo->lastInsertId();
      } else {
        $pdo->prepare("UPDATE mock_exams SET month='Сентябрь',format='normal',access_mode='general',position=1 WHERE id=?")
            ->execute([$mockId]);
      }

      $submissions = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE mock_id=?');
      $submissions->execute([$mockId]);
      $existing = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE mock_id=?');
      $existing->execute([$mockId]);
      if ((int)$submissions->fetchColumn() === 0 && (int)$existing->fetchColumn() > 0) {
        $ids = $pdo->prepare('SELECT id FROM questions WHERE mock_id=?');
        $ids->execute([$mockId]);
        $questionIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($questionIds) {
          $ph = implode(',', array_fill(0, count($questionIds), '?'));
          $pdo->prepare("DELETE FROM question_attachments WHERE question_id IN ($ph)")->execute($questionIds);
        }
        $pdo->prepare('DELETE FROM questions WHERE mock_id=?')->execute([$mockId]);
      }

      $existing->execute([$mockId]);
      if ((int)$existing->fetchColumn() === 0) {
        $questions = [];
        $questions[] = [
          'text',
          <<<'TEXT'
**Задание 1. Аудирование**

Вы услышите 6 высказываний. Установите соответствие между высказываниями каждого говорящего A–F и утверждениями 1–7. Используйте каждое утверждение только один раз. Одно утверждение лишнее. Вы услышите запись дважды.

1. Reality destroyed my prejudices about the British.
2. It was OK but I still prefer a hotel.
3. I guess my hosts were rich to have such a big house.
4. I stayed with a family who lived in the country.
5. When staying with strangers you have to be open-minded.
6. The house pets ruined the meals for me.
7. I couldn't get warm enough in my room.

Введите шесть цифр подряд в порядке A–F.
TEXT,
          null, ['437165'],
          'Ответ: **437165**. Ключевые фрагменты: A — “the family lived just outside — they were farmers”; B — “the old house where they lived was enormous”; C — “the house itself was freezing”; D — прежние стереотипы оказались неверны; E — кошки находились в столовой во время еды; F — важна готовность принимать культурные различия.',
          2, 'sequence'
        ];
        $questions[] = [
          'text',
          <<<'TEXT'
**Задание 2. Аудирование**

Вы услышите диалог. Определите, какие утверждения A–G соответствуют содержанию текста (**1 — True**), какие не соответствуют (**2 — False**) и о чём в тексте не сказано (**3 — Not stated**). Вы услышите запись дважды.

A. Tom and Brenda have not met for a long time.
B. Tom used to dream of becoming a doctor.
C. Brenda is an expert in scientific things.
D. For Tom medical equipment is the same thing as surgical equipment.
E. Tom's company produces instruments which affect harmful cells with very low temperatures.
F. Tom has many friends among surgeons.
G. Tom oversees the processes of designing and creating new instruments.

Введите семь цифр подряд в порядке A–G.
TEXT,
          null, ['1322131'],
          'Ответ: **1322131**. В диалоге прямо сказано “Haven’t seen you for ages”, Бренда называет себя несведущей в науке, Том различает medical и surgical equipment, описывает криохирургию и говорит, что контролирует весь процесс разработки.',
          3, 'sequence'
        ];

        $listening = [
          [3, 'According to Dr. Matthews, stargazing has become a luxury because…', ['telescopes have become expensive.', 'the air is too dirty to see through.', 'the sky never gets dark enough.'], 2, 'Искусственное освещение не даёт небу стать достаточно тёмным.'],
          [4, 'By exclaiming “Exactly.” Dr. Matthews…', ["points out that the presenter's point of view is common.", 'shows that he is happy that the presenter agrees with him.', "agrees with the presenter's point of view on the matter."], 0, 'Реплика подчёркивает, что высказанная ведущим точка зрения типична.'],
          [5, 'Sea turtles…', ['are only active when it is dark.', 'use darkness to catch food.', 'come ashore when it is dark.'], 2, 'Морские черепахи выходят на берег ночью, чтобы отложить яйца.'],
          [6, 'According to Dr. Matthews,…', ['natural resources should be used to fight light pollution efficiently.', 'fighting light pollution will result in preserving natural resources.', 'saving natural resources is more important than fighting light pollution.'], 1, 'Снижение светового загрязнения уменьшает бессмысленную трату энергии и природных ресурсов.'],
          [7, 'Dr. Matthews says that…', ['Paris suffers from light pollution more than other cities.', 'Paris uses simple traditional ways of fighting light pollution.', 'Paris uses modern technology to reduce light pollution.'], 1, 'Париж выключает подсветку памятников и требует выключать ночное освещение зданий.'],
          [8, 'Dr. Matthews believes that the key to solving the problem of light pollution lies in…', ['making electricity very expensive.', 'calculating the value of wasted energy.', 'recognizing that this problem really exists.'], 2, 'Проблему невозможно решить, пока люди не осознают ценность исчезающей темноты.'],
          [9, 'According to Dr. Matthews, Dark Sky Parks and Reserves…', ['can be visited by anyone.', 'prohibit the use of smartphones.', 'are meant for astronomers.'], 0, 'В этих парках проводятся программы для посетителей; люди могут гулять и наблюдать звёздное небо.'],
        ];
        foreach ($listening as [$number,$prompt,$options,$correct,$solution]) {
          $questions[] = ['single', "**Задание $number. Аудирование**\n\n$prompt", $options, [$correct], $solution, 1, 'full'];
        }

        $questions[] = [
          'text',
          <<<'TEXT'
**Задание 10. Чтение**

Установите соответствие между текстами A–G и заголовками 1–8. Используйте каждую цифру только один раз. Один заголовок лишний.

1. Look to your past
2. Health benefits of hobbies
3. Finding a hobby that suits you
4. Dangerous hobby
5. Time out with a purpose
6. Finding time for your hobby
7. Making new friends
8. Finding excitement

**A.** Hobbies provide work-free and responsibility-free time in your schedule. This can be especially useful for people who feel stressed by all that they have to do and need to recharge their batteries. For some of us it may be difficult to give ourselves permission to just sit and relax. Having a hobby, however, can provide a break and help people feel that they are not just “sitting around” but are using their free time for something productive.

**B.** Are there things you enjoyed as a child that you might still enjoy as an adult? Maybe you had a fantastic record collection, loved to make clothes for your dolls or were always out on your bike. Those are all things you could pick up again as an adult that would make great hobbies. Or there may be hobbies in your home right now that you started but have recently forgotten about. Maybe it is time to finish that crochet project or pick up the guitar again.

**C.** If you are adding a new thing into your life, you have to take time and focus away from something else. The good news is that most of us have a lot of time we are not using well, either because we are spending a lot of time online or watching TV or just wasting time we could be spending on our hobbies. See if you can spend a half hour or so every other day to explore your interest.

**D.** Of course, everyone is different and your personality does play a role in what sorts of hobbies you will like. If you do not have a lot of patience, you might feel that knitting is too much for you, but exploring quick sewing projects might be a better choice. Maybe you really like being with friends, so you need to take a class or have an interest that you can do with a group. If you travel a lot, something portable or that you can do anywhere is helpful.

**E.** One study found that those who engage in physical leisure activities for at least 20 minutes once a week are less likely to have fatigue. Other research found that enjoyable activities performed during leisure time were associated with lower blood pressure, total cortisol and body mass index, and feelings of better physical function. Such activities were also associated with higher levels of positive psychosocial states and lower levels of depression.

**F.** When you look for material or equipment for your hobby, you are likely to find people who have the same hobby as you. You may be surprised to know how serious some people are about their hobbies and therefore would have great knowledge of their chosen pastime. It is a great way to meet people with whom you have something in common.

**G.** For those who are not very stressed and may actually be understimulated, hobbies provide a nice source of eustress, the healthy kind of stress that we all need to remain feeling happy about life. If the rest of your life is somewhat dull or uninspiring, hobbies can provide meaning and fun and can break up a boring schedule without feeling like work.

Введите семь цифр подряд в порядке A–G.
TEXT,
          null, ['5163278'],
          'Ответ: **5163278**. A — полезный перерыв; B — возвращение к детским увлечениям; C — поиск времени; D — соответствие личности; E — польза для здоровья; F — новые знакомства; G — приятное возбуждение и вызов.',
          3, 'sequence'
        ];

        $questions[] = [
          'text',
          <<<'TEXT'
**Задание 11. Чтение**

Прочитайте текст и заполните пропуски A–F частями предложений 1–7. Одна часть лишняя.

### Saturday jobs: memories of weekend working

Research has shown a sharp fall in the number of teenagers who do Saturday jobs. It seems such a shame — my Saturday job as a kitchen porter was something of a rite of passage. I’ll never forget long hours **A ___**, scouring grease off huge saucepans and griddles. Working atmosphere there helped me grow a thicker skin, develop quicker banter and, most importantly, taught me the value of hard work. It also resulted in a steady supply of cash, **B ___**. I’m not the only one who has strong memories of weekend work. DJ Trevor Nelson said everyone should be able to have a Saturday job: “It taught me a lot, **C ___**.”

The link between the type of Saturday job a celebrity performed and their later career is sometimes obvious. Dragon’s Den star and businessman Peter Jones, for example, showed early promise by starting his own business. “I passed my Lawn Tennis Association coaching exam, **D ___**,” he explains. “At the start I was coaching other kids, **E ___** — for which I could charge £25–30 an hour.”

Skier Chemmy Alcott got a job working for the Good Ski Guide, on the advertising side. “It became clear to me what my personal value to companies could be. It led directly to me finding my head sponsor… and it offered me an eight-year contract. That gave me the financial backing **F ___**.”

1. and things would be different if everyone was given the chance
2. which let me know he approved of me
3. and I persuaded my local club to let me use a court on Saturdays
4. which I needed to become a professional skier
5. which I would happily spend as I liked
6. that I spent in the kitchen of a busy country pub in East Sussex
7. but soon I got adults wanting to book lessons

Введите шесть цифр подряд в порядке A–F.
TEXT,
          null, ['651374'],
          'Ответ: **651374**. Связки проверяются по грамматике и смыслу: long hours that I spent; cash which I would spend; taught me a lot, and…; exam, and I persuaded…; kids, but soon adults…; backing which I needed.',
          2, 'sequence'
        ];

        $readingText = <<<'TEXT'
### Tablets out, imagination in

In the heart of Silicon Valley is a nine-classroom school where employees of tech giants Google, Apple and Yahoo send their children. But despite its location in America’s digital centre, there is not an iPad, smartphone or screen in sight. The fact that parents working for the biggest technology companies are questioning the value of computers in education begs the question — is the futuristic dream of high-tech classrooms really in the best interests of the next generation?

A global report by the OECD organisation suggests that there is no link between excessive use of computers and high results for reading, maths and science. What is more, those students who use tablets and computers often tend to do worse than students who use them less frequently.

Beverly Amico from the Association of Waldorf Schools of North America explains that their teachers encourage students to learn subjects by expressing themselves through artistic activities such as painting and drawing, rather than consuming information downloaded onto a tablet. The idea is to get rid of the distraction of electronic media and to encourage stronger communication between teacher and pupil during lessons.

Amico claims one of the reasons parents who work in the digital industry are choosing a low-tech, no-tech education for their children is that it teaches students innovative thinking skills. And these are what many employers desire. She adds that students weaned on technology often lack the ability to think outside the box and solve problems.

Sarah Thorne, head of the London Acorn school, also questions the belief that limiting or removing the use of technology in class will make the students less competitive on the job market.

Students under the age of 12 at the school in Morden, London, are banned from using smartphones and computers, and watching TV or films at all times, including during holidays. The school’s students are allowed to watch TV once they reach 12 years old and then only documentaries that have been previously vetted by parents. They cannot watch films until they are 14. The Internet is banned completely for everyone under 16, both at home and at school. Computers are only to be used as part of the school curriculum for students who are at least 14.

It may sound draconian, but Thorne believes taking a more considered approach to the use of technology in class allows teachers to help students develop core skills such as leadership, decision making and creativity. Besides, much of the technology which is cutting edge today is likely to appear primitive in tomorrow’s world.

Thorne claims feedback from students about the restrictions has been positive. Younger pupils relish the chance to play and even teenagers who have come from a typical school admit they are happier.

Restricting the use of technology is also a challenge for the 21st-century teachers, who are used to the easy accessibility of resources and information, thanks to interactive whiteboards and computers. “It is hard work,” admits Ian Young, a class teacher. “You definitely have to be a lot more creative in how you deliver a lesson. You have to work with your voice more, whether it is loud or quiet, to give them incentive. You need to make sure you keep them interested in what’s coming next.” He adds: “I don’t think we are doing children any favours by teaching them through machines at that young age.”
TEXT;
        $reading = [
          [12, 'What question does the author pose in the first paragraph?', ['What will education be like in the future?', 'Is high-tech really very useful in education?', 'How will the parents from technology companies educate their children?', 'Should hi-tech gadgets be allowed in the classroom?'], 1, 'Автор ставит под сомнение пользу высокотехнологичных классов для следующего поколения.'],
          [13, 'What does the OECD report suggest?', ['Students should not use computers in their studies.', "Superabundant use of computers does not influence students’ achievements.", 'Results in Reading, Maths and Science benefit from use of computers.', 'The more students use computers, the worse their results could be.'], 3, 'В тексте сказано, что часто использующие компьютеры ученики показывают худшие результаты.'],
          [14, 'Both Beverly Amico and Sarah Thorne think that limiting technology in the classroom will…', ["boost students’ creativity.", 'better prepare students for the job market.', "develop students’ communication skills.", 'help students concentrate better.'], 0, 'Обе подчёркивают развитие инновационного мышления и творческих навыков.'],
          [15, 'At the age of fourteen the students of Morden are allowed to…', ['watch films.', 'use the Internet.', 'watch TV.', 'use smartphones.'], 0, 'В тексте прямо сказано: “They cannot watch films until they are 14.”'],
          [16, 'The word “relish” in paragraph 8 means…', ['enjoy.', 'ignore.', 'envy.', 'inspire.'], 0, 'Relish означает «получать удовольствие, наслаждаться».'],
          [17, 'The word “they” in paragraph 8 (“admit they are happier”) refers to…', ['younger pupils.', 'restrictions.', 'teenagers.', 'teachers.'], 2, 'Местоимение they относится к teenagers.'],
          [18, 'Ian Young believes that the 21st century demands that a teacher becomes more…', ['creative.', 'hardworking.', 'entertaining.', 'informative.'], 0, 'Учитель прямо говорит: “You definitely have to be a lot more creative.”'],
        ];
        foreach ($reading as $index => [$number,$prompt,$options,$correct,$solution]) {
          $intro = $index === 0 ? $readingText . "\n\n" : '**Используйте текст “Tablets out, imagination in” из задания 12.**' . "\n\n";
          $questions[] = ['single', $intro . "**Задание $number.**\n\n$prompt", $options, [$correct], $solution, 1, 'full'];
        }

        $wordForms = [
          [19, 'The Capybara is a large, semi-aquatic rodent that is found ___ the waterlogged regions of Central and South America. **INHABIT**', ['inhabiting'], 'Ответ: **inhabiting**. После “is found” нужна неличная -ing-форма со значением состояния.'],
          [20, 'Closely related to other South American rodents, Capybaras are the largest ___ among all rodents in the world. **ONE**', ['ones'], 'Ответ: **ones**. Местоимение заменяет существительное rodents во множественном числе.'],
          [21, 'Despite their enormous size, Capybaras are animals that ___ well to life in the water. **ADAPT**', ['have adapted','haveadapted'], 'Ответ: **have adapted**. Нужен Present Perfect: результат адаптации важен сейчас.'],
          [22, 'Capybaras have a number of distinctive ___ that aid their amphibious lifestyle. **CHARACTERISTIC**', ['characteristics'], 'Ответ: **characteristics**. После “a number of” требуется существительное во множественном числе.'],
          [23, 'The common name of the Capybara is thought to ___ from the word that means “Master of the Grasses”. **ORIGINATE**', ['have originated','haveoriginated'], 'Ответ: **have originated**. После “is thought” используется перфектный инфинитив.'],
          [24, 'Its scientific name can ___ back to the Greek word for water hog. **TRACE**', ['be traced','betraced'], 'Ответ: **be traced**. После модального глагола требуется пассивный инфинитив.'],
          [25, 'Jenna Ortega, a star in one of the most popular Netflix ___, Wednesday, decided to get involved with the writing process. **ADAPT**', ['adaptations'], 'Ответ: **adaptations**. Конструкция “one of the” требует существительного во множественном числе.'],
          [26, 'She actually ___ the established script that was originally given to her. **REGARD**', ['disregarded'], 'Ответ: **disregarded**. Нужен глагол с отрицательной приставкой dis- в Past Simple.'],
          [27, '“Everything that Wednesday Addams does did not make sense for her character,” the young ___ explained. **ACT**', ['actress'], 'Ответ: **actress**. Речь идёт об актрисе Дженне Ортеге.'],
          [28, 'Steven DeKnight, a screenwriter and producer of Smallville, was the first one to ___ of Ortega’s behaviour. **APPROVE**', ['disapprove'], 'Ответ: **disapprove**. Требуется отрицательное значение и инфинитив после “the first one to”.'],
          [29, 'When asked if Ortega’s comments were simply a result of ___, he was clearly doubtful as she criticised the showrunners publicly. **CONCEPTION**', ['misconception'], 'Ответ: **misconception**. Нужна приставка mis- со значением ошибочного представления.'],
        ];
        foreach ($wordForms as [$number,$prompt,$correct,$solution]) {
          $questions[] = ['text', "**Задание $number. Грамматика и лексика**\n\n$prompt", null, $correct, $solution, 1, 'full'];
        }

        $lexis = [
          [30, 'Henry did this because he wanted to ___ that people spend too much time and money on food and clothes.', ['agree','secure','ensure','prove'], 3, 'Ответ: **prove** — «доказать».'],
          [31, '___ to the writer, he spent only one hundred dollars a year while he lived in this hut.', ['According','Including','Regarding','Concerning'], 0, 'Ответ: **According**. Устойчивая конструкция “according to”.'],
          [32, 'He wrote a book called “The Wood” which ___ all about the days he lived in his hut.', ['says','talks','tells','speaks'], 2, 'Ответ: **tells** — книга повествует о событиях.'],
          [33, 'Several other books also describe the time when he ___ to live all by himself.', ['held','made','kept','used'], 3, 'Ответ: **used**. Конструкция “used to do” описывает прошлую привычку.'],
          [34, '“I am for simple living, and I ___ being alone!” he would declare.', ['amuse','please','enjoy','satisfy'], 2, 'Ответ: **enjoy** — enjoy being alone.'],
          [35, 'It ___ you feel, when you read his books, that it is fine to walk around the fields.', ['turns','makes','gives','takes'], 1, 'Ответ: **makes**. Конструкция “make somebody feel”.'],
          [36, '___, people passing by the pond used to hear him whistling old ballads and thought he sounded lonely and sad.', ['Although','Otherwise','However','Moreover'], 2, 'Ответ: **However** — вводит противопоставление.'],
        ];
        foreach ($lexis as [$number,$prompt,$options,$correct,$solution]) {
          $questions[] = ['single', "**Задание $number. Грамматика и лексика**\n\n$prompt", $options, [$correct], $solution, 1, 'full'];
        }

        $questions[] = [
          'textarea',
          <<<'TEXT'
**Задание 37. Электронное письмо**

You have received an email message from your English-speaking pen-friend David:

> Yesterday I had to go to the psychologist because of the loss of motivation at school. Do Russian teenagers ever visit a psychologist? What typical problems are teenagers facing nowadays? If you needed psychological help, would you go to a professional or ask your parents for advice? Why?

> By the way, I’m going to have a photo session for my graduation album next week…

Write an email to David. In your letter:

- answer his questions;
- ask 3 questions about the graduation album.

Write **100–140 words**. Remember the rules of email writing.
TEXT,
          null, null,
          <<<'TEXT'
Dear David,

Thank you for your email. I was happy to get it.

In your message you ask me a few questions. As for teenagers in Russia, many of them visit psychologists nowadays. I think that adolescents are facing problems with communication and finding their purpose and occupation in life. Well, if I needed psychological help, I would definitely turn to a professional, because they have relevant education and experience that could help me cope with difficulties.

By the way, I’m really curious about your graduation album. Will the album contain photos of all the students? Will it be made by a professional or by the students themselves? What style will it be made in?

Sorry, I’m finishing as I have to help my mother. Write back soon.

Love,
Tanya
TEXT,
          6, 'full'
        ];

        $questions[] = [
          'textarea',
          <<<'TEXT'
**Задание 38. Развёрнутое письменное высказывание**

Выберите **только один** вариант — 38.1 или 38.2. Напишите **200–250 слов**.

### 38.1

Imagine that you are doing a project on why teenagers in Zetland choose online courses to prepare for exams. You have found some data on the subject — the results of a survey conducted among teenagers in Zetland.

| Reasons | Number of respondents (%) |
|---|---:|
| Affordability | 42 |
| Flexible schedule | 28 |
| Easy access | 17 |
| Wide range of learning programmes | 9 |
| Personalized learning experience | 4 |

Comment on the data in the table and give your personal opinion on the subject of the project.

### 38.2

Imagine that you are doing a project on what problems teenagers in Zetland face when learning a language. You have found some data on the subject — the results of a survey conducted among teenagers in Zetland.

![Pie chart: problems teenagers face when learning a language](/img/mocks/english-september-written-38-2.svg)

Comment on the data in the pie chart and give your personal opinion on the subject of the project.

### Plan

- make an opening statement on the subject of the project;
- select and report 2–3 facts;
- make 1–2 comparisons where relevant and give your comments;
- outline a problem connected with the subject and suggest a way of solving it;
- conclude by giving and explaining your opinion.
TEXT,
          null, null,
          <<<'TEXT'
**Пример 38.1**

I am doing a project on why teenagers in Zetland choose online courses to prepare for exams. I have found a table with the results of a relevant survey and I am going to analyse the data.

According to the table, affordability is the most popular reason, chosen by 42% of respondents. A flexible schedule ranks second at 28%, while easy access is mentioned by 17%. Affordability is 14 percentage points more popular than flexibility and over four times as common as a wide range of programmes, at 9%. This may be because families want effective preparation without spending too much money.

One problem when choosing an online school is that its advertising may not reflect the real quality of teaching. As a result, a student may waste both time and money. The best solution is to study independent reviews, attend a trial lesson and check teachers’ qualifications before paying.

In conclusion, I believe that combining online classes with regular independent practice is the ideal way for me to prepare for exams. Online courses provide structure and feedback, while self-study helps me work on my personal weaknesses.

**Пример 38.2**

I am doing a project on the problems teenagers in Zetland face when learning a language. I have found a pie chart with the results of a relevant survey and I am going to analyse the data.

According to the chart, difficult grammar is the most common problem, named by 38% of respondents. Pronunciation comes second at 22%, whereas 18% feel embarrassed. Grammar is 16 percentage points more common than pronunciation and almost four times as common as idioms and slang, at 10%. This suggests that systematic language rules cause more difficulty than informal vocabulary.

A serious problem connected with speaking a foreign language is the fear of making mistakes. It may prevent learners from practising and becoming fluent. A good solution is to join a friendly conversation club where learners of the same level can speak regularly without being judged.

In conclusion, I believe that learning languages plays an important role in teenagers’ lives because it broadens their horizons, improves communication skills and creates more opportunities for education and travel.
TEXT,
          14, 'full'
        ];

        $insert = $pdo->prepare(
          'INSERT INTO questions(mock_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,?,?,?,?,?,NULL,?,0,0,0,?,1,?)'
        );
        $questionIds = [];
        foreach ($questions as $position => [$type,$text,$options,$correct,$solution,$points,$gradeMode]) {
          $insert->execute([
            $mockId, $type, $text,
            $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $correct === null ? null : json_encode($correct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $solution, $points, $gradeMode, $position,
          ]);
          $questionIds[$position + 1] = (int)$pdo->lastInsertId();
        }

        $attach = $pdo->prepare("INSERT INTO question_attachments(question_id,kind,name,size,url,position) VALUES(?,'audio',?,?,?,0)");
        $attach->execute([$questionIds[1], 'Аудио к заданию 1', '12,1 МБ', '/audio/mocks/english-september-written-task-1.mp3']);
        $attach->execute([$questionIds[2], 'Аудио к заданию 2', '9,8 МБ', '/audio/mocks/english-september-written-task-2.mp3']);
        foreach (range(3, 9) as $number) {
          $attach->execute([$questionIds[$number], 'Аудио к заданиям 3–9', '9,1 МБ', '/audio/mocks/english-september-written-tasks-3-9.mp3']);
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Английский, сентябрь: создан входной письменный пробник с 38 заданиями и аудированием');
  },

  // v118 — базовое ДЗ к уроку вводного интенсива по обществознанию
  // «Вся политика за 60 минут»: 10 заданий с автопроверкой и решениями.
  118 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_sprint_politics_homework.php';

    $lessonSt = $pdo->query(
      "SELECT l.id, l.title, c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='social' AND l.title='Вся политика за 60 минут'
       ORDER BY (c.title LIKE '%СПРИНТ%') DESC, (c.type='Вводные') DESC, c.position, l.id
       LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) {
      out('  ! ДЗ «Вся политика за 60 минут» пропущено: урок не найден');
      return;
    }

    $lessonId = (int)$lesson['id'];
    $title = 'Вся политика за 60 минут (база)';
    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id=? AND title=? ORDER BY id LIMIT 1');
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);

      if ($homeworkId <= 0) {
        $blank = $pdo->prepare(
          "SELECT h.id,h.title FROM homeworks h
           WHERE h.lesson_id=? AND (h.level='normal' OR h.level IS NULL OR h.level='')
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id=h.id)
           ORDER BY CASE WHEN h.title LIKE '%политик%' OR h.title='Базовый уровень' THEN 0 ELSE 1 END,h.id"
        );
        $blank->execute([$lessonId]);
        $blanks = $blank->fetchAll();
        if ($blanks && (count($blanks) === 1 || preg_match('/политик|базовый уровень/ui', (string)$blanks[0]['title']))) {
          $homeworkId = (int)$blanks[0]['id'];
          $pdo->prepare("UPDATE homeworks SET title=?,level='normal' WHERE id=?")->execute([$title, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM homeworks WHERE lesson_id=?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare("INSERT INTO homeworks(lesson_id,level,title,deadline,position) VALUES(?,'normal',?,'',?)")
              ->execute([$lessonId, $title, (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id=?');
      $countSt->execute([$homeworkId]);
      $existing = (int)$countSt->fetchColumn();
      if ($existing === 0) {
        $insert = $pdo->prepare(
          "INSERT INTO questions(homework_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
        );
        $hint = 'Запиши последовательность цифр без пояснений. Пробелы, запятые или точки с запятой между цифрами допустимы.';
        foreach (social_sprint_politics_homework_questions() as $position => [$text,$correct,$solution]) {
          $insert->execute([
            $homeworkId,
            $text,
            json_encode($correct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $solution,
            $hint,
            $position,
          ]);
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Обществознание, СПРИНТ: ДЗ «' . $title . '» создано у урока «' . $lesson['title'] . '»; вопросов: 10');
  },

  // v119 — очищенный учебник к вебинару основного курса обществознания
  // от 14 сентября. Идемпотентно привязываем один и тот же публичный файл.
  119 => function (PDO $pdo, string $driver) {
    $lessonSt = $pdo->query(
      "SELECT l.id,l.title,c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='social'
         AND (
           l.air_at LIKE '2026-09-14%'
           OR l.air LIKE '%14.09%'
           OR l.air LIKE '%14.9%'
           OR l.air LIKE '%14 сентября%'
           OR l.title LIKE '%Связь человека с природой%'
         )
       ORDER BY (c.type='Основные') DESC,(c.title LIKE '%СПРИНТ%') ASC,c.position,l.id
       LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) {
      out('  ! Учебник к вебинару 14 сентября не прикреплён: урок обществознания не найден');
      return;
    }

    $lessonId = (int)$lesson['id'];
    $url = '/files/social/obschestvo-14-sentyabrya-uchebnik.pdf';
    $find = $pdo->prepare('SELECT id FROM materials WHERE lesson_id=? AND url=? ORDER BY id LIMIT 1');
    $find->execute([$lessonId, $url]);
    $materialId = (int)($find->fetchColumn() ?: 0);
    if ($materialId > 0) {
      $pdo->prepare("UPDATE materials SET name='Учебник к вебинару',size='23,3 МБ',kind='pdf' WHERE id=?")
          ->execute([$materialId]);
    } else {
      $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM materials WHERE lesson_id=?');
      $positionSt->execute([$lessonId]);
      $pdo->prepare("INSERT INTO materials(lesson_id,name,size,url,kind,position) VALUES(?,'Учебник к вебинару','23,3 МБ',?,'pdf',?)")
          ->execute([$lessonId, $url, (int)$positionSt->fetchColumn()]);
    }

    live_content_touch();
    out('  + Учебник прикреплён: «' . $lesson['course_title'] . '» → «' . $lesson['title'] . '»');
  },

  // v120 — учебник лежит на основном домене, а кабинет может быть открыт на
  // поддомене. Абсолютный URL не попадает под SPA-маршрутизацию поддомена.
  120 => function (PDO $pdo, string $driver) {
    $oldUrl = '/files/social/obschestvo-14-sentyabrya-uchebnik.pdf';
    $newUrl = 'https://100-points.ru/files/social/obschestvo-14-sentyabrya-uchebnik.pdf';
    $st = $pdo->prepare('UPDATE materials SET url=? WHERE url=?');
    $st->execute([$newUrl, $oldUrl]);
    live_content_touch();
    out('  + Ссылка на учебник переведена на абсолютный адрес; обновлено: ' . $st->rowCount());
  },

  // v121 — очищенный список лексики к эфиру Present Simple 15 сентября.
  121 => function (PDO $pdo, string $driver) {
    $findLesson = $pdo->query(
      "SELECT l.id,l.title,c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='english' AND c.type='Основные'
         AND l.title='Present Simple' AND l.air_at LIKE '2026-09-15%'
       ORDER BY c.position,c.id,l.id LIMIT 1"
    );
    $lesson = $findLesson ? $findLesson->fetch() : false;
    if (!$lesson) {
      out('  ! Список лексики не прикреплён: эфир Present Simple 15 сентября не найден');
      return;
    }

    $lessonId = (int)$lesson['id'];
    $name = 'Список лексики';
    // Абсолютный адрес нужен для уроков, открытых на поддомене lk.
    $url = 'https://100-points.ru/files/english/english-15-september-vocabulary-clean.pdf';
    $findMaterial = $pdo->prepare('SELECT id FROM materials WHERE lesson_id=? AND (name=? OR url=?) ORDER BY id LIMIT 1');
    $findMaterial->execute([$lessonId, $name, $url]);
    $materialId = (int)($findMaterial->fetchColumn() ?: 0);
    if ($materialId > 0) {
      $pdo->prepare('UPDATE materials SET name=?,size=?,url=?,kind=? WHERE id=?')
          ->execute([$name, '12,3 МБ', $url, 'pdf', $materialId]);
    } else {
      $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM materials WHERE lesson_id=?');
      $positionSt->execute([$lessonId]);
      $pdo->prepare('INSERT INTO materials(lesson_id,name,size,url,kind,position) VALUES(?,?,?,?,?,?)')
          ->execute([$lessonId, $name, '12,3 МБ', $url, 'pdf', (int)$positionSt->fetchColumn()]);
    }
    live_content_touch();
    out('  + Список лексики прикреплён: «' . $lesson['course_title'] . '» → «' . $lesson['title'] . '» (15 сентября)');
  },

  // v122 — два очищенных пособия к русскому установочному эфиру 14 сентября.
  122 => function (PDO $pdo, string $driver) {
    $findLesson = $pdo->query(
      "SELECT l.id,l.title,c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='rus' AND c.type='Основные'
         AND l.title='Установочный стрим. Задание 4'
         AND l.air_at LIKE '2026-09-14%'
       ORDER BY c.position,c.id,l.id LIMIT 1"
    );
    $lesson = $findLesson ? $findLesson->fetch() : false;
    if (!$lesson) {
      out('  ! Пособия по заданию 4 не прикреплены: русский эфир 14 сентября не найден');
      return;
    }

    $lessonId = (int)$lesson['id'];
    $materials = [
      ['Навигатор ЕГЭ', '6,2 МБ', 'https://100-points.ru/files/russian/russian-14-september-ege-navigator-clean.pdf'],
      ['Практика: задание 4', '4,9 МБ', 'https://100-points.ru/files/russian/russian-14-september-orthoepy-practice-clean.pdf'],
    ];
    $findMaterial = $pdo->prepare('SELECT id FROM materials WHERE lesson_id=? AND (name=? OR url=?) ORDER BY id LIMIT 1');
    $updateMaterial = $pdo->prepare('UPDATE materials SET name=?,size=?,url=?,kind=? WHERE id=?');
    $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM materials WHERE lesson_id=?');
    $insertMaterial = $pdo->prepare('INSERT INTO materials(lesson_id,name,size,url,kind,position) VALUES(?,?,?,?,?,?)');
    foreach ($materials as [$name, $size, $url]) {
      $findMaterial->execute([$lessonId, $name, $url]);
      $materialId = (int)($findMaterial->fetchColumn() ?: 0);
      if ($materialId > 0) {
        $updateMaterial->execute([$name, $size, $url, 'pdf', $materialId]);
      } else {
        $positionSt->execute([$lessonId]);
        $insertMaterial->execute([$lessonId, $name, $size, $url, 'pdf', (int)$positionSt->fetchColumn()]);
      }
    }
    live_content_touch();
    out('  + Навигатор и практика прикреплены: «' . $lesson['course_title'] . '» → «' . $lesson['title'] . '» (14 сентября)');
  },

  // v123 — интерактивное ДЗ к заданию 4 по русскому, 25 вариантов из PDF
  // с автопроверкой и разборами после сдачи.
  123 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/russian_september_14_orthoepy_homework.php';
    $lessonSt = $pdo->query(
      "SELECT l.id,l.title,c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='rus' AND c.type='Основные'
         AND l.title='Установочный стрим. Задание 4'
         AND l.air_at LIKE '2026-09-14%'
       ORDER BY c.position,c.id,l.id LIMIT 1"
    );
    $lesson = $lessonSt ? $lessonSt->fetch() : false;
    if (!$lesson) {
      out('  ! ДЗ по заданию 4 не создано: русский эфир 14 сентября не найден');
      return;
    }

    $lessonId = (int)$lesson['id'];
    $title = 'Задание 4: ударения (база)';
    $questions = russian_september_14_orthoepy_homework_questions();
    if (count($questions) !== 25) throw new RuntimeException('ДЗ по заданию 4 должно содержать 25 вопросов');
    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id=? AND title=? ORDER BY id LIMIT 1');
      $find->execute([$lessonId, $title]);
      $homeworkId = (int)($find->fetchColumn() ?: 0);
      if ($homeworkId <= 0) {
        $blank = $pdo->prepare(
          "SELECT h.id,h.title FROM homeworks h
           WHERE h.lesson_id=? AND (h.level='normal' OR h.level IS NULL OR h.level='')
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id=h.id)
           ORDER BY CASE WHEN h.title LIKE '%задани% 4%' OR h.title='Базовый уровень' THEN 0 ELSE 1 END,h.id"
        );
        $blank->execute([$lessonId]);
        $blanks = $blank->fetchAll();
        if ($blanks && (count($blanks) === 1 || preg_match('/задани.{0,3}4|базовый уровень/ui', (string)$blanks[0]['title']))) {
          $homeworkId = (int)$blanks[0]['id'];
          $pdo->prepare("UPDATE homeworks SET title=?,level='normal' WHERE id=?")->execute([$title, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM homeworks WHERE lesson_id=?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare("INSERT INTO homeworks(lesson_id,level,title,deadline,position) VALUES(?,'normal',?,'',?)")
              ->execute([$lessonId, $title, (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id=?');
      $countSt->execute([$homeworkId]);
      $existing = (int)$countSt->fetchColumn();
      if ($existing === 0) {
        $insert = $pdo->prepare(
          "INSERT INTO questions(homework_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
        );
        $hint = 'Введи номера выбранных вариантов. Пробелы, запятые и порядок цифр не влияют на проверку.';
        foreach ($questions as $position => [$text, $correct, $solution]) {
          $insert->execute([
            $homeworkId, $text,
            json_encode($correct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $solution, $hint, $position,
          ]);
        }
      } elseif ($existing !== 25) {
        throw new RuntimeException('Существующее ДЗ по заданию 4 уже содержит ' . $existing . ' вопросов; не перезаписываем данные');
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Русский, 14 сентября: ДЗ «' . $title . '» привязано к «' . $lesson['title'] . '»; вопросов: 25');
  },

  // v124 — ДЗ к эфиру «№7. Основы тригонометрии» 14 сентября.
  // Дедлайн не указан в исходных файлах: оставляем существующий либо пустой.
  124 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/math_september_14_trigonometry_homework.php';
    $lessonSt = $pdo->query(
      "SELECT l.id,l.title,c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='math' AND c.type='Основные'
         AND l.title='№7. Основы тригонометрии'
         AND l.air_at LIKE '2026-09-14%'
       ORDER BY l.id"
    );
    $matches = $lessonSt ? $lessonSt->fetchAll() : [];
    if (count($matches) !== 1) throw new RuntimeException('ДЗ по математике: ожидали один урок 14 сентября, найдено ' . count($matches));
    $lesson = $matches[0];
    $lessonId = (int)$lesson['id'];
    $title = 'Основы тригонометрии — задания 1–16';
    $questions = math_september_14_trigonometry_homework_questions();
    if (count($questions) !== 16) throw new RuntimeException('ДЗ по математике должно содержать 16 заданий');

    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id=? AND title=? ORDER BY id');
      $find->execute([$lessonId, $title]);
      $named = $find->fetchAll(PDO::FETCH_COLUMN);
      if (count($named) > 1) throw new RuntimeException('Несколько ДЗ с одинаковым названием — не меняем их');
      $homeworkId = $named ? (int)$named[0] : 0;
      if ($homeworkId <= 0) {
        $blank = $pdo->prepare(
          "SELECT h.id FROM homeworks h
           WHERE h.lesson_id=? AND (h.level='normal' OR h.level IS NULL OR h.level='')
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id=h.id)
           ORDER BY h.id"
        );
        $blank->execute([$lessonId]);
        $blanks = $blank->fetchAll(PDO::FETCH_COLUMN);
        if (count($blanks) > 1) throw new RuntimeException('Найдено несколько пустых базовых ДЗ — не выбираем произвольно');
        if ($blanks) {
          $homeworkId = (int)$blanks[0];
          $pdo->prepare("UPDATE homeworks SET title=?,level='normal' WHERE id=?")->execute([$title, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM homeworks WHERE lesson_id=?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare("INSERT INTO homeworks(lesson_id,level,title,deadline,position) VALUES(?,'normal',?,'',?)")
              ->execute([$lessonId, $title, (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id=?');
      $countSt->execute([$homeworkId]);
      $existing = (int)$countSt->fetchColumn();
      if ($existing === 0) {
        $insert = $pdo->prepare(
          "INSERT INTO questions(homework_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,'text',?,NULL,?,?,?,1,0,0,0,'full',1,?)"
        );
        foreach ($questions as $position => [$text, $answer, $solution]) {
          $insert->execute([$homeworkId, $text, json_encode([$answer], JSON_UNESCAPED_UNICODE), $solution,
                            'Введи только числовой ответ; отрицательное число — с минусом.', $position]);
        }
      } elseif ($existing !== 16) {
        throw new RuntimeException('ДЗ по математике уже содержит ' . $existing . ' вопросов — не перезаписываем');
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Математика, 14 сентября: ДЗ «' . $title . '» привязано к «' . $lesson['title'] . '»; вопросов: 16');
  },

  // v125 — дедлайн этого ДЗ: семь дней от 15.09.2026, 23:59 МСК.
  125 => function (PDO $pdo, string $driver) {
    $find = $pdo->query(
      "SELECT h.id,h.deadline
       FROM homeworks h
       JOIN lessons l ON l.id=h.lesson_id
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='math' AND c.type='Основные'
         AND l.title='№7. Основы тригонометрии'
         AND l.air_at LIKE '2026-09-14%'
         AND h.title='Основы тригонометрии — задания 1–16'"
    );
    $matches = $find ? $find->fetchAll() : [];
    if (count($matches) !== 1) throw new RuntimeException('Дедлайн ДЗ по математике: ожидали одну работу, найдено ' . count($matches));
    $deadline = '2026-09-22 23:59:00';
    $old = trim((string)$matches[0]['deadline']);
    if ($old !== '' && $old !== $deadline) throw new RuntimeException('Дедлайн уже изменён вручную: ' . $old . '; не перезаписываем');
    $pdo->prepare('UPDATE homeworks SET deadline=? WHERE id=?')->execute([$deadline, (int)$matches[0]['id']]);
    live_content_touch();
    out('  + Математика, 14 сентября: дедлайн ДЗ установлен на 22.09.2026 23:59 МСК');
  },

  // v126 — ДЗ из двух PDF к эфиру основного курса обществознания 14 сентября.
  126 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_september_14_person_society_homework.php';
    $lessonSt = $pdo->query(
      "SELECT l.id,l.title,c.title AS course_title
       FROM lessons l
       JOIN blocks b ON b.id=l.block_id
       JOIN courses c ON c.id=b.course_id
       JOIN subjects s ON s.id=c.subject_id
       WHERE s.slug='social' AND c.type='Основные'
         AND l.title='Человек, потребности, общество'
         AND l.air_at LIKE '2026-09-14%'
       ORDER BY l.id"
    );
    $lessons = $lessonSt ? $lessonSt->fetchAll() : [];
    if (count($lessons) !== 1) throw new RuntimeException('Общество, 14 сентября: ожидали один урок, найдено ' . count($lessons));
    $lesson = $lessons[0];
    $lessonId = (int)$lesson['id'];
    $title = 'Человек, потребности, общество — задания 1–20';
    $deadline = '2026-09-22 23:59:00';
    $questions = social_september_14_person_society_homework_questions();
    if (count($questions) !== 20) throw new RuntimeException('Общество, 14 сентября: ожидается 20 заданий');

    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id,deadline FROM homeworks WHERE lesson_id=? AND title=? ORDER BY id');
      $find->execute([$lessonId, $title]);
      $named = $find->fetchAll();
      if (count($named) > 1) throw new RuntimeException('Несколько одинаковых ДЗ в уроке — не меняем данные');
      $homeworkId = $named ? (int)$named[0]['id'] : 0;
      $oldDeadline = $named ? trim((string)$named[0]['deadline']) : '';
      if ($homeworkId <= 0) {
        $blank = $pdo->prepare(
          "SELECT h.id,h.deadline FROM homeworks h
           WHERE h.lesson_id=? AND (h.level='normal' OR h.level IS NULL OR h.level='')
             AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.homework_id=h.id)
           ORDER BY h.id"
        );
        $blank->execute([$lessonId]);
        $blanks = $blank->fetchAll();
        if (count($blanks) > 1) throw new RuntimeException('Несколько пустых обычных ДЗ — нельзя выбрать произвольно');
        if ($blanks) {
          $homeworkId = (int)$blanks[0]['id'];
          $oldDeadline = trim((string)$blanks[0]['deadline']);
          if ($oldDeadline !== '' && $oldDeadline !== $deadline) throw new RuntimeException('У пустого ДЗ уже есть ручной дедлайн ' . $oldDeadline);
          $pdo->prepare("UPDATE homeworks SET title=?,level='normal',deadline=? WHERE id=?")
              ->execute([$title, $deadline, $homeworkId]);
        } else {
          $positionSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM homeworks WHERE lesson_id=?');
          $positionSt->execute([$lessonId]);
          $pdo->prepare("INSERT INTO homeworks(lesson_id,level,title,deadline,position) VALUES(?,'normal',?,?,?)")
              ->execute([$lessonId, $title, $deadline, (int)$positionSt->fetchColumn()]);
          $homeworkId = (int)$pdo->lastInsertId();
        }
      } else {
        if ($oldDeadline !== '' && $oldDeadline !== $deadline) throw new RuntimeException('Дедлайн изменён вручную: ' . $oldDeadline);
        $pdo->prepare('UPDATE homeworks SET deadline=? WHERE id=?')->execute([$deadline, $homeworkId]);
      }

      $countSt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE homework_id=?');
      $countSt->execute([$homeworkId]);
      $existing = (int)$countSt->fetchColumn();
      if ($existing === 0) {
        $insert = $pdo->prepare(
          "INSERT INTO questions(homework_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,'text',?,NULL,?,?,?,1,0,0,0,?,1,?)"
        );
        foreach ($questions as $position => [$text, $answers, $solution, $isMatch]) {
          $insert->execute([
            $homeworkId, $text, json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $solution,
            $isMatch ? 'Введи пять цифр строго в порядке А, Б, В, Г, Д.' : 'Введи цифры выбранных вариантов; их порядок не влияет на проверку.',
            $isMatch ? 'sequence' : 'full', $position,
          ]);
        }
      } elseif ($existing !== 20) {
        throw new RuntimeException('В ДЗ уже есть ' . $existing . ' заданий, не перезаписываем их');
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    live_content_touch();
    out('  + Общество, 14 сентября: «' . $title . '» → «' . $lesson['title'] . '»; 20 вопросов, дедлайн 22.09.2026 23:59 МСК');
  },

  // v127 — базовое ДЗ по истории: 21 задание, атлас и контурная карта к №21.
  127 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/history_september_15_slavs_homework.php';
    $id = history_september_15_slavs_homework_seed($pdo);
    live_content_touch();
    out('  + История, 15 сентября: ДЗ #' . $id . ', 21 задание, базовый уровень; дедлайн новой работы 23.09.2026 23:59 МСК');
  },

  // v128 — Present Simple 15 сентября: Бронза, Серебро, Золото.
  128 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/english_september_15_present_simple_homework.php';
    $ids = english_september_15_present_simple_homework_seed($pdo);
    live_content_touch();
    out('  + Present Simple: ДЗ ' . implode(', ', $ids) . '; 25/26/47 вопросов, дедлайн новых работ 23.09.2026 23:59 МСК');
  },

  // v129 — составные уроки: до 10 полноценных занятий внутри одного пункта программы.
  // Дочернее занятие остаётся обычной записью lessons, поэтому у него независимо
  // работают видео, эфир, описание, материалы, таймкоды, блокировки и ДЗ.
  129 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'lessons', 'parent_lesson_id')) {
      $pdo->exec('ALTER TABLE lessons ADD COLUMN parent_lesson_id INT NULL');
    }
    try { $pdo->exec('CREATE INDEX idx_lessons_parent_position ON lessons(parent_lesson_id,position,id)'); }
    catch (Throwable $e) { /* индекс уже есть */ }

    // Наглядный пример в существующей английской «Летней школе».
    // Повторный прогон безопасен: контейнер ищется по названию внутри выбранного блока.
    $blockSt = $pdo->prepare(
      "SELECT b.id
       FROM blocks b
       JOIN courses c ON c.id=b.course_id
       JOIN months m ON m.id=b.month_id
       WHERE c.title='Летняя школа по Английскому языку'
         AND m.name='Август'
       ORDER BY b.position DESC,b.id DESC LIMIT 1"
    );
    $blockSt->execute();
    $blockId = (int)($blockSt->fetchColumn() ?: 0);
    if ($blockId > 0) {
      $existing = $pdo->prepare("SELECT id FROM lessons WHERE block_id=? AND parent_lesson_id IS NULL AND title='Тест: урок из нескольких занятий' LIMIT 1");
      $existing->execute([$blockId]);
      $parentId = (int)($existing->fetchColumn() ?: 0);
      if ($parentId === 0) {
        $posSt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM lessons WHERE block_id=? AND parent_lesson_id IS NULL');
        $posSt->execute([$blockId]);
        $pdo->prepare(
          "INSERT INTO lessons(block_id,parent_lesson_id,type,title,meta,video_source,description,position)
           VALUES(?,NULL,'practice','Тест: урок из нескольких занятий','4 занятия внутри','none',?,?)"
        )->execute([
          $blockId,
          "Этот пункт программы объединяет несколько самостоятельных занятий. Выберите нужное занятие в списке слева.",
          (int)$posSt->fetchColumn(),
        ]);
        $parentId = (int)$pdo->lastInsertId();

        $sourceSt = $pdo->prepare(
          "SELECT kinescope_id,duration FROM lessons
           WHERE block_id=? AND parent_lesson_id IS NULL AND kinescope_id IS NOT NULL AND kinescope_id<>''
           ORDER BY position,id LIMIT 1"
        );
        $sourceSt->execute([$blockId]);
        $source = $sourceSt->fetch() ?: [];
        $parts = [
          ['text', 'Как устроен составной урок', 'Без видео', null, null,
            "Здесь может находиться вводное занятие. У каждого пункта списка — собственные описание, видео, файлы и домашнее задание."],
          ['practice', 'Видео и разбор темы', 'Видео', $source['kinescope_id'] ?? null, $source['duration'] ?? null,
            "Видео относится только к этому занятию. Переключение между занятиями не меняет выбранный урок в программе справа."],
          ['text', 'Материалы и полезные ссылки', 'Материалы', null, null,
            "Файлы и ссылки также закрепляются за конкретным занятием внутри составного урока."],
          ['text', 'Закрепление и домашнее задание', 'Домашнее задание', null, null,
            "Последнее занятие показывает, что домашнее задание можно прикрепить к отдельному пункту списка."],
        ];
        $partIns = $pdo->prepare(
          "INSERT INTO lessons(block_id,parent_lesson_id,type,title,meta,kinescope_id,video_source,duration,description,position)
           VALUES(?,?,?,?,?,?,?, ?,?,?)"
        );
        $partIds = [];
        foreach ($parts as $position => $part) {
          [$type,$title,$meta,$kinescope,$duration,$description] = $part;
          $partIns->execute([$blockId,$parentId,$type,$title,$meta,$kinescope,$kinescope ? 'kinescope' : 'none',$duration,$description,$position]);
          $partIds[] = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO materials(lesson_id,name,size,url,kind,position) VALUES(?,?,'Ссылка',?,'link',0)")
            ->execute([$partIds[2], 'Тренажёр по лексике', 'https://wordwall.net/resource/96077712']);

        $pdo->prepare("INSERT INTO homeworks(lesson_id,level,title,deadline,position) VALUES(?,'normal',?,?,0)")
            ->execute([$partIds[3], 'Тестовое ДЗ составного урока', date('Y-m-d 23:59', strtotime('+7 days'))]);
        $homeworkId = (int)$pdo->lastInsertId();
        $pdo->prepare(
          "INSERT INTO questions(homework_id,type,text,options,correct,solution,hint,points,optional,hard,case_sensitive,grade_mode,file_limit,position)
           VALUES(?,'single',?,?,?,?,?,1,0,0,0,'full',1,0)"
        )->execute([
          $homeworkId,
          'Сколько занятий можно объединить внутри одного урока?',
          json_encode(['До 4','До 10','Без ограничений'], JSON_UNESCAPED_UNICODE),
          json_encode(1),
          'В одном составном уроке можно разместить до 10 занятий.',
          'Посмотри на счётчик над списком занятий.',
        ]);
        out('  + Тестовый составной урок: английская Летняя школа, 4 занятия');
      }
    }
    live_content_touch();
  },

  // v130 — постоянная внешняя ссылка как отдельный источник основного видео урока.
  130 => function (PDO $pdo, string $driver) {
    if (!column_exists($pdo, $driver, 'lessons', 'video_url')) {
      $pdo->exec('ALTER TABLE lessons ADD COLUMN video_url VARCHAR(500) NULL');
    }
    live_content_touch();
    out('  + Уроки: постоянная внешняя ссылка на видео');
  },

  // v131 — ДЗ к уроку по заданиям 17, 18 и 24: базовый и средний уровни.
  131 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_september_16_tasks_17_18_24_homework.php';
    $ids = social_september_16_tasks_17_18_24_seed($pdo);
    live_content_touch();
    out('  + Общество, 16 сентября: ДЗ ' . implode(', ', $ids) . '; базовый — 12 вопросов, средний — 4 задания; дедлайн 26.09.2026 23:59 МСК');
  },

  // v132 — ДЗ к уроку «Деятельность»: базовый и средний уровни.
  132 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_september_18_activity_homework.php';
    $ids = social_september_18_activity_seed($pdo);
    live_content_touch();
    out('  + Общество, 18 сентября, «Деятельность»: ДЗ ' . implode(', ', $ids) . '; базовый — 12 вопросов, средний — 2 задания; дедлайн 26.09.2026 23:59 МСК');
  },

  // v133 — экзаменационная практика к эфиру 25 сентября оформлена как ДЗ.
  133 => function (PDO $pdo, string $driver) {
    require_once __DIR__ . '/social_september_25_exam_practice_homework.php';
    $ids = social_september_25_exam_practice_seed($pdo);
    live_content_touch();
    out('  + Общество, эфир 25 сентября, 18:00: ДЗ ' . implode(', ', $ids) . '; базовый — 16 вопросов, средний — 9 заданий; существующий дедлайн сохранён');
  },

];

// ── Прогон ──────────────────────────────────────────────────
$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
out("БД: $driver");

$pdo->exec(ddl("CREATE TABLE IF NOT EXISTS schema_migrations (
  version INT PRIMARY KEY,
  applied_at {TS}
) {ENGINE}", $driver));

$applied = [];
foreach ($pdo->query("SELECT version FROM schema_migrations") as $r) {
  $applied[(int)$r['version']] = true;
}

ksort($MIGRATIONS);
$ran = 0;
foreach ($MIGRATIONS as $v => $fn) {
  if (isset($applied[$v])) continue;
  $fn($pdo, $driver);
  $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$v]);
  out("  ✓ миграция v$v применена");
  $ran++;
}
out($ran ? "Применено миграций: $ran" : "Схема актуальна, новых миграций нет.");

// ── Сид стартовых данных (только при пустых таблицах) ───────
// На проде сид никогда не запускается неявно: его нужно отдельно запросить
// параметром ?seed=1 или CLI-флагом --seed. Это не даёт обычной миграции
// случайно создавать демонстрационные данные.
$seedDisabled = in_array('--no-seed', $argv ?? [], true) || isset($_GET['no-seed']);
$seedExplicit = in_array('--seed', $argv ?? [], true) || isset($_GET['seed']);
$seed = !$seedDisabled && (!is_prod() || $seedExplicit);
if ($seed) {
  $isMysql = ($driver === 'mysql'); // фактический прод = реально подключились к MySQL

  // Тестовый ученик с простым паролем — только для локальной разработки.
  if (!$isMysql && (int)$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'] === 0) {
    $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role) VALUES (?,?,?,?,?)")
        ->execute(['Брат (тест)', 'test@100-points.ru', '+79990000000', password_hash('1234', PASSWORD_DEFAULT), 'student']);
    out("  + тестовый ученик (dev): test@100-points.ru / +79990000000  пароль: 1234");
  }

  // Стартовый админ. На проде НЕ зашиваем известный пароль — генерируем случайный
  // и печатаем один раз. Локально — удобный фиксированный admin_irg / Qwerty123.
  if ((int)$pdo->query("SELECT COUNT(*) c FROM admins")->fetch()['c'] === 0) {
    if ($isMysql) {
      $pw = bin2hex(random_bytes(8)); // 16 hex-символов
      $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?,?)")
          ->execute(['admin_irg', password_hash($pw, PASSWORD_DEFAULT)]);
      out("  + админ: admin_irg / $pw");
      out("    ↑ ЗАПИШИ пароль — показан ОДИН раз. Войди и смени его в админке.");
    } else {
      $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?,?)")
          ->execute(['admin_irg', password_hash('Qwerty123', PASSWORD_DEFAULT)]);
      out("  + админ (dev): admin_irg / Qwerty123");
    }
  }
}

// ── Сид каталога контента (Этап 2): предметы, курсы, месяцы, блоки, уроки ──
// Заполняем только при пустой таблице courses (идемпотентно). На проде тоже
// наполняем — это тестовый каталог, который потом редактируется через админку.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM courses")->fetch()['c'] === 0) {
  seed_catalog($pdo);
  out("  + тестовый каталог: предметы, курсы, месяцы, блоки, уроки, материалы, тайм-коды");
}

// Сид тарифов (Этап 3) + активная подписка существующим ученикам, чтобы они не потеряли доступ.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM plans")->fetch()['c'] === 0) {
  seed_plans($pdo);
  out("  + тарифы (Базовый/Стандарт/Премиум) + активная подписка существующим ученикам");
}

// Свежая БД: после сида users/courses зачисляем учеников на все курсы (чтобы dev-вход работал).
if ($seed) {
  $added = enroll_all_existing($pdo);
  if ($added) out("  + зачисления: $added (ученики × курсы)");
}

// Демо-ДЗ (Этап 4): обычное + повышенное на первом уроке.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM homeworks")->fetch()['c'] === 0) {
  if (seed_homework($pdo)) out("  + демо-ДЗ (обычное + повышенное) на уроке 1");
}

// «Эксперты»-проверяющие (Этап 5): 100 профилей для ощущения живой проверки.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM experts")->fetch()['c'] === 0) {
  $n = seed_experts($pdo);
  out("  + эксперты-проверяющие: $n профилей");
}

// Демо-пробники (Этап 6): по предметам и месяцам.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM mock_exams")->fetch()['c'] === 0) {
  if (seed_mocks($pdo)) out("  + демо-пробники по предметам и месяцам");
}

// Демо-банк заданий (Этап 7): темы, подтемы, вопросы, источник+вариант.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM topics")->fetch()['c'] === 0) {
  if (seed_bank($pdo)) out("  + демо-банк заданий (темы, подтемы, вопросы)");
}

// Демо-задачи ИИ-тренажёра (Этап 8, режим «Решение задач»).
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM trainer_tasks")->fetch()['c'] === 0) {
  if (seed_trainer($pdo)) out("  + демо-задачи ИИ-тренажёра");
}

// Демо-события расписания (Этап 11) — на текущую неделю, к первому курсу.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM events")->fetch()['c'] === 0) {
  $course = $pdo->query("SELECT id FROM courses ORDER BY id LIMIT 1")->fetch();
  if ($course) {
    $cid = (int)$course['id'];
    // [смещение дней от сегодня, время, тип, заголовок]
    $rows = [
      [0, '18:00', 'webinar', 'Вебинар: параметры в задании 18'],
      [0, '19:30', 'practice', 'Практика: планиметрия'],
      [1, '17:00', 'webinar', 'Вебинар: производная и её применение'],
      [2, '18:00', 'practice', 'Практика: тригонометрические уравнения'],
      [4, '17:00', 'webinar', 'Разбор пробного ЕГЭ'],
    ];
    $ei = $pdo->prepare("INSERT INTO events (course_id, type, title, starts_at, duration, link, position) VALUES (?,?,?,?,?,?,?)");
    foreach ($rows as $i => $r) {
      $date = date('Y-m-d', strtotime("+{$r[0]} days"));
      $ei->execute([$cid, $r[2], $r[3], "$date {$r[1]}", 90, 'https://kinescope.io/live', $i]);
    }
    out('  + демо-расписание (события недели)');
  }
}

// Демо-товары магазина (Этап 10).
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM shop_items")->fetch()['c'] === 0) {
  // [name, description, category, price, once_only, badge]
  $items = [
    ['Тёмная тема', 'Тёмное оформление кабинета', 'theme', 300, 1, null],
    ['Неоновая тема', 'Яркое неоновое оформление', 'theme', 600, 1, null],
    ['Тема «Космос»', 'Звёздное оформление', 'theme', 500, 1, null],
    ['Мужская тема', 'Секретное оформление для парней. Что внутри — узнаешь после покупки 😏', 'theme', 500, 1, 'СЮРПРИЗ'],
    ['Женская тема', 'Секретное оформление для девушек. Что внутри — узнаешь после покупки 😉', 'theme', 500, 1, 'СЮРПРИЗ'],
    ['Рамка для аватара', 'Золотая академическая рамка с кристаллом', 'theme', 350, 1, null],
    ['Цвет ника', 'Палитра цветов для имени в кабинете', 'theme', 250, 1, null],
    ['Заморозка стрика', 'Серия не сгорит за пропуск дня', 'privilege', 150, 0, null],
    ['Продление дедлайна +24ч', 'Лишний день на домашку', 'privilege', 200, 0, null],
    ['+1 жизнь', 'Мгновенно восстанавливает одну жизнь', 'privilege', 120, 0, null],
    ['Бустер ×2 кристалла', 'Удваивает игровые начисления на 24 часа', 'privilege', 250, 0, null],
    ['VIP-статус навсегда', 'VIP-тема · +1 день к ДЗ · 7 жизней · вход +10 кристаллов', 'privilege', 3000, 1, 'TOP'],
    ['Секретный конспект', 'Шпаргалка по сложной теме', 'content', 300, 0, null],
    ['Запись закрытого вебинара', 'Лучшие вебинары прошлых лет', 'content', 350, 0, null],
    ['Доп. пробник', 'Ещё один пробный экзамен', 'content', 250, 0, null],
    ['Пробник с наставником', 'Проходишь вместе с куратором', 'content', 800, 0, null],
    ['Telegram Premium', 'Подписка на 1 месяц', 'reward', 2800, 0, null],
    ['Steam — 100 ₽', 'Пополнение кошелька Steam', 'reward', 2000, 0, null],
    ['Игровая валюта', 'Robux / V-Bucks / донат на выбор', 'reward', 2500, 0, null],
    ['Подписка на музыку', 'Яндекс Плюс / VK — 1 месяц', 'reward', 2200, 0, null],
    ['Пополнение телефона — 100 ₽', 'На любой номер', 'reward', 2000, 0, null],
    ['Сертификат 300 ₽', 'Ozon / Wildberries / Steam', 'reward', 5500, 0, 'TOP'],
  ];
  $si = $pdo->prepare("INSERT INTO shop_items (name, description, category, price, once_only, badge, active, position) VALUES (?,?,?,?,?,?,1,?)");
  foreach ($items as $i => $it) $si->execute([$it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $i]);
  out('  + товары магазина');
}

// Демо-материалы библиотеки (Этап 15).
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM library_items")->fetch()['c'] === 0) {
  $math = $pdo->query("SELECT id FROM subjects WHERE slug = 'math' LIMIT 1")->fetch();
  $sid = $math ? (int)$math['id'] : null;
  $rows = [
    [$sid, 'Конспекты', 'Конспект: производная и её применение', 'https://kinescope.io/', 'PDF · 1.2 МБ', 'doc'],
    [$sid, 'Шпаргалки', 'Шпаргалка: формулы сокращённого умножения', 'https://kinescope.io/', 'PDF · 0.4 МБ', 'doc'],
    [$sid, 'Демоверсии', 'Демоверсия ЕГЭ по профильной математике', 'https://kinescope.io/', 'PDF · 2.1 МБ', 'doc'],
    [null, 'Кодификаторы', 'Кодификатор ЕГЭ (все предметы)', 'https://kinescope.io/', 'PDF · 0.8 МБ', 'doc'],
    [null, 'Карточки', 'Карточки: даты по истории', 'https://kinescope.io/', 'Ссылка', 'link'],
  ];
  $li = $pdo->prepare("INSERT INTO library_items (subject_id, category, title, url, size, kind, position) VALUES (?,?,?,?,?,?,?)");
  foreach ($rows as $i => $r) $li->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $i]);
  out('  + демо-материалы библиотеки');
}

// Каталог достижений (Этап 9). icon — имя для фронта.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM achievements")->fetch()['c'] === 0) {
  $ach = [
    ['first_homework', 'Первый шаг', 'Сдай первое домашнее задание', 'flag', 50, 1],
    ['homeworks_10', 'Труженик', 'Сдай 10 домашних заданий', 'pencil', 100, 10],
    ['perfect_test', 'Стобалльник', 'Реши тест на 100%', 'target', 50, 1],
    ['mock_done', 'Боевое крещение', 'Пройди первый пробник', 'clipboard', 50, 1],
    ['mocks_5', 'Марафонец', 'Пройди 5 пробников', 'flame', 100, 5],
    ['streak_7', 'Неделя в строю', 'Занимайся 7 дней подряд', 'sunrise', 100, 7],
    ['crystals_500', 'Богатей', 'Накопи 500 кристаллов', 'gem', 150, 500],
  ];
  $ai = $pdo->prepare("INSERT INTO achievements (code, title, description, icon, reward, target, position) VALUES (?,?,?,?,?,?,?)");
  foreach ($ach as $i => $a) $ai->execute([$a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $i]);
  out('  + каталог достижений');
}

// Демо-база знаний техподдержки (Этап 8/12): категории + Q&A.
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM help_categories")->fetch()['c'] === 0) {
  $cats = [
    ['Домашние задания', [
      ['Как долго проверяют домашнее задание?', 'Тестовая часть проверяется автоматически сразу после сдачи. Развёрнутые ответы проверяет эксперт обычно в течение 1–2 дней — статус «на проверке» сменится на оценку с комментарием.'],
      ['Что делать, если задание проверили неправильно?', 'Если ты не согласен с оценкой развёрнутого ответа, напиши в поддержку или своему куратору — работу пересмотрят. Тестовую часть можно перерешать.'],
      ['Можно ли пересдать домашнее задание?', 'Да, можно пройти ДЗ заново — последняя попытка перезаписывает предыдущую. Развёрнутые ответы при этом снова уйдут на проверку.'],
    ]],
    ['Пробники', [
      ['Чем пробник отличается от домашки?', 'Пробник крупнее и без жёсткого дедлайна — это имитация экзамена по предмету за месяц. Проверяется так же: тесты автоматически, развёрнутое — экспертом.'],
      ['Где посмотреть результаты пробника?', 'После сдачи откроется разбор: за каждое задание видно верно/неверно, правильный ответ и решение, а развёрнутые — после проверки экспертом.'],
    ]],
    ['Подписка и доступ', [
      ['Как открыть доступ к курсу?', 'Доступ к курсам выдаёт администратор (по подписке или вручную). Если курс закрыт — напиши в поддержку, чтобы тебе открыли нужный курс.'],
      ['Что даёт тариф «Стандарт»?', 'Тариф «Стандарт» и выше открывает повышенный уровень домашних заданий и расширенные возможности. Сам доступ к курсам выдаётся отдельно.'],
      ['Почему урок показывает «замок»?', 'Урок закрыт, если у тебя нет доступа к этому курсу. Повышенные ДЗ дополнительно требуют подписку «Стандарт». Доступ открывает администратор.'],
    ]],
    ['Технические проблемы', [
      ['Сайт не открывается, что делать?', 'Попробуй обновить страницу (Ctrl/Cmd+R), очистить кэш или зайти из другого браузера. Если у тебя включён VPN — отключи его, иногда он мешает. Если не помогло — напиши в поддержку.'],
      ['Не сохраняются ответы в домашке.', 'Ответы сохраняются автоматически каждые пару секунд. Если что-то пропало — обнови страницу, черновик восстановится. Проверь, что ты вошёл в свой аккаунт.'],
    ]],
  ];
  $ci = $pdo->prepare("INSERT INTO help_categories (name, position) VALUES (?,?)");
  $qi = $pdo->prepare("INSERT INTO help_qa (category_id, question, answer, position) VALUES (?,?,?,?)");
  foreach ($cats as $cpos => $c) {
    $ci->execute([$c[0], $cpos]);
    $cid = (int)$pdo->lastInsertId();
    foreach ($c[1] as $qpos => $qa) $qi->execute([$cid, $qa[0], $qa[1], $qpos]);
  }
  out('  + база знаний техподдержки (категории + вопросы-ответы)');
}

// Демо-темы для ИИ-генерации (режим «Вопрос-ответ»).
if ($seed && (int)$pdo->query("SELECT COUNT(*) c FROM trainer_qa_topics")->fetch()['c'] === 0) {
  $math = $pdo->query("SELECT id FROM subjects WHERE slug = 'math' LIMIT 1")->fetch();
  if ($math) {
    $sid = (int)$math['id'];
    $rows = [
      [1, 'Проценты и доли', 'Простые задачи на проценты от числа, скидки, доли. Числовой ответ.', 'z-ai/glm-5.3-flash'],
      [2, 'Линейные уравнения', 'Уравнения вида ax+b=c, простые текстовые задачи на уравнения.', 'z-ai/glm-5.3-flash'],
      [3, 'Производные', 'Нахождение производных многочленов, простых функций.', 'z-ai/glm-5.3-flash'],
    ];
    $qi = $pdo->prepare("INSERT INTO trainer_qa_topics (subject_id, level, name, examples, model, position) VALUES (?,?,?,?,?,?)");
    foreach ($rows as $i => $r) $qi->execute([$sid, $r[0], $r[1], $r[2], $r[3], $i]);
    out("  + демо-темы для ИИ-генерации вопросов");
  }
}

out("Готово.");

// Демо-задачи тренажёра по математике (разные уровни, с эталонным ответом для ИИ).
function seed_trainer(PDO $pdo): bool {
  $math = $pdo->query("SELECT id FROM subjects WHERE slug = 'math' LIMIT 1")->fetch();
  if (!$math) return false;
  $sid = (int)$math['id'];
  // [level, text, answer, solution, model]
  $tasks = [
    [1, 'Решите уравнение: 3x − 7 = 11.', 'x = 6', '3x = 18, x = 6.', 'z-ai/glm-5.3-flash'],
    [1, 'Найдите 25% от числа 80.', '20', '80 · 0,25 = 20.', 'z-ai/glm-5.3-flash'],
    [2, 'В треугольнике два угла равны 50° и 60°. Найдите третий угол.', '70°', 'Сумма углов 180°, 180 − 50 − 60 = 70°.', 'z-ai/glm-5.3-flash'],
    [3, 'Найдите производную функции f(x) = x³ − 2x.', "f'(x) = 3x² − 2", 'Производная x³ = 3x², производная 2x = 2.', 'z-ai/glm-5.3-flash'],
    [4, 'Решите неравенство: x² − 5x + 6 < 0.', '2 < x < 3', 'Корни 2 и 3, парабола ветвями вверх, между корнями отрицательна.', 'z-ai/glm-5.3-flash'],
  ];
  $ti = $pdo->prepare("INSERT INTO trainer_tasks (subject_id, level, text, answer, solution, model, position) VALUES (?,?,?,?,?,?,?)");
  foreach ($tasks as $i => $t) $ti->execute([$sid, $t[0], $t[1], $t[2], $t[3], $t[4], $i]);
  return true;
}

// Демо-банк по математике: номера заданий → подтемы → вопросы (+ % ошибок), источник ФИПИ + вариант.
function seed_bank(PDO $pdo): bool {
  $math = $pdo->query("SELECT id FROM subjects WHERE slug = 'math' LIMIT 1")->fetch();
  if (!$math) return false;
  $sid = (int)$math['id'];
  $J = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);

  $ti = $pdo->prepare("INSERT INTO topics (subject_id, number, name, part, error_rate, position) VALUES (?,?,?,?,?,?)");
  $sti = $pdo->prepare("INSERT INTO subtopics (topic_id, name, position) VALUES (?,?,?)");
  $qi = $pdo->prepare("INSERT INTO questions (subtopic_id, difficulty, type, text, options, correct, solution, points, optional, hard, position) VALUES (?,?,?,?,?,?,?,?,0,0,?)");

  // [number, name, error_rate, part, [ [subtopic, [вопросы...]] ]]
  // вопрос = [type, text, options|null, correct|null, solution, points, difficulty]
  $topics = [
    [1, 'Фигуры на плоскости', 18, 'first', [
      ['Треугольники', [
        ['single', 'Сумма углов треугольника равна:', ['90°', '180°', '360°'], 1, 'Сумма углов треугольника = 180°.', 1, 1],
        ['text', 'Гипотенуза прямоугольного треугольника с катетами 3 и 4. Ответ — число.', null, ['5'], 'По теореме Пифагора: √(9+16)=5.', 1, 2],
      ]],
      ['Четырёхугольники', [
        ['single', 'Сумма углов выпуклого четырёхугольника:', ['180°', '360°', '540°'], 1, 'Сумма углов четырёхугольника = 360°.', 1, 1],
      ]],
    ]],
    [4, 'Вероятность (базовая)', 5, 'first', [
      ['Классическая вероятность', [
        ['text', 'Монету бросают 1 раз. Вероятность орла? Десятичная дробь.', null, ['0,5', '0.5'], 'Один из двух исходов: 0,5.', 1, 1],
        ['single', 'В урне 3 белых и 7 чёрных. Вероятность белого:', ['0,3', '0,7', '0,5'], 0, '3 из 10 = 0,3.', 1, 2],
      ]],
    ]],
    [5, 'Вероятность (формулы)', 35, 'first', [
      ['Сложные события', [
        ['text', 'P(A)=0,4, P(B)=0,5, события независимы. P(A и B)? Десятичная.', null, ['0,2', '0.2'], '0,4·0,5=0,2.', 2, 3],
      ]],
    ]],
    [7, 'Корни, логарифмы, преобразования', 16, 'first', [
      ['Логарифмы', [
        ['text', 'Вычислите log₂ 8. Целое число.', null, ['3'], '2³=8, значит log₂8=3.', 1, 2],
      ]],
    ]],
  ];

  foreach ($topics as $tpos => $t) {
    [$num, $tname, $err, $part, $subs] = $t;
    $ti->execute([$sid, $num, $tname, $part, $err, $tpos]);
    $topicId = (int)$pdo->lastInsertId();
    foreach ($subs as $spos => $sub) {
      [$sname, $qs] = $sub;
      $sti->execute([$topicId, $sname, $spos]);
      $subtopicId = (int)$pdo->lastInsertId();
      foreach ($qs as $qpos => $q) {
        $qi->execute([$subtopicId, $q[6], $q[0], $q[1], $J($q[2]), $J($q[3]), $q[4], $q[5], $qpos]);
      }
    }
  }

  // Источник + демо-вариант для режима «Пробник» (берём пару уже созданных вопросов банка).
  $pdo->prepare("INSERT INTO bank_sources (subject_id, name, position) VALUES (?,?,0)")->execute([$sid, 'ФИПИ']);
  $srcId = (int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO bank_variants (source_id, subject_id, name, position) VALUES (?,?,?,0)")->execute([$srcId, $sid, 'Демоверсия 2026 (вариант 1)']);
  $varId = (int)$pdo->lastInsertId();
  $vqi = $pdo->prepare("INSERT INTO variant_questions (variant_id, question_id, position) VALUES (?,?,?)");
  $bankQs = $pdo->query("SELECT id FROM questions WHERE subtopic_id IS NOT NULL ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($bankQs as $i => $qid) $vqi->execute([$varId, (int)$qid, $i]);

  return true;
}

// Демо-пробники: один предмет может иметь несколько пробников в один месяц («Март», «Март 2», «Март Про»).
function seed_mocks(PDO $pdo): bool {
  // Берём первый курс каждого предмета (по slug) — к нему привяжем пробники.
  $courseBySlug = [];
  $rows = $pdo->query("SELECT c.id AS cid, s.slug FROM courses c JOIN subjects s ON s.id = c.subject_id ORDER BY c.position, c.id");
  foreach ($rows as $r) if (!isset($courseBySlug[$r['slug']])) $courseBySlug[$r['slug']] = (int)$r['cid'];
  if (!$courseBySlug) return false;

  $mi = $pdo->prepare("INSERT INTO mock_exams (course_id, month, title, position) VALUES (?,?,?,?)");
  $qi = $pdo->prepare("INSERT INTO questions (mock_id, type, text, options, correct, solution, points, optional, hard, position) VALUES (?,?,?,?,?,?,?,?,?,?)");
  $J = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);

  // month — обычный месяц (метка периода, редактируется в админке; можно поставить и «2026» для архива).
  // Приставка «2»/«ПРО» — в НАЗВАНИИ пробника, не в месяце.
  // [slug, month, title, вопросы[]]; вопрос = [type, text, options|null, correct|null, solution, points]
  $mocks = [
    ['math', 'Март', 'Пробный ЕГЭ по математике', [
      ['single', 'Чему равен sin 30°?', ['0', '0,5', '1'], 1, 'sin 30° = 0,5.', 2],
      ['text', 'Решите: 2x = 10. x = ?', null, ['5'], 'x = 5.', 2],
      ['textarea', 'Опишите ход решения задачи на движение (своими словами).', null, null, 'Скорость, время, расстояние; составить уравнение.', 4],
    ]],
    ['math', 'Март', 'Пробный ЕГЭ по математике 2', [
      ['single', 'Производная x² равна:', ['x', '2x', 'x²/2'], 1, 'Производная x² = 2x.', 2],
      ['multi', 'Какие числа простые?', ['2', '4', '7', '9'], [0, 2], 'Простые: 2 и 7.', 3],
    ]],
    ['math', 'Март', 'Пробник ПРО по математике', [
      ['text', 'Сколько корней у уравнения x²+1=0 в действительных числах?', null, ['0', 'ноль', 'нет'], 'Дискриминант < 0 — корней нет.', 4],
      ['textarea', 'Докажите, что сумма углов треугольника равна 180°.', null, null, 'Через параллельные прямые и накрест лежащие углы.', 6],
    ]],
    ['rus', 'Март', 'Пробный ЕГЭ по русскому языку', [
      ['text', 'Вставьте букву: пр..красный (е/и). Одно слово.', null, ['прекрасный'], 'Приставка пре- в значении «очень».', 2],
      ['textarea', 'Напишите мини-сочинение (3-4 предложения) о роли книги.', null, null, 'Свободный развёрнутый ответ — проверяет эксперт.', 6],
    ]],
    ['history', 'Апрель', 'Пробный ЕГЭ по истории', [
      ['single', 'В каком году крещение Руси?', ['988', '1147', '1242'], 0, 'Крещение Руси — 988 год.', 2],
      ['multi', 'Кто из них — князья Древней Руси?', ['Олег', 'Пётр I', 'Игорь', 'Екатерина II'], [0, 2], 'Олег и Игорь — древнерусские князья.', 3],
    ]],
  ];

  foreach ($mocks as $mpos => $m) {
    [$slug, $month, $title, $qs] = $m;
    if (!isset($courseBySlug[$slug])) continue;
    $mi->execute([$courseBySlug[$slug], $month, $title, $mpos]);
    $mockId = (int)$pdo->lastInsertId();
    foreach ($qs as $i => $q) {
      $qi->execute([$mockId, $q[0], $q[1], $J($q[2]), $J($q[3]), $q[4], $q[5], 0, 0, $i]);
    }
  }
  return true;
}

// 100 «экспертов»: ФИО + крутой вуз РФ + балл ЕГЭ 95+. Детерминированно (без random), чтобы прогон был воспроизводим.
function seed_experts(PDO $pdo): int {
  $surM = ['Иванов','Смирнов','Кузнецов','Соколов','Лебедев','Козлов','Новиков','Морозов','Волков','Соловьёв','Васильев','Зайцев','Павлов','Семёнов','Голубев','Виноградов','Богданов','Воробьёв','Фёдоров','Михайлов','Беляев','Тарасов','Орлов','Киселёв','Макаров'];
  $nameM = ['Александр','Дмитрий','Максим','Сергей','Андрей','Алексей','Артём','Илья','Кирилл','Михаил','Никита','Егор','Иван','Роман','Тимофей'];
  $nameF = ['Анна','Мария','Елена','Дарья','Софья','Виктория','Полина','Екатерина','Ксения','Алиса','Арина','Вероника','Юлия','Ольга','Татьяна'];
  $uni = ['МГУ им. М. В. Ломоносова','СПбГУ','МФТИ','НИУ ВШЭ','МГТУ им. Н. Э. Баумана','Университет ИТМО','МГИМО','НИЯУ МИФИ','РАНХиГС','Новосибирский государственный университет','Сеченовский университет','Финансовый университет','РЭУ им. Г. В. Плеханова','СПбПУ Петра Великого','Казанский федеральный университет'];
  $ins = $pdo->prepare("INSERT INTO experts (last_name, first_name, university, ege_score) VALUES (?,?,?,?)");
  for ($i = 0; $i < 100; $i++) {
    $female = ($i % 3 === 0); // примерно треть — женские профили
    $sur = $surM[$i % count($surM)];
    if ($female) $sur .= 'а'; // феминитив фамилии
    $name = $female ? $nameF[($i * 5) % count($nameF)] : $nameM[($i * 7) % count($nameM)];
    $u = $uni[($i * 3) % count($uni)];
    $score = 95 + ($i % 6); // 95..100
    $ins->execute([$sur, $name, $u, $score]);
  }
  return 100;
}

// Демо-ДЗ на первом уроке: одно обычное (с авто- и развёрнутым вопросом) и одно повышенное.
function seed_homework(PDO $pdo): bool {
  $lesson = $pdo->query("SELECT id FROM lessons ORDER BY id LIMIT 1")->fetch();
  if (!$lesson) return false;
  $lid = (int)$lesson['id'];

  $hi = $pdo->prepare("INSERT INTO homeworks (lesson_id, level, title, deadline, position) VALUES (?,?,?,?,?)");
  $qi = $pdo->prepare("INSERT INTO questions (homework_id, type, text, options, correct, solution, points, optional, hard, position) VALUES (?,?,?,?,?,?,?,?,?,?)");
  $J = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);

  // Обычное ДЗ.
  $hi->execute([$lid, 'normal', 'Домашняя работа по уроку', 'до 1 ноября', 0]);
  $hwNormal = (int)$pdo->lastInsertId();
  $normalQs = [
    ['single', 'Орган слуха человека — это:', ['ухо','нос','глаз'], 0, 'Орган слуха — ухо: воспринимает звуковые колебания.', 4, 0, 0],
    ['multi', 'Какие из перечисленного — органы чувств? Выберите все.', ['глаз','ухо','кожа','печень'], [0,1,2], 'Органы чувств: глаз, ухо, кожа. Печень — железа.', 6, 0, 0],
    ['text', 'Как называется прозрачная передняя оболочка глаза? Одно слово.', null, ['роговица'], 'Прозрачная передняя оболочка глаза — роговица.', 4, 0, 0],
    ['textarea', 'Опишите путь звуковой волны от ушной раковины до слухового нерва.', null, null, 'Ушная раковина → слуховой проход → барабанная перепонка → молоточек, наковальня, стремечко → улитка → слуховой нерв.', 10, 0, 0],
    ['single', 'Сколько слуховых косточек в среднем ухе?', ['две','три','четыре'], 1, 'Три: молоточек, наковальня, стремечко.', 4, 1, 0],
  ];
  foreach ($normalQs as $i => $q) {
    $qi->execute([$hwNormal, $q[0], $q[1], $J($q[2]), $J($q[3]), $q[4], $q[5], $q[6], $q[7], $i]);
  }

  // Повышенное ДЗ (доступно с подпиской Стандарт+).
  $hi->execute([$lid, 'hard', 'Повышенная сложность', 'до 3 ноября', 1]);
  $hwHard = (int)$pdo->lastInsertId();
  $hardQs = [
    ['multi', 'Отметьте структуры внутреннего уха:', ['улитка','преддверие','роговица','полукружные каналы'], [0,1,3], 'Внутреннее ухо: улитка, преддверие, полукружные каналы. Роговица — глаз.', 6, 0, 1],
    ['text', 'Какой витамин важен для зрения? Одно слово/буква.', null, ['A','витамин A','ретинол'], 'За зрение отвечает витамин A (ретинол).', 4, 0, 1],
    ['textarea', 'В 1560 г. произошло падение Избранной рады. Укажите любые три причины.', null, null, 'Расхождения царя с радой во взглядах на власть; смерть первой жены и подозрения в отравлении; стремление к единоличному правлению.', 10, 0, 1],
  ];
  foreach ($hardQs as $i => $q) {
    $qi->execute([$hwHard, $q[0], $q[1], $J($q[2]), $J($q[3]), $q[4], $q[5], $q[6], $q[7], $i]);
  }
  return true;
}

// Есть ли колонка в таблице (для идемпотентных ALTER). Портируемо SQLite/MySQL.
function column_exists(PDO $pdo, string $driver, string $table, string $col): bool {
  if ($driver === 'sqlite') {
    foreach ($pdo->query("PRAGMA table_info($table)") as $r) if ($r['name'] === $col) return true;
    return false;
  }
  $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
  $st->execute([$table, $col]);
  return (bool)$st->fetch();
}

// Зачисляет всех учеников на все курсы, пропуская уже существующие пары. Идемпотентно.
// Возвращает число добавленных строк.
function enroll_all_existing(PDO $pdo): int {
  $users   = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $courses = $pdo->query("SELECT id FROM courses")->fetchAll(PDO::FETCH_COLUMN);
  if (!$users || !$courses) return 0;
  $have = [];
  foreach ($pdo->query("SELECT user_id, course_id FROM enrollments") as $r) {
    $have[$r['user_id'] . '-' . $r['course_id']] = true;
  }
  $ins = $pdo->prepare("INSERT INTO enrollments (user_id, course_id, source) VALUES (?,?, 'subscription')");
  $n = 0;
  foreach ($users as $u) foreach ($courses as $c) {
    if (isset($have[$u . '-' . $c])) continue;
    $ins->execute([(int)$u, (int)$c]);
    $n++;
  }
  return $n;
}

// Тарифы и стартовая подписка. Фичи — JSON-массив строк.
function seed_plans(PDO $pdo) {
  // [name, tagline, price, badge, features[], hard_access(повышенный уровень ДЗ)]
  $plans = [
    ['Базовый', 'Старт подготовки', 9999, null,
      ['Доступ ко всем урокам', 'Тесты с автопроверкой', 'Банк заданий', 'Библиотека материалов'], 0],
    ['Стандарт', 'Оптимальный выбор', 13999, null,
      ['Всё из «Базового»', 'Личный куратор', 'Проверка домашних заданий', 'Проверка второй части наставником', 'Пробники с разбором', 'ИИ-чат и ИИ-тренажёр'], 1],
    ['Премиум', 'Максимальный результат', 17999, 'top',
      ['Всё из «Стандарта»', 'Онлайн-наставник 24/7', 'ИИ-тренажёр PRO', 'Личные консультации с психологом · 2 раза в месяц', 'Созвон с куратором по каждому предмету · 1 раз в месяц', 'Приоритетная поддержка'], 1],
  ];
  $pi = $pdo->prepare("INSERT INTO plans (name, tagline, price, features, badge, position, hard_access) VALUES (?,?,?,?,?,?,?)");
  $planId = [];
  foreach ($plans as $i => $p) {
    $pi->execute([$p[0], $p[1], $p[2], json_encode($p[4], JSON_UNESCAPED_UNICODE), $p[3], $i, $p[5]]);
    $planId[$p[0]] = (int)$pdo->lastInsertId();
  }
  // Всем существующим ученикам — активная «Стандарт» до 2028-06-30 (чтобы текущие доступы сохранились).
  if ((int)$pdo->query("SELECT COUNT(*) c FROM subscriptions")->fetch()['c'] === 0) {
    $std = $planId['Стандарт'];
    $today = date('Y-m-d');
    $si = $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, status, started_at, ends_at) VALUES (?,?,?,?,?)");
    foreach ($pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
      $si->execute([(int)$uid, $std, 'active', $today, '2028-06-30']);
    }
  }
}

// Наполнение каталога демо-данными. Структура задана вложенным массивом
// (курс → месяцы → блоки → уроки → материалы/тайм-коды) и разворачивается вставками.
function seed_catalog(PDO $pdo) {
  // Предметы: name, slug, цвет, иконка (ключи совпадают с фронтовыми ICONS/PATTERNS).
  $subjects = [
    ['Профильная математика', 'math',      '#3f9bff', 'pi'],
    ['Русский язык',          'rus',       '#7c4dff', 'feather'],
    ['История',               'history',   '#e0584f', 'landmark'],
    ['Обществознание',        'social',    '#0ea5a5', 'social'],
    ['Английский язык',       'english',   '#e0518a', 'english'],
    ['Биология',              'bio',       '#41c45e', 'leaf'],
    ['Литература',            'literature','#9b5cc7', 'book'],
    ['Поступление',           'admission', '#ff7a00', 'cap'],
  ];
  $sid = [];
  $si = $pdo->prepare("INSERT INTO subjects (name, slug, color, icon, position) VALUES (?,?,?,?,?)");
  foreach ($subjects as $i => $s) { $si->execute([$s[0], $s[1], $s[2], $s[3], $i]); $sid[$s[1]] = (int)$pdo->lastInsertId(); }

  // Демонстрационные материалы и тайм-коды для теоретического урока.
  $demoMaterials = [
    ['Конспект урока', '420 КБ', '', 'pdf'],
    ['Скрипт разбора', '180 КБ', '', 'doc'],
  ];
  $demoTimecodes = [
    ['00:00', 'Вступление'],
    ['03:40', 'Разбор теории'],
    ['11:20', 'Примеры задач'],
    ['21:05', 'Итоги и что дальше'],
  ];

  // Готовые «кирпичики» уроков (без материалов — добавим первым теориям программно).
  $theory  = fn($title, $meta, $dur = null) => ['theory',   $title, $meta, $dur];
  $practice= fn($title, $air, $hint)        => ['practice', $title, 'Вебинар-практикум', null, $air, $hint];

  // Универсальная программа для «каркасных» курсов (1 месяц, 2 блока).
  $basicProgram = fn($t1, $t2) => [
    ['Сентябрь', [
      [1, $t1, [ $theory("$t1: теория", 'Видео и тест'), $theory('Разбор примеров', 'Видео и тест', '32 мин'),
                 $practice('Практикум по теме', 'Эфир 12 сентября, 18:00', 'Нужно решить 2 урока') ]],
      [2, $t2, [ $theory("$t2: теория", 'Видео и тест', '40 мин'), $theory('Типовые задания', 'Видео и тест', '27 мин') ]],
    ]],
  ];

  // КУРСЫ: [subject_slug, title, type, color, price, program]
  // program: [ [monthName, [ [blockN, blockTitle, [lessons...] ], ... ] ], ... ]
  $courses = [
    // — Наполненный годовой курс (Основные): 2 месяца, по 2 блока.
    ['math', 'Годовой курс ЕГЭ по математике', 'Основные', '#3f9bff', 3390, [
      ['Сентябрь', [
        [1, 'Прямоугольник, ромб, квадрат', [
          $theory('Ромб и квадрат', 'Видео и тест'),
          $theory('Центральная симметрия', 'Видео и тест', '38 мин'),
          $practice('Ромб. Квадрат. Симметрия', 'Эфир 5 сентября, 15:00', 'Нужно решить 2 урока'),
        ]],
        [2, 'Площадь многоугольника', [
          $theory('Площадь прямоугольника и квадрата', 'Видео и тест', '24 мин'),
          $theory('Площадь параллелограмма', 'Видео и тест', '31 мин'),
          $practice('Площади: разбор задач', 'Эфир 12 сентября, 15:00', 'Нужно решить 2 урока'),
        ]],
      ]],
      ['Октябрь', [
        [3, 'Площади фигур', [
          $theory('Площадь треугольника', 'Видео и тест', '29 мин'),
          $theory('Площадь трапеции', 'Видео и тест', '26 мин'),
          $practice('Площади фигур: практикум', 'Эфир 3 октября, 15:00', 'Нужно решить 2 урока'),
        ]],
        [4, 'Окружность и круг', [
          $theory('Длина окружности', 'Видео и тест', '22 мин'),
          $theory('Площадь круга и сектора', 'Видео и тест', '34 мин'),
        ]],
      ]],
    ]],

    // — Наполненный вводный курс (Вводные).
    ['history', 'Вводный курс по истории', 'Вводные', '#e0584f', 0, [
      ['Сентябрь', [
        [1, 'Древняя Русь', [
          $theory('Восточные славяне', 'Видео и тест'),
          $theory('Образование государства', 'Видео и тест', '36 мин'),
          $practice('Древняя Русь: разбор', 'Эфир 8 сентября, 17:00', 'Нужно решить 2 урока'),
        ]],
        [2, 'Политическая раздробленность', [
          $theory('Причины раздробленности', 'Видео и тест', '28 мин'),
          $theory('Крупнейшие княжества', 'Видео и тест', '33 мин'),
        ]],
      ]],
    ]],

    // — Каркасные курсы для остальных типов/предметов (по 1 месяцу, 2 блока).
    ['rus',       'Вводный курс по русскому языку',   'Вводные',        '#7c4dff', 0,    $basicProgram('Орфография', 'Морфология')],
    ['bio',       'Вводный курс по биологии',          'Вводные',        '#41c45e', 0,    $basicProgram('Клетка', 'Ткани и органы')],
    ['social',    'Вводный курс по обществознанию',    'Вводные',        '#0ea5a5', 0,    $basicProgram('Человек и общество', 'Экономика')],
    ['english',   'Вводный курс по английскому языку', 'Вводные',        '#e0518a', 0,    $basicProgram('Времена группы Present', 'Лексика')],
    ['literature','Вводный курс по литературе',         'Вводные',        '#9b5cc7', 0,    $basicProgram('Литературные роды и жанры', 'Анализ художественного текста')],
    ['math',      'Летняя школа по математике',         'Летняя школа',   '#3f9bff', 1990, $basicProgram('Алгебра: повторение', 'Геометрия: повторение')],
    ['rus',       'Летняя школа по русскому языку',     'Летняя школа',   '#7c4dff', 1990, $basicProgram('Орфография: интенсив', 'Пунктуация: интенсив')],
    ['admission', 'Профориентация и выбор вуза',        'Профориентация', '#ff7a00', 0,    $basicProgram('Кем стать', 'Как выбрать вуз')],
  ];

  $ci = $pdo->prepare("INSERT INTO courses (subject_id, title, type, color, cover, price, position) VALUES (?,?,?,?,?,?,?)");
  $mi = $pdo->prepare("INSERT INTO months (course_id, name, position) VALUES (?,?,?)");
  $bi = $pdo->prepare("INSERT INTO blocks (course_id, month_id, n, title, position) VALUES (?,?,?,?,?)");
  $li = $pdo->prepare("INSERT INTO lessons (block_id, type, title, meta, kinescope_id, air, duration, locked_hint, position) VALUES (?,?,?,?,?,?,?,?,?)");
  $mati = $pdo->prepare("INSERT INTO materials (lesson_id, name, size, url, kind, position) VALUES (?,?,?,?,?,?)");
  $ti = $pdo->prepare("INSERT INTO timecodes (lesson_id, t, label, position) VALUES (?,?,?,?)");

  foreach ($courses as $cpos => $c) {
    [$slug, $title, $type, $color, $price, $program] = $c;
    $ci->execute([$sid[$slug], $title, $type, $color, null, $price ?: null, $cpos]);
    $courseId = (int)$pdo->lastInsertId();

    foreach ($program as $mpos => $month) {
      [$mname, $mblocks] = $month;
      $mi->execute([$courseId, $mname, $mpos]);
      $monthId = (int)$pdo->lastInsertId();

      foreach ($mblocks as $bpos => $block) {
        [$bn, $btitle, $lessons] = $block;
        $bi->execute([$courseId, $monthId, $bn, $btitle, $bpos]);
        $blockId = (int)$pdo->lastInsertId();

        foreach ($lessons as $lpos => $L) {
          // L = [type, title, meta, duration?(theory) | null, air?(practice), hint?(practice)]
          $ltype = $L[0]; $ltitle = $L[1]; $lmeta = $L[2] ?? null;
          $ldur  = $ltype === 'theory'   ? ($L[3] ?? null) : null;
          $lair  = $ltype === 'practice' ? ($L[4] ?? null) : null;
          $lhint = $ltype === 'practice' ? ($L[5] ?? null) : null;
          $li->execute([$blockId, $ltype, $ltitle, $lmeta, null, $lair, $ldur, $lhint, $lpos]);
          $lessonId = (int)$pdo->lastInsertId();

          // Демо-материалы и тайм-коды — первому теоретическому уроку каждого блока.
          if ($ltype === 'theory' && $lpos === 0) {
            foreach ($demoMaterials as $k => $m) $mati->execute([$lessonId, $m[0], $m[1], $m[2] ?: null, $m[3], $k]);
            foreach ($demoTimecodes as $k => $tc) $ti->execute([$lessonId, $tc[0], $tc[1], $k]);
          }
        }
      }
    }
  }
}
