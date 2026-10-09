<?php
// Run with: php tests/settings_security_test.php (no database or external messages).
require_once __DIR__ . '/../includes/settings_security.php';
$sessionDirectory = __DIR__ . '/.settings-session-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
session_save_path($sessionDirectory);
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
function startSession() {
    if (session_status() === PHP_SESSION_NONE) session_start();
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $operation, string $message): void {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}

$originalKey = getenv('SETTINGS_ENCRYPTION_KEY');
putenv('SETTINGS_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
try {
    $secret = 'sample-key-with-special-characters-<>&"';
    $encrypted = encryptSettingSecret($secret);
    check(strpos($encrypted, $secret) === false, 'Ciphertext exposed the secret.');
    check(decryptSettingSecret($encrypted) === $secret, 'Round-trip failed.');
    check(encryptSettingSecret($secret) !== $encrypted, 'Encryption reused its nonce.');
    check(decryptSettingSecret('legacy-key') === 'legacy-key', 'Legacy setting broke.');
    check(encryptSettingSecret('') === '', 'Empty setting changed.');
    $payload = base64_decode(substr($encrypted, 7));
    $payload[28] = chr(ord($payload[28]) ^ 1);
    rejects(fn() => decryptSettingSecret('enc:v1:' . base64_encode($payload)), 'Tampered ciphertext accepted.');
    rejects(fn() => decryptSettingSecret('enc:v1:invalid'), 'Invalid ciphertext accepted.');
    putenv('SETTINGS_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    rejects(fn() => decryptSettingSecret($encrypted), 'Wrong encryption key accepted.');
    putenv('SETTINGS_ENCRYPTION_KEY=invalid');
    rejects(fn() => encryptSettingSecret($secret), 'Invalid key accepted.');
    check(isSecretSetting('unisms_api_key') && isSecretSetting('mail_password'), 'Credential classification failed.');
    check(isSecretSetting('calendarific_api_key'), 'Calendarific key was not classified as a secret.');
    check(!isSecretSetting('school_name'), 'Ordinary setting classified as secret.');
    $token = settingsCsrfToken();
    check(strlen($token) === 64 && settingsCsrfToken() === $token, 'CSRF token is unstable.');
    check(validSettingsCsrf($token), 'Valid token rejected.');
    foreach ([null, '', [], 'incorrect'] as $badToken) {
        check(!validSettingsCsrf($badToken), 'Invalid token accepted.');
    }
    session_destroy();
    echo "Settings security checks passed.\n";
} finally {
    putenv($originalKey === false ? 'SETTINGS_ENCRYPTION_KEY' : 'SETTINGS_ENCRYPTION_KEY=' . $originalKey);
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach (glob($sessionDirectory . '/sess_*') as $sessionFile) unlink($sessionFile);
    rmdir($sessionDirectory);
    restore_error_handler();
}
