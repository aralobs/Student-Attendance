<?php
require_once __DIR__ . '/../includes/sms_status.php';
$cases = [
    ['{"message":{"status":"pending"}}', 'pending'],
    ['{"status":"pending"}', 'pending'],
    ['{"message":{"status":"sent"}}', 'sent'],
    ['{"status":"sent"}', 'sent'],
    ['{"event":"message.sent"}', 'sent'],
    ['{"message":{"status":"failed"}}', 'failed'],
    ['{"status":"unexpected"}', 'failed'],
    ['{"message":"error"}', 'failed'],
    ['null', 'failed'], ['<html>error</html>', 'failed'], ['', 'failed'],
];
foreach ($cases as [$response, $expected]) {
    if (smsResponseStatus($response) !== $expected) throw new RuntimeException('Incorrect SMS response classification');
}
echo "SMS status checks passed (11 synthetic responses; no messages sent).\n";
