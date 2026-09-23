<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail_helper.php';
requireAdmin();

header('Content-Type: application/json');

$email = trim($_POST['email'] ?? '');
if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'No email address provided.']);
    exit;
}

$html   = buildEmailTemplate(
    'Test Email',
    '<p>This is a test email from the SPCCS Attendance System.</p>
     <p>If you received this, email notifications are working correctly! ✅</p>'
);

$result = sendEmail($email, 'Test Recipient', 'Test Email — SPCCS Attendance System', $html);
echo json_encode($result);