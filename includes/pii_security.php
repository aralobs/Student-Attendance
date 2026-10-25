<?php
/** Authenticated encryption for sensitive server-side report remarks. */
function piiEncryptionKey(bool $create): string {
    $encoded = getenv('PII_ENCRYPTION_KEY');
    if ($encoded === false || $encoded === '') {
        $path = getenv('PII_KEY_FILE') ?: __DIR__ . '/../config/pii_key.php';
        if (!$create && !is_file($path)) throw new RuntimeException('PII encryption key is unavailable.');
        $file = @fopen($path, $create ? 'c+b' : 'rb');
        if (!$file) throw new RuntimeException('PII encryption key is unavailable.');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('Cannot lock PII key.');
            $contents = stream_get_contents($file);
            if ($contents === '' && $create) {
                $encoded = base64_encode(random_bytes(32));
                $contents = "<?php exit; // pii-key:" . $encoded . "\n";
                if (fwrite($file, $contents) !== strlen($contents) || !fflush($file)) throw new RuntimeException('Cannot save PII key.');
                @chmod($path, 0600);
            } elseif (preg_match('/pii-key:([A-Za-z0-9+\/=]{44})/', $contents, $matches)) {
                $encoded = $matches[1];
            } else throw new RuntimeException('PII encryption key is invalid.');
        } finally {
            flock($file, LOCK_UN); fclose($file);
        }
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('PII encryption key is invalid.');
    return $key;
}

function encryptPII(string $value, string $context): string {
    $iv = random_bytes(12); $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', piiEncryptionKey(true), OPENSSL_RAW_DATA, $iv, $tag, 'pii:v1:' . $context);
    if ($ciphertext === false) throw new RuntimeException('PII encryption failed.');
    return 'pii:v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decryptPII(string $value, string $context): string {
    if (!str_starts_with($value, 'pii:v1:')) throw new RuntimeException('Invalid encrypted PII.');
    $payload = base64_decode(substr($value, 7), true);
    if ($payload === false || strlen($payload) < 28) throw new RuntimeException('Invalid encrypted PII.');
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', piiEncryptionKey(false), OPENSSL_RAW_DATA,
        substr($payload, 0, 12), substr($payload, 12, 16), 'pii:v1:' . $context);
    if ($plain === false) throw new RuntimeException('PII decryption failed.');
    return $plain;
}
