<?php
/**
 * QR Image Server
 * Usage: <img src="qr_image.php?token=STU-XXXXX">
 * Serves QR code as image directly — no file storage needed.
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/qr_helper.php';

// Validate token
$token = trim($_GET['token'] ?? '');
$size  = min(400, max(100, (int)($_GET['size'] ?? 250)));

if (empty($token)) {
    http_response_code(400);
    exit('Missing token');
}

// Optional: verify token exists in DB
// (comment out if you want to generate for any string)
$db   = getDB();
$stmt = $db->prepare("SELECT id FROM students WHERE qr_token = ? AND is_active = 1");
$stmt->execute([$token]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Token not found');
}

// Serve the QR image directly
serveQRCode($token, $size);