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
    // ДЗ привязано к уроку. level: 'normal' (обычное) | 'hard' (повышенной сложности).
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

    // Вопрос ДЗ. type: single|multi|text|textarea. options/correct — JSON.
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
$seed = !in_array('--no-seed', $argv ?? [], true) && !isset($_GET['no-seed']);
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
    ['Рамка для аватара', 'Набор рамок как в Discord', 'theme', 350, 1, null],
    ['Цвет ника', 'Подсветка имени в рейтинге и чате', 'theme', 250, 1, null],
    ['Заморозка стрика', 'Серия не сгорит за пропуск дня', 'privilege', 150, 0, null],
    ['Продление дедлайна +24ч', 'Лишний день на домашку', 'privilege', 200, 0, null],
    ['+1 жизнь', 'Дополнительная жизнь', 'privilege', 120, 0, null],
    ['Бустер ×2 кристалла', 'Двойные кристаллы на 1 день', 'privilege', 250, 0, null],
    ['VIP-статус навсегда', 'Все плюшки и значок VIP', 'privilege', 3000, 1, 'TOP'],
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
      [1, 'Проценты и доли', 'Простые задачи на проценты от числа, скидки, доли. Числовой ответ.', 'deepseek/deepseek-v4-flash'],
      [2, 'Линейные уравнения', 'Уравнения вида ax+b=c, простые текстовые задачи на уравнения.', 'deepseek/deepseek-v4-flash'],
      [3, 'Производные', 'Нахождение производных многочленов, простых функций.', 'deepseek/deepseek-chat-v3.1'],
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
    [1, 'Решите уравнение: 3x − 7 = 11.', 'x = 6', '3x = 18, x = 6.', 'deepseek/deepseek-v4-flash'],
    [1, 'Найдите 25% от числа 80.', '20', '80 · 0,25 = 20.', 'deepseek/deepseek-v4-flash'],
    [2, 'В треугольнике два угла равны 50° и 60°. Найдите третий угол.', '70°', 'Сумма углов 180°, 180 − 50 − 60 = 70°.', 'deepseek/deepseek-v4-flash'],
    [3, 'Найдите производную функции f(x) = x³ − 2x.', "f'(x) = 3x² − 2", 'Производная x³ = 3x², производная 2x = 2.', 'deepseek/deepseek-v4-flash'],
    [4, 'Решите неравенство: x² − 5x + 6 < 0.', '2 < x < 3', 'Корни 2 и 3, парабола ветвями вверх, между корнями отрицательна.', 'deepseek/deepseek-chat-v3.1'],
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
