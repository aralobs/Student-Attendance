<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$db->exec(file_get_contents(__DIR__ . '/security_abuse_controls.sql'));
// Run periodically (e.g. daily) to bound expired storage; limits remain in force.
$db->prepare('DELETE FROM security_rate_limits WHERE expires_at < ?')->execute([time() - 86400]);
echo "Security abuse-control table ready; expired buckets cleaned.\n";
