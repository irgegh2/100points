<?php
// Скопировать в secrets.prod.php только на сервере и заполнить самостоятельно.
// Этот пример не содержит работающих доступов.
return [
  'host' => 'localhost',
  'name' => 'YOUR_DATABASE',
  'user' => 'YOUR_DATABASE_USER',
  'pass' => '',
  'migrate_key' => '',
  'routerai_key' => '',
  // SHA-256 отдельного случайного Bearer-ключа личного GPT, не сам ключ.
  'gpt_actions_key_hash' => '',
];
