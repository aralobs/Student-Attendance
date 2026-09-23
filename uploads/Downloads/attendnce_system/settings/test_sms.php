<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/UniSms.php';
requireAdmin();

header('Content-Type: application/json');

$number = trim($_POST['number'] ?? '');
if (empty($number)) {
    echo json_encode(['success' => false, 'message' => 'No number provided.']);
    exit;
}

$apiKey   = getSetting('unisms_api_key');
$senderId = getSetting('unisms_sender_id') ?? 'UnisoftSMS';

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'message' => 'No API key configured in settings.']);
    exit;
}

$phone = formatPhone($number);

try {
    $client            = new UniSms($apiKey);
    $client->recipient = $phone;
    $client->content = 'Hello! This is a confirmation that SMS notifications are active for SPCCS Attendance System. Thank you.';
    $client->sender_id = $senderId;

    $response = $client->send();
    $decoded  = json_decode($response, true);

    if (isset($decoded['message']['status']) && $decoded['message']['status'] === 'sent') {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $response]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>