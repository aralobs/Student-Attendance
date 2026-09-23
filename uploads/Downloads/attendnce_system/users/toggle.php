<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'User not found.');
} elseif ($user['id'] == currentUser()['id']) {
    setFlash('danger', 'You cannot deactivate your own account.');
} else {
    $newStatus = $user['is_active'] ? 0 : 1;
    $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
    setFlash('success', "User '{$user['full_name']}' " . ($newStatus ? 'activated' : 'deactivated') . '.');
}

header('Location: index.php');
exit;