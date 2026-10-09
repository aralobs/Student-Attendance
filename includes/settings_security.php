<?php
/** Security helpers for administrator-managed integration settings. */
function settingsCsrfToken(): string {
    startSession();
    if (empty($_SESSION['settings_csrf'])) {
        $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['settings_csrf'];
}

function validSettingsCsrf($token): bool {
    return is_string($token) && hash_equals(settingsCsrfToken(), $token);
}

function requireSettingsPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'message' => 'Please use the settings page.']);
        exit;
    }
    if (!validSettingsCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please reload Settings and try again.']);
        exit;
    }
}

function isSecretSetting(string $name): bool {
    return in_array($name, ['unisms_api_key', 'mail_password', 'calendarific_api_key'], true);
}

function settingsEncryptionKey(bool $create): string {
    $encoded = getenv('SETTINGS_ENCRYPTION_KEY');
    if ($encoded === false || $encoded === '') {
        // PHP serves this file without output; keep it out of version control/backups of the DB.
        $path = __DIR__ . '/../config/settings_key.php';
        if (!$create && !is_file($path)) {
            throw new RuntimeException('Settings encryption key is unavailable.');
        }
        $file = fopen($path, $create ? 'c+b' : 'rb');
        if ($file === false) {
            throw new RuntimeException('Settings encryption key is unavailable.');
        }
        try {
            if (!flock($file, LOCK_EX)) {
                throw new RuntimeException('Cannot lock settings encryption key.');
            }
            $contents = stream_get_contents($file);
            if ($contents === '' && $create) {
                $encoded = base64_encode(random_bytes(32));
                $contents = "<?php exit; // settings-key:" . $encoded . "\n";
                if (fwrite($file, $contents) !== strlen($contents) || !fflush($file)) {
                    throw new RuntimeException('Cannot save settings encryption key.');
                }
                @chmod($path, 0600);
            } elseif (preg_match('/settings-key:([A-Za-z0-9+\/=]{44})/', $contents, $matches)) {
                $encoded = $matches[1];
            } else {
                throw new RuntimeException('Settings encryption key is invalid.');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Settings encryption key must encode 32 bytes.');
    }
    return $key;
}

function encryptSettingSecret(string $value): string {
    if ($value === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', settingsEncryptionKey(true), OPENSSL_RAW_DATA, $iv, $tag, 'settings:v1');
    if ($ciphertext === false) throw new RuntimeException('Cannot encrypt settings.');
    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decryptSettingSecret(string $value): string {
    // Existing plaintext settings keep working until the next successful settings save.
    if (strncmp($value, 'enc:v1:', 7) !== 0) return $value;
    $payload = base64_decode(substr($value, 7), true);
    if ($payload === false || strlen($payload) < 29) throw new RuntimeException('Invalid encrypted setting.');
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', settingsEncryptionKey(false), OPENSSL_RAW_DATA,
        substr($payload, 0, 12), substr($payload, 12, 16), 'settings:v1');
    if ($plain === false) throw new RuntimeException('Cannot decrypt settings.');
    return $plain;
}
