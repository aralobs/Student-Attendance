<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail_helper.php';
requireAdmin();

header('Content-Type: application/json');
requireSettingsPost();

$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

$html   = buildEmailTemplate(
    'Test Email',
    '<p>This is a test email from the SPCCS Attendance System.</p>
     <p>If you received this, email notifications are working correctly! ✅</p>'
);

try {
    $result = sendEmail($email, 'Test Recipient', 'Test Email — SPCCS Attendance System', $html);
    echo json_encode(['success' => $result['success'], 'message' => $result['success']
        ? 'Email sent successfully.' : 'Email could not be sent. Check the saved Gmail address and App Password.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Email test failed. Check the saved settings and try again.']);
}
