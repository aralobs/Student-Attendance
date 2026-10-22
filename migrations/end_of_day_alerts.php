<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$db = getDB();
foreach (explode(';', file_get_contents(__DIR__ . '/end_of_day_alerts.sql')) as $sql) {
    if (trim($sql) !== '') $db->exec($sql);
}
echo "End-of-day alert migration complete.\n";
