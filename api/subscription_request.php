<?php
// POST /subscription_request.php — демонстрационная заглушка оплаты.
// Никаких платёжных операций и заявок не создаёт; доступ выдаёт только администратор.
require __DIR__ . '/bootstrap.php';
require_user();
ok(['demo' => true, 'message' => 'Онлайн-оплата временно недоступна']);
