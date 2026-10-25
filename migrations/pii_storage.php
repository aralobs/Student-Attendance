<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pii_security.php';
getDB()->exec(file_get_contents(__DIR__ . '/pii_storage.sql'));
piiEncryptionKey(true);
echo "Encrypted SF2 remark storage and key ready. No existing student records changed.\n";
