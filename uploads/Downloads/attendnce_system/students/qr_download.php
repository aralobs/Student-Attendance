<?php
/**
 * Download QR code as PNG file
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/qr_helper.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Student not found');
}

$filename = 'QR_' . $student['lrn'] . '_' . $student['last_name'] . '.png';
$savePath = sys_get_temp_dir() . '/' . $filename;

$generated = generateQRCode($student['qr_token'], $savePath, 400);

if ($generated && file_exists($savePath)) {
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($savePath));
    readfile($savePath);
    unlink($savePath); // clean up temp file
    exit;
}

// Fallback: serve SVG
$svg = generateQRCodeSVG($student['qr_token'], 400);
header('Content-Type: image/svg+xml');
header('Content-Disposition: attachment; filename="QR_' . $student['lrn'] . '.svg"');
echo $svg;
exit;