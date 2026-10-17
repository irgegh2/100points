<?php
// POST /admin_settings_set.php  { maintenance?: bool, maintenance_text?: string, ai_model?: string }
require __DIR__ . '/bootstrap.php';
require_admin();
$in = body();
if (array_key_exists('maintenance', $in)) setting_set('maintenance', !empty($in['maintenance']) ? '1' : '0');
if (array_key_exists('maintenance_text', $in)) {
  $t = trim((string)$in['maintenance_text']);
  if ($t !== '') setting_set('maintenance_text', mb_substr($t, 0, 400));
}
if (array_key_exists('ai_model', $in)) {
  $model = trim((string)$in['ai_model']);
  if (!preg_match('/^[A-Za-z0-9_.~:-]+\/[A-Za-z0-9_.~:-]+$/', $model) || strlen($model) > 160) {
    fail('Модель должна быть в формате provider/model', 400);
  }
  setting_set('ai_model', $model);
}
// читаем свежие значения напрямую (кеш setting_get мог остаться старым в этом процессе)
$m = db()->prepare("SELECT v FROM app_settings WHERE k = 'maintenance'"); $m->execute(); $mv = $m->fetchColumn();
$x = db()->prepare("SELECT v FROM app_settings WHERE k = 'maintenance_text'"); $x->execute(); $xv = $x->fetchColumn();
$a = db()->prepare("SELECT v FROM app_settings WHERE k = 'ai_model'"); $a->execute(); $av = $a->fetchColumn();
ok([
  'maintenance' => $mv === '1',
  'maintenance_text' => $xv ?: 'Идут технические работы. Скоро вернёмся!',
  'ai_model' => $av ?: 'openai/gpt-5.4-mini',
]);
