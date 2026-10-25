<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pii_security.php';
getDB()->exec(file_get_contents(__DIR__ . '/security_audit.sql'));
foreach (explode(';', file_get_contents(__DIR__ . '/security_audit_triggers.sql')) as $sql) {
    if (!trim($sql)) continue;
    preg_match('/CREATE TRIGGER (\w+)/', $sql, $match);
    $check = getDB()->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
    $check->execute([$match[1]]);
    if (!$check->fetchColumn()) getDB()->exec($sql);
}
piiEncryptionKey(true);
echo "Security event and encrypted change history tables ready. No existing school records changed.\n";
