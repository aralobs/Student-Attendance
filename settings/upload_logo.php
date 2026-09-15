<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

header('Content-Type: application/json');

if (empty($_FILES['logo']['name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
$allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Use PNG or JPG.']);
    exit;
}

if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB.']);
    exit;
}

$dest = BASE_PATH . 'assets/img/school_logo.' . $ext;

// If not PNG, save with extension but also copy as .png for consistent reference
if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
    exit;
}

// If uploaded as jpg/jpeg, also save a copy as .png using GD
if (in_array($ext, ['jpg','jpeg']) && extension_loaded('gd')) {
    $src = imagecreatefromjpeg($dest);
    imagepng($src, BASE_PATH . 'assets/img/school_logo.png');
}

echo json_encode([
    'success' => true,
    'url'     => BASE_URL . 'assets/img/school_logo.' . $ext,
]);
exit;