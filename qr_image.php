<?php
/**
 * QR Image Server
 * Usage: <img src="qr_image.php?id=123"> (authorized staff only)
 * Serves QR code as image directly — no file storage needed.
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/qr_access.php';
requireQRManagement();
require_once 'includes/qr_helper.php';

// Validate the student ID; attendance tokens must not appear in URLs.
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$size  = min(400, max(100, (int)($_GET['size'] ?? 250)));

if ($id === false || $id === null) {
    http_response_code(400);
    exit('Invalid student ID');
}

// Resolve the token on the server after authenticating the caller.
$db   = getDB();
$stmt = $db->prepare("SELECT section_id, qr_token FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    exit('Student not found');
}
$student=requireQRStudentAccess((int)$id,$student);

// Serve the QR image directly
serveQRCode($student['qr_token'], $size);
