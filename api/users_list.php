<?php
// GET /users_list.php — список учеников (только для админа).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session(); // read-only — снимаем лок сессии сразу

$rows = db()->query("SELECT id, name, email, phone, role, created_at FROM users ORDER BY id DESC")->fetchAll();
ok(['users' => $rows]);
