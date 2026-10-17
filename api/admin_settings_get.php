<?php
// GET /admin_settings_get.php — настройки для админки.
require __DIR__ . '/bootstrap.php';
require_admin();
release_session();
ok([
  'maintenance' => is_maintenance(),
  'maintenance_text' => setting_get('maintenance_text', 'Идут технические работы. Скоро вернёмся!'),
  'ai_model' => setting_get('ai_model', 'openai/gpt-5.4-mini'),
]);
