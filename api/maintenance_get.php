<?php
// GET /maintenance_get.php — публичный флаг тех.работ (для фронта, без авторизации).
require __DIR__ . '/bootstrap.php';
release_session();
ok(['on' => is_maintenance(), 'text' => setting_get('maintenance_text', 'Идут технические работы. Скоро вернёмся!')]);
