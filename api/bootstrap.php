<?php
// Единая точка входа для всех эндпоинтов API.
// Подключает конфиг и хелперы, ставит обработчики ошибок, открывает CORS и сессию.
// В каждом эндпоинте достаточно: require __DIR__ . '/bootstrap.php';

// Безопасный дефолт ДО любых require: даже если config/helpers упадут с ошибкой
// загрузки, наружу ничего не утечёт (на проде). Локально (cli-server) — показываем.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', in_array(php_sapi_name(), ['cli', 'cli-server'], true) ? '1' : '0');

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';

install_error_handlers();
cors();          // на OPTIONS-preflight здесь же выходит 204
start_session();
