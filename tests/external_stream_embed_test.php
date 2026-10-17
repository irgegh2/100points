<?php
require_once __DIR__ . '/../api/helpers.php';

function assert_same($expected, $actual, string $label): void {
  if ($expected !== $actual) {
    fwrite(STDERR, $label . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
    exit(1);
  }
}

$token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
  . '.eyJ1cmlfdXVpZCI6IjMyMzA1NDJiLWVjZDQtNDlmZS05YmU1LWQ4N2E5ZWY1ZjAwMSJ9'
  . '.7Q8MbqcDT5lIDr4yBCmV9pN_t6Eh2gFFVDzlXXsR7l0';

assert_same(
  ['provider' => 'webrestream', 'embed_url' => 'https://webrestream.site/p/' . $token],
  external_stream_embed('https://webrestream.site/p/' . $token . '/'),
  'WebRestream player link should be normalized and embedded'
);
assert_same(null, external_stream_embed('https://webrestream.site/c/3230542b?channel_id=1'), 'Telegram redirect must not be embedded');
assert_same(null, external_stream_embed('https://webrestream.site/p/not-a-jwt'), 'Unsigned player path must be rejected');
assert_same(null, external_stream_embed('https://evil.example/p/' . $token), 'Unknown host must be rejected');

$eduContentId = '6378e3fc-9037-4e41-96ee-f66433d6ff4a';
assert_same(
  ['provider' => 'educontent', 'embed_url' => 'https://educontent.cloud/watch/' . $eduContentId . '?partner=14'],
  external_stream_embed('https://educontent.cloud/watch/' . $eduContentId . '?partner=14'),
  'EduContent watch link should be normalized and embedded'
);
assert_same(
  ['provider' => 'educontent', 'embed_url' => 'https://educontent.cloud/watch/' . $eduContentId],
  external_stream_embed('https://www.educontent.cloud/watch/' . strtoupper($eduContentId) . '/?partner=bad'),
  'EduContent link should allow www and discard an invalid partner id'
);
assert_same(null, external_stream_embed('https://educontent.cloud/watch/not-a-uuid?partner=14'), 'Invalid EduContent video id must be rejected');
assert_same(null, external_stream_embed('https://evil.educontent.cloud/watch/' . $eduContentId), 'EduContent subdomains must not be trusted');

echo "external_stream_embed: ok\n";
