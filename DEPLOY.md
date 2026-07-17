# Деплой на reg.ru (структура сервера)

Фронт (собранный) и бэкенд (PHP) живут на одном домене. Структура `public_html`:

```
public_html/
├── index.html          ← сборка фронта (vite build → dist/)
├── assets/             ← JS/CSS из сборки
├── img/                ← картинки
├── .htaccess           ← SPA-fallback (из public/.htaccess)
└── api/                ← бэкенд (папка api/ как есть)
    ├── bootstrap.php   ← единая точка входа (config+helpers+CORS+сессия+ошибки)
    ├── config.php      ← выбор БД: SQLite (локально) / MySQL (прод)
    ├── helpers.php     ← ok()/fail(), обработка ошибок
    ├── migrate.php     ← версионные миграции + сид
    ├── *.php           ← эндпоинты (login, me, users_* и т.д.)
    ├── secrets.prod.php← доступы к MySQL (НЕ в git, закрыт .htaccess)
    ├── .htaccess       ← защита secrets/*.sqlite/data
    └── data/           ← локальная SQLite (на проде не нужна)
```

Продовые дампы БД хранятся **вне web-root**: `~/backups/100-points.ru/`.
Это важно для REG.RU: nginx может отдавать статические `.sql.gz` раньше правил `.htaccess`.

## Как выкатывать

1. Локально собрать фронт:
   ```
   npm run build
   ```
   Результат — в `dist/` (включая `.htaccess` из `public/`).

2. Залить на сервер по FTP/файловому менеджеру reg.ru:
   - **содержимое** `dist/` → в корень `public_html/`;
   - папку `api/` → в `public_html/api/`;
   - `api/secrets.prod.php` — залить вручную (в git его нет), вписать реальные доступы к MySQL и свой `migrate_key`.

3. Применить миграции (создать таблицы) одним из способов:
   - по SSH (рекомендуется):  `php public_html/api/migrate.php`
   - либо из браузера:  `https://100-points.ru/api/migrate.php?key=ВАШ_migrate_key`
     (работает, только если `migrate_key` в `secrets.prod.php` — реальная строка ≥16 символов;
     пока там плейсхолдер — веб-запуск закрыт, это норма).

   При первом прогоне создаются таблицы и админ `admin_irg` со **случайным паролем**,
   который печатается в выводе ОДИН раз — запиши его и сразу смени в админке.
   (Тестовый ученик `test@100-points.ru / 1234` создаётся только локально, на проде — нет.)

## Прод vs локалка
- Прод определяется по домену `100-points.ru` + наличию `secrets.prod.php` (`is_prod()` в `config.php`).
- Локально (`php -S localhost:8000 -t api`) используется SQLite-файл `api/data/app.sqlite`.
- Ошибки: на проде наружу отдаётся только общий текст (детали — в лог), локально — полный текст.

## Поддомены (опционально, Этап 15)
`lk.100-points.ru` (кабинет) и `admin.100-points.ru` (админка) — отдельные папки/DNS,
тот же `api/`. Фронт уже умеет различать хосты (`src/auth.js`: `isLkHost`/`isAdminHost`).

## Бэкапы БД (api/backup.php)
Скрипт делает продовый дамп в `~/backups/100-points.ru/` за пределами `/www`
и хранит последние 14 копий. Локальные SQLite-бэкапы остаются в `api/data/backups/`.
- Ручной запуск по SSH: `php public_html/api/backup.php`
- Через cron-сервис панели reg.ru (раз в сутки) одним из вариантов:
  - команда: `php /home/<логин>/www/100-points.ru/api/backup.php`
  - либо URL: `wget -qO- "https://100-points.ru/api/backup.php?key=<migrate_key>"`
    (тот же `migrate_key` из `secrets.prod.php`).
Скачать копию можно по FTP из `/backups/100-points.ru/`; по HTTP эта папка недоступна в принципе.
