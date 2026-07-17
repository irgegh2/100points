<?php
// POST /admin_logout.php — завершает админ-сессию.
require __DIR__ . '/bootstrap.php';

unset($_SESSION['admin_id']);
ok();
