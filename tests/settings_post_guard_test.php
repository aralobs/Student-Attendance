<?php
// Isolated request-guard checks; no application sessions, DB, or provider calls.
require_once __DIR__ . '/../includes/settings_security.php';
function startSession() {}
$_SESSION = ['settings_csrf' => str_repeat('a', 64)];
$case = $argv[1] ?? 'valid';
$_SERVER['REQUEST_METHOD'] = $case === 'get' ? 'GET' : 'POST';
$_POST = ['csrf_token' => $_SESSION['settings_csrf']];
if ($case === 'missing') $_POST = [];
if ($case === 'array') $_POST['csrf_token'] = [];
if ($case === 'wrong') $_POST['csrf_token'] = 'wrong';
$expected = $case === 'get' ? 405 : ($case === 'valid' ? 200 : 403);
http_response_code(200);
register_shutdown_function(function () use ($expected) {
    if (http_response_code() !== $expected) {
        fwrite(STDERR, 'Unexpected response status.' . PHP_EOL);
        exit(1);
    }
});
requireSettingsPost();
if ($case !== 'valid') throw new RuntimeException('Unauthorized request reached the action.');
echo 'Valid request accepted.';
