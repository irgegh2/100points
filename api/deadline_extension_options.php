<?php
// GET /deadline_extension_options.php — персональные ДЗ, которые ещё можно продлить.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gamify.php';
$uid = require_user();
release_session();

ok(['homeworks' => g_eligible_homeworks_for_extension($uid)]);
