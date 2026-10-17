# 100points

Код учебной платформы [100-points.ru](https://100-points.ru): React/Vite, PHP API и MySQL. Локальная разработка API поддерживает SQLite.

## Запуск

```sh
npm ci
npm run dev
```

Для локального API без production-конфига:

```sh
POINTS_FORCE_SQLITE=1 php api/migrate.php
POINTS_FORCE_SQLITE=1 php -S 127.0.0.1:8000 -t api
```

Настройки прокси находятся в `vite.config.js`. Production-развёртывание описано в [DEPLOY.md](DEPLOY.md).

## Работа из ChatGPT

Подготовлены закрытые операции чтения учебного каталога и создания домашних с решениями. Описание подключения: [integrations/chatgpt/README.md](integrations/chatgpt/README.md). Схемы Actions сайта и GitHub лежат рядом.

GitHub хранит исходники и историю изменений. Актуальные уроки, домашки и задания хранятся в базе сайта: для них нужно вызывать API, а не считать содержимое репозитория снимком базы.

## Что не входит в репозиторий

Production-пароли, API-ключи, FTP-доступ, базы и дампы, ученические вложения, временные выгрузки и локальные отчёты. Пример конфигурации без секретов — `api/secrets.example.php`.
