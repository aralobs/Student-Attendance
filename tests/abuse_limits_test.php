<?php
// Real InnoDB concurrency checks in a randomly named disposable database.
require_once __DIR__ . '/../includes/functions.php';
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
if (($argv[1] ?? '') === '--worker') {
    $name = $argv[2] ?? '';
    if (!preg_match('/^abuse_test_[a-f0-9]{12}$/D', $name)) exit(1);
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    echo consumeAbuseLimits($db, [abuseBucket('concurrent', 'same-device', 6, 60)], 1000);
    exit;
}
$name = 'abuse_test_' . bin2hex(random_bytes(6));
$admin = new PDO($dsn, DB_USER, DB_PASS, $options);
$created = false;
$workers = [];
$checks = 0;
function abuseCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
try {
    $admin->exec("CREATE DATABASE `$name`"); $created = true;
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    $schema = file_get_contents(__DIR__ . '/../migrations/security_abuse_controls.sql');
    $db->exec($schema); $db->exec($schema);
    $bucket = abuseBucket('boundary', 'synthetic-user', 2, 60);
    abuseCheck(consumeAbuseLimits($db, [$bucket], 1000) === 0, 'First attempt rejected');
    abuseCheck(consumeAbuseLimits($db, [$bucket], 1001) === 0, 'Quota undercount');
    abuseCheck(consumeAbuseLimits($db, [$bucket], 1002) === 58, 'Quota bypassed');
    abuseCheck(consumeAbuseLimits($db, [$bucket], 1059) === 1, 'Denied attempts extended window');
    abuseCheck(consumeAbuseLimits($db, [$bucket], 1060) === 0, 'Window did not reset');
    abuseCheck(consumeAbuseLimits($db, [abuseBucket('boundary', 'other-user', 2, 60)], 1002) === 0, 'Different identity blocked');
    abuseCheck(consumeAbuseLimits($db, [abuseBucket('other-scope', 'synthetic-user', 2, 60)], 1002) === 0, 'Different action blocked');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_X_FORWARDED_FOR'] = 'attacker-controlled';
    abuseCheck(abuseClientIP() === '127.0.0.1', 'Spoofed forwarding header trusted');
    foreach (range(1, 10) as $i) abuseCheck(consumeAbuseLimits($db, loginAbuseBuckets('SameAccount'), 2000) === 0, 'Normal login attempt rejected');
    abuseCheck(consumeAbuseLimits($db, loginAbuseBuckets('sameaccount'), 2000) === 900, 'Username casing bypassed throttle');
    $_SESSION = ['session_token' => 'synthetic-device-session'];
    $scanner = ['id' => 7, 'role' => 'scanner_operator'];
    foreach (range(1, 20) as $i) {
        abuseCheck(consumeAbuseLimits($db, scannerAbuseBuckets($scanner), 3000) === 0, 'Kiosk burst rejected');
        abuseCheck(consumeAbuseLimits($db, scannerProbeBuckets(7, 1), 3000) === 0, 'Probe limit applied early');
    }
    abuseCheck(consumeAbuseLimits($db, scannerProbeBuckets(7, 1), 3000) === 60, 'Probe quota missing');
    abuseCheck(consumeAbuseLimits($db, scannerAbuseBuckets($scanner), 3001) === 59, 'Probe lockout skipped before lookup');
    abuseCheck(consumeAbuseLimits($db, scannerAbuseBuckets(['id' => 8]), 3001) === 0, 'Other device blocked by one kiosk');
    // Clearing/changing the session cannot reset the user-level scan quota.
    for ($i = 0; $i < 120; $i++) abuseCheck(consumeAbuseLimits($db, scannerAbuseBuckets(['id' => 9]), 4000) === 0, 'Legitimate scan burst rejected');
    $_SESSION['session_token'] = 'new-cookie';
    abuseCheck(consumeAbuseLimits($db, scannerAbuseBuckets(['id' => 9]), 4000) === 60, 'Cookie reset bypassed user limit');
    for ($i = 0; $i < 24; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $name], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        $workers[] = [$process, $pipes];
    }
    $allowed = 0;
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        abuseCheck($exit === 0 && $err === '' && in_array($out, ['0', '60'], true), 'Concurrent counter failure: ' . $err . $out);
        if ($out === '0') $allowed++;
    }
    $workers = [];
    abuseCheck($allowed === 6, 'Parallel requests exceeded the quota');
    $tokens = [];
    for ($i = 0; $i < 100; $i++) {
        $token = generateQRToken('123456789012');
        abuseCheck((bool)preg_match('/^STU-123456789012-[A-F0-9]{64}$/D', $token), 'Token must contain the LRN and 256-bit random suffix');
        abuseCheck(strlen($token) <= 100, 'Token exceeds scanner/database bound');
        $tokens[$token] = true;
    }
    abuseCheck(count($tokens) === 100, 'Duplicate QR tokens');
    abuseCheck(passwordPolicyError('short') !== null, 'Short password accepted');
    abuseCheck(passwordPolicyError('good passphrase') === null, 'Valid password rejected');
    abuseCheck(passwordPolicyError(str_repeat('a', 7)) !== null, 'Seven-character password accepted');
    abuseCheck(passwordPolicyError(str_repeat('a', 8)) === null, 'Eight-character password rejected');
    abuseCheck(passwordPolicyError(str_repeat('a', 16)) === null, 'Sixteen-character password rejected');
    abuseCheck(passwordPolicyError(str_repeat('a', 17)) !== null, 'Seventeen-character password accepted');
    abuseCheck(passwordPolicyError(str_repeat('a', 73)) !== null, 'Bcrypt truncation allowed');
    abuseCheck(passwordPolicyError(['invalid']) !== null, 'Array password accepted');
    abuseCheck(passwordPolicyError(str_repeat('a', 15) . "\0") !== null, 'NUL password accepted');
    abuseCheck(passwordPolicyError(str_repeat('界', 15)) === null, 'Unicode passphrase rejected');
    abuseCheck(passwordPolicyError(str_repeat('界', 16)) === null, 'Sixteen Unicode characters rejected');
    abuseCheck(passwordPolicyError(str_repeat('界', 17)) !== null, 'Seventeen Unicode characters accepted');
    echo "Abuse-control checks passed ($checks checks, including 24 concurrent workers; disposable DB).\n";
} finally {
    foreach ($workers as [$process, $pipes]) {
        if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    }
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if ($created) $admin->exec("DROP DATABASE `$name`");
}
