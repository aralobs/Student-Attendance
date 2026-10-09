<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/UniSms.php';
requireAdmin();

header('Content-Type: application/json');
requireSettingsPost();

$number = is_string($_POST['number'] ?? null) ? trim($_POST['number']) : '';
if (empty($number)) {
    echo json_encode(['success' => false, 'message' => 'No number provided.']);
    exit;
}

try {
    $apiKey   = getSetting('unisms_api_key');
    $senderId = getSetting('unisms_sender_id') ?? 'UnisoftSMS';

    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'message' => 'No API key configured in settings.']);
        exit;
    }

    $phone = formatPhone($number);
    if (!preg_match('/^\+639\d{9}$/', $phone)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid Philippine mobile number.']);
        exit;
    }

    $client            = new UniSms($apiKey);
    $client->recipient = $phone;
    $client->content = 'Hello! This is a confirmation that SMS notifications are active for SPCCS Attendance System. Thank you.';
    $client->sender_id = $senderId;

    $response = $client->send();
    $decoded  = json_decode($response, true);

    if (isset($decoded['message']['status']) && $decoded['message']['status'] === 'sent') {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'SMS could not be sent. Check the saved key, sender ID and SMS balance.']);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'SMS test failed. Check the saved settings and try again.']);
}
?>
