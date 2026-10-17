<?php
// GET /users_list.php — список учеников (только для админа).
require __DIR__ . '/bootstrap.php';
require_admin();
release_session(); // read-only — снимаем лок сессии сразу

$rows = db()->query(
  "SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at,
          COALESCE(v.status, 'missing') AS vk_status, v.vk_url
   FROM users u
   LEFT JOIN user_vk_contacts v ON v.user_id = u.id
   ORDER BY u.id DESC"
)->fetchAll();
ok(['users' => $rows]);
