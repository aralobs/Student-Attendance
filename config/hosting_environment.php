<?php
/** Shared-hosting configuration, stored beside (outside) public_html. */
function loadHostingEnvironment(string $path): void {
    if (!is_file($path)) return;
    $values = require $path;
    if (!is_array($values)) throw new RuntimeException('Invalid private hosting configuration');
    $allowed = ['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'BASE_URL', 'DB_TLS_CA', 'TRUSTED_PROXY_IPS', 'PII_KEY_FILE',
        'PII_ENCRYPTION_KEY', 'SETTINGS_ENCRYPTION_KEY', 'STUDENT_PHOTO_DIR',
        'SESSION_IDLE_TIMEOUT', 'SESSION_ABSOLUTE_TIMEOUT',
        'SCANNER_SESSION_IDLE_TIMEOUT', 'SCANNER_SESSION_ABSOLUTE_TIMEOUT'];
    foreach ($values as $name => $value) {
        if (!in_array($name, $allowed, true) || !is_string($value) || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid private hosting configuration');
        }
    }
    foreach ($values as $name => $value) {
        // Explicit server/worker environment variables take precedence.
        if (getenv($name) === false && !putenv($name . '=' . $value)) {
            throw new RuntimeException('Cannot load private hosting configuration');
        }
    }
}
